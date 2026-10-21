<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Remote;

use OCA\TeamsLogic\Convert\GraphContactConverter;
use OCA\TeamsLogic\Convert\VCards;
use OCA\TeamsLogic\Db\SyncItem;
use OCA\TeamsLogic\Sync\ChangeSet;
use OCA\TeamsLogic\Sync\RemoteApi;
use OCA\TeamsLogic\Sync\RemoteCollection;
use OCA\TeamsLogic\Sync\RemoteItem;
use Sabre\VObject\Component\VCard;

/**
 * Microsoft Graph contacts: the default Contacts folder (`/me/contacts`) and the folders under
 * `/me/contactFolders`, each with contacts delta (v1.0).
 */
class GraphContactsApi implements RemoteApi {
	private const V1 = GraphCalendarApi::V1;
	/** The default Contacts folder, which Graph addresses as /me/contacts. */
	public const DEFAULT_FOLDER = 'default';

	private GraphContactConverter $converter;

	public function __construct(
		private Http $http,
	) {
		$this->converter = new GraphContactConverter();
	}

	public function kind(): string {
		return SyncItem::CONTACTS;
	}

	public function label(): string {
		return 'Outlook';
	}

	public function collections(): array {
		$out = [new RemoteCollection(self::DEFAULT_FOLDER, 'Contacts')];
		$url = self::V1 . '/me/contactFolders?$top=100';
		while ($url !== null) {
			$page = $this->http->request('GET', $url)['json'] ?? [];
			foreach ((array)($page['value'] ?? []) as $folder) {
				$out[] = new RemoteCollection((string)$folder['id'], (string)($folder['displayName'] ?? 'Contacts'));
			}
			$url = $page['@odata.nextLink'] ?? null;
		}
		return $out;
	}

	public function changes(RemoteCollection $collection, ?string $token): ChangeSet {
		$url = $token;
		if ($url === null) {
			$folder = $collection->id === self::DEFAULT_FOLDER ? $this->defaultFolderId() : $collection->id;
			if ($folder === null) {
				// An empty default folder has no contact to learn its id from: nothing to sync yet.
				return new ChangeSet([], true, null);
			}
			$url = self::V1 . '/me/contactFolders/' . rawurlencode($folder) . '/contacts/delta';
		}
		$items = [];
		$deltaLink = null;
		while ($url !== null) {
			$response = $this->http->request('GET', $url, null, ['Prefer' => 'odata.maxpagesize=100']);
			if ($response['status'] === 410 || ($response['status'] === 404 && $token !== null)) {
				throw new SyncTokenExpiredException('Graph contacts delta link expired', $response['status']);
			}
			$page = $response['json'] ?? [];
			foreach ((array)($page['value'] ?? []) as $contact) {
				$id = (string)$contact['id'];
				$items[$id] = isset($contact['@removed'])
					? new RemoteItem($id, null, true)
					: new RemoteItem($id, isset($contact['@odata.etag']) ? (string)$contact['@odata.etag'] : null, false, $contact);
			}
			$url = $page['@odata.nextLink'] ?? null;
			$deltaLink = $page['@odata.deltaLink'] ?? $deltaLink;
		}
		return new ChangeSet($items, $token === null, $deltaLink !== null ? (string)$deltaLink : null);
	}

	public function fetch(RemoteCollection $collection, string $id): ?RemoteItem {
		$response = $this->http->request('GET', self::V1 . '/me/contacts/' . rawurlencode($id));
		if ($response['status'] === 404 || $response['json'] === null) {
			return null;
		}
		return new RemoteItem($id, (string)($response['json']['@odata.etag'] ?? ''), false, $response['json']);
	}

	public function toLocal(RemoteCollection $collection, RemoteItem $item, ?string $existing): string {
		$contact = is_array($item->data) && isset($item->data['displayName']) ? $item->data : ($this->fetch($collection, $item->id)?->data ?? []);
		$uid = $existing !== null ? VCards::text(VCards::parse($existing), 'UID') : null;
		$uid ??= 'teamslogic-graph-' . sha1($item->id);
		$photo = $this->http->getRaw(self::V1 . '/me/contacts/' . rawurlencode($item->id) . '/photo/$value');
		$bytes = $photo['status'] === 200 && $photo['body'] !== '' ? $photo['body'] : null;
		return $this->converter->toVCard($contact, $uid, $bytes)->serialize();
	}

	public function create(RemoteCollection $collection, string $local): RemoteItem {
		$card = VCards::parse($local);
		$url = $collection->id === self::DEFAULT_FOLDER
			? self::V1 . '/me/contacts'
			: self::V1 . '/me/contactFolders/' . rawurlencode($collection->id) . '/contacts';
		$created = $this->http->request('POST', $url, $this->converter->toContact($card))['json'] ?? [];
		$id = (string)($created['id'] ?? throw new RemoteException('Graph returned no contact id'));
		$etag = $this->uploadPhoto($id, $card) ?? (string)($created['@odata.etag'] ?? '');
		return new RemoteItem($id, $etag);
	}

	public function update(RemoteCollection $collection, string $id, ?string $etag, string $local): RemoteItem {
		$card = VCards::parse($local);
		$headers = $etag !== null && $etag !== '' ? ['If-Match' => $etag] : [];
		$response = $this->http->request('PATCH', self::V1 . '/me/contacts/' . rawurlencode($id), $this->converter->toContact($card), $headers);
		if ($response['status'] === 404) {
			throw new PreconditionFailedException('The contact was deleted in Outlook', 404);
		}
		$newEtag = $this->uploadPhoto($id, $card) ?? (string)($response['json']['@odata.etag'] ?? '');
		return new RemoteItem($id, $newEtag);
	}

	public function delete(RemoteCollection $collection, string $id, ?string $etag): void {
		$headers = $etag !== null && $etag !== '' ? ['If-Match' => $etag] : [];
		$this->http->request('DELETE', self::V1 . '/me/contacts/' . rawurlencode($id), null, $headers);
	}

	private function defaultFolderId(): ?string {
		$page = $this->http->request('GET', self::V1 . '/me/contacts?$top=1&$select=parentFolderId')['json'] ?? [];
		$first = $page['value'][0] ?? null;
		return is_array($first) && !empty($first['parentFolderId']) ? (string)$first['parentFolderId'] : null;
	}

	/** Uploads an embedded photo; returns the contact's etag afterwards. */
	private function uploadPhoto(string $id, VCard $card): ?string {
		$photo = $this->converter->photoFor($card);
		if ($photo === null) {
			return null;
		}
		$this->http->putRaw(self::V1 . '/me/contacts/' . rawurlencode($id) . '/photo/$value', $photo['data'], $photo['mime']);
		$contact = $this->http->request('GET', self::V1 . '/me/contacts/' . rawurlencode($id) . '?$select=id')['json'] ?? [];
		return isset($contact['@odata.etag']) ? (string)$contact['@odata.etag'] : null;
	}
}
