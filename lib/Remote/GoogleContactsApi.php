<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Remote;

use OCA\TeamsLogic\Convert\GooglePersonConverter;
use OCA\TeamsLogic\Convert\VCards;
use OCA\TeamsLogic\Db\SyncItem;
use OCA\TeamsLogic\Sync\ChangeSet;
use OCA\TeamsLogic\Sync\RemoteApi;
use OCA\TeamsLogic\Sync\RemoteCollection;
use OCA\TeamsLogic\Sync\RemoteItem;

/**
 * Google People: the user's contacts (one address book), connections.list with syncToken.
 */
class GoogleContactsApi implements RemoteApi {
	private const BASE = 'https://people.googleapis.com/v1';

	private GooglePersonConverter $converter;

	public function __construct(
		private Http $http,
	) {
		$this->converter = new GooglePersonConverter();
	}

	public function kind(): string {
		return SyncItem::CONTACTS;
	}

	public function label(): string {
		return 'Google';
	}

	public function collections(): array {
		return [new RemoteCollection('people/me', 'Contacts')];
	}

	public function changes(RemoteCollection $collection, ?string $token): ChangeSet {
		$base = self::BASE . '/people/me/connections?pageSize=1000&requestSyncToken=true&personFields=' . GooglePersonConverter::READ_FIELDS;
		if ($token !== null) {
			$base .= '&syncToken=' . rawurlencode($token);
		}
		$items = [];
		$next = null;
		$pageToken = null;
		do {
			$response = $this->http->request('GET', $base . ($pageToken !== null ? '&pageToken=' . rawurlencode($pageToken) : ''));
			if ($response['status'] === 410) {
				throw new SyncTokenExpiredException('Google contacts sync token expired', 410);
			}
			$page = $response['json'] ?? [];
			foreach ((array)($page['connections'] ?? []) as $person) {
				$id = (string)$person['resourceName'];
				$deleted = !empty($person['metadata']['deleted']);
				$items[$id] = new RemoteItem($id, $deleted ? null : (string)($person['etag'] ?? ''), $deleted, $deleted ? null : $person);
			}
			$pageToken = $page['nextPageToken'] ?? null;
			$next = $page['nextSyncToken'] ?? $next;
		} while ($pageToken !== null);
		return new ChangeSet($items, $token === null, $next !== null ? (string)$next : null);
	}

	public function fetch(RemoteCollection $collection, string $id): ?RemoteItem {
		$response = $this->http->request('GET', self::BASE . '/' . $this->path($id) . '?personFields=' . GooglePersonConverter::READ_FIELDS);
		if ($response['status'] === 404 || $response['status'] === 410 || $response['json'] === null) {
			return null;
		}
		return new RemoteItem($id, (string)($response['json']['etag'] ?? ''), false, $response['json']);
	}

	public function toLocal(RemoteCollection $collection, RemoteItem $item, ?string $existing): string {
		$uid = null;
		if ($existing !== null) {
			$uid = VCards::text(VCards::parse($existing), 'UID');
		}
		$uid ??= 'teamslogic-google-' . sha1($item->id);
		return $this->converter->toVCard((array)$item->data, $uid)->serialize();
	}

	public function create(RemoteCollection $collection, string $local): RemoteItem {
		$card = VCards::parse($local);
		$person = $this->http->request('POST', self::BASE . '/people:createContact?personFields=metadata', $this->converter->toPerson($card))['json'] ?? [];
		$id = (string)($person['resourceName'] ?? throw new RemoteException('Google returned no contact id'));
		$etag = $this->uploadPhoto($id, $card) ?? (string)($person['etag'] ?? '');
		return new RemoteItem($id, $etag);
	}

	public function update(RemoteCollection $collection, string $id, ?string $etag, string $local): RemoteItem {
		$card = VCards::parse($local);
		$body = $this->converter->toPerson($card);
		$body['etag'] = (string)$etag;
		$response = $this->http->request('PATCH', self::BASE . '/' . $this->path($id) . ':updateContact?personFields=metadata&updatePersonFields=' . GooglePersonConverter::UPDATE_FIELDS, $body);
		if ($response['status'] === 404) {
			throw new PreconditionFailedException('The contact was deleted in Google', 404);
		}
		$newEtag = $this->uploadPhoto($id, $card) ?? (string)($response['json']['etag'] ?? '');
		return new RemoteItem($id, $newEtag);
	}

	public function delete(RemoteCollection $collection, string $id, ?string $etag): void {
		$this->http->request('DELETE', self::BASE . '/' . $this->path($id) . ':deleteContact');
	}

	/** Uploads an embedded photo; returns the contact's etag afterwards. */
	private function uploadPhoto(string $id, \Sabre\VObject\Component\VCard $card): ?string {
		$photo = $this->converter->photoFor($card);
		if ($photo === null) {
			return null;
		}
		$response = $this->http->request('PATCH', self::BASE . '/' . $this->path($id) . ':updateContactPhoto', [
			'photoBytes' => base64_encode($photo['data']),
			'personFields' => 'metadata',
		]);
		return isset($response['json']['person']['etag']) ? (string)$response['json']['person']['etag'] : null;
	}

	/** "people/c123" stays as it is in the path. */
	private function path(string $resourceName): string {
		return implode('/', array_map('rawurlencode', explode('/', $resourceName)));
	}
}
