<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CardDAV\CardDavBackend;
use OCA\TeamsLogic\Convert\ICal;
use OCA\TeamsLogic\Convert\UnsupportedException;
use OCA\TeamsLogic\Db\Connection;
use OCA\TeamsLogic\Db\ConnectionMapper;
use OCA\TeamsLogic\Db\SyncItem;
use OCA\TeamsLogic\Db\SyncItemMapper;
use OCA\TeamsLogic\OAuth\OAuthException;
use OCA\TeamsLogic\OAuth\OAuthService;
use OCA\TeamsLogic\Remote\PreconditionFailedException;
use OCA\TeamsLogic\Remote\RemoteException;
use OCA\TeamsLogic\Remote\SyncTokenExpiredException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One sync round for a connection: for each remote calendar / address book, make sure its local
 * copy exists ("Google · Work"), plan the round (SyncPlanner) and carry it out.
 *
 * Tokens move on only when their side was handled completely: a failed pull keeps the remote
 * token (the change comes again next round), a failed push keeps the local one.
 */
class SyncService {
	private const LOCK_TTL = 600;

	private ?IMemcache $locks = null;

	public function __construct(
		private ConnectionMapper $connections,
		private SyncItemMapper $items,
		private OAuthService $oauth,
		private RemoteApiFactory $apis,
		private CalDavBackend $calDav,
		private CardDavBackend $cardDav,
		private SyncPlanner $planner,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		ICacheFactory $cacheFactory,
	) {
		$cache = $cacheFactory->createLocking('teamslogic');
		$this->locks = $cache instanceof IMemcache ? $cache : null;
	}

	/**
	 * @return array{pulled: int, pushed: int, conflicts: int, errors: list<string>, skipped?: bool}
	 */
	public function sync(Connection $connection): array {
		$summary = ['pulled' => 0, 'pushed' => 0, 'conflicts' => 0, 'errors' => []];
		$lock = 'sync-' . $connection->getId();
		if ($this->locks !== null && !$this->locks->add($lock, 1, self::LOCK_TTL)) {
			return $summary + ['skipped' => true];
		}
		try {
			$this->run($connection, $summary);
		} finally {
			$this->locks?->remove($lock);
		}
		return $summary;
	}

	private function run(Connection $connection, array &$summary): void {
		try {
			$token = $this->oauth->accessToken($connection);
		} catch (OAuthException $e) {
			$connection->setLastError(($e->reconnect ? 'Sign in again: ' : '') . $e->getMessage());
			$connection->setLastSync($this->time->getTime());
			$this->connections->update($connection);
			$summary['errors'][] = (string)$connection->getLastError();
			return;
		}
		$apis = $this->apis->forConnection($connection, $token);

		$state = $connection->getState();
		foreach ($apis as $api) {
			$kind = $api->kind();
			$store = $this->store($kind);
			$kindState = (array)($state[$kind] ?? []);
			try {
				$collections = $api->collections();
			} catch (RemoteException $e) {
				$summary['errors'][] = "$kind: " . $e->getMessage();
				continue;
			}
			$seen = [];
			foreach ($collections as $collection) {
				$seen[$collection->id] = true;
				$cs = (array)($kindState[$collection->id] ?? []);
				try {
					$localId = $store->ensureCollection(
						$connection->getUserId(),
						isset($cs['localId']) ? (int)$cs['localId'] : null,
						self::collectionUri($connection, $kind, $collection->id),
						$api->label() . ' · ' . $collection->name,
						$collection->color,
					);
					if (($cs['localId'] ?? null) !== $localId) {
						// A new local copy (or the old one was deleted locally): start over.
						if (isset($cs['localId'])) {
							$this->items->deleteForCollection($connection->getId(), $kind, (int)$cs['localId']);
						}
						$cs = ['localId' => $localId];
					}
					$cs['name'] = $collection->name;
					$cs['readOnly'] = $collection->readOnly;
					$cs['meeting'] = $collection->meetingProvider;
					$cs = $this->syncCollection($connection, $api, $store, $collection, $cs, $summary);
				} catch (Throwable $e) {
					$this->logger->warning('TeamsLogic: sync of {name} failed: ' . $e->getMessage(), ['name' => $collection->name, 'exception' => $e]);
					$summary['errors'][] = $collection->name . ': ' . $e->getMessage();
				}
				$kindState[$collection->id] = $cs;
			}
			// Remote calendars / address books that are gone take their local copy with them.
			foreach ($kindState as $remoteId => $cs) {
				if (!isset($seen[$remoteId]) && isset($cs['localId'])) {
					try {
						$store->deleteCollection((int)$cs['localId']);
					} catch (Throwable $e) {
						$this->logger->info('TeamsLogic: local copy of a removed collection not deleted: ' . $e->getMessage());
					}
					$this->items->deleteForCollection($connection->getId(), $kind, (int)$cs['localId']);
					unset($kindState[$remoteId]);
				}
			}
			$state[$kind] = $kindState;
		}

		$connection->setState($state);
		$connection->setLastSync($this->time->getTime());
		$connection->setLastError($summary['errors'] === [] ? null : implode('; ', array_slice($summary['errors'], 0, 3)));
		$this->connections->update($connection);
	}

	/**
	 * @param array<string, mixed> $cs this collection's state: localId, localToken, remoteToken
	 * @return array<string, mixed> the state afterwards
	 */
	private function syncCollection(Connection $connection, RemoteApi $api, LocalStore $store, RemoteCollection $collection, array $cs, array &$summary): array {
		$localId = (int)$cs['localId'];
		$kind = $api->kind();
		$known = $this->items->findForCollection($connection->getId(), $kind, $localId);

		$local = $store->changes($localId, isset($cs['localToken']) ? (string)$cs['localToken'] : null);
		try {
			$remote = $api->changes($collection, isset($cs['remoteToken']) ? (string)$cs['remoteToken'] : null);
		} catch (SyncTokenExpiredException) {
			$this->logger->info('TeamsLogic: sync token for {name} expired; listing everything', ['name' => $collection->name]);
			$remote = $api->changes($collection, null);
		}

		$knownForPlanner = [];
		foreach ($known as $remoteId => $item) {
			$knownForPlanner[(string)$remoteId] = [
				'localUri' => $item->getLocalUri(),
				'remoteEtag' => $item->getRemoteEtag(),
				'localEtag' => $item->getLocalEtag(),
			];
		}
		$actions = $this->planner->plan($remote->forPlanner(), $remote->complete, $local['changed'], $knownForPlanner, $collection->readOnly);

		$pullFailed = false;
		$pushFailed = false;
		$ctx = new SyncContext($connection, $api, $store, $collection, $localId, $known, $remote);
		foreach ($actions as $action) {
			try {
				$this->apply($ctx, $action, $summary);
			} catch (UnsupportedException $e) {
				$this->logger->info('TeamsLogic: {uri} left out of the sync: ' . $e->getMessage(), ['uri' => $action->localUri ?? $action->remoteId]);
				if ($action->type === SyncAction::PUSH_UPDATE && isset($known[$action->remoteId])) {
					$current = $store->get($localId, (string)$action->localUri);
					$this->saveItem($ctx, (string)$action->remoteId, (string)$action->localUri, $known[$action->remoteId]->getRemoteEtag(), $current['etag'] ?? null);
				}
			} catch (Throwable $e) {
				$this->logger->warning('TeamsLogic: {type} of {item} in {name} failed: ' . $e->getMessage(), [
					'type' => $action->type, 'item' => $action->localUri ?? $action->remoteId, 'name' => $collection->name, 'exception' => $e,
				]);
				if ($action->isPush()) {
					$pushFailed = true;
				} else {
					$pullFailed = true;
				}
				if (count($summary['errors']) < 10) {
					$summary['errors'][] = $collection->name . ': ' . $e->getMessage();
				}
			}
		}

		if (!$pullFailed && $remote->token !== null) {
			$cs['remoteToken'] = $remote->token;
		}
		if (!$pushFailed) {
			$cs['localToken'] = $local['token'];
		}
		return $cs;
	}

	private function apply(SyncContext $ctx, SyncAction $action, array &$summary): void {
		$remoteId = (string)$action->remoteId;
		$knownItem = $ctx->known[$remoteId] ?? null;
		switch ($action->type) {
			case SyncAction::PULL_CREATE:
			case SyncAction::PULL_UPDATE:
				$item = $ctx->remote->items[$remoteId] ?? null;
				if ($item === null || $item->data === null) {
					$item = $ctx->api->fetch($ctx->collection, $remoteId);
				}
				$uri = $action->localUri ?? $knownItem?->getLocalUri() ?? $this->newUri($ctx, $remoteId);
				if ($item === null) {
					$ctx->store->delete($ctx->localId, $uri);
					$this->dropItem($ctx, $remoteId);
					break;
				}
				$existing = $ctx->store->get($ctx->localId, $uri);
				$data = $ctx->api->toLocal($ctx->collection, $item, $existing['data'] ?? null);
				$hash = ContentHash::of($data);
				$remoteEtag = $item->etag ?? $knownItem?->getRemoteEtag();
				if (!$ctx->collection->readOnly && $knownItem?->getRemoteHash() !== null && $knownItem->getRemoteHash() === $hash) {
					// Only the etag moved (Outlook does that just after a create): nothing to pull. Keep
					// the local etag of the last round, so a local change made meanwhile still counts.
					$this->saveItem($ctx, $remoteId, $uri, $remoteEtag, $knownItem->getLocalEtag(), $hash);
					if ($action->conflict) {
						// That local change isn't a conflict after all: send it.
						$this->apply($ctx, new SyncAction($existing === null ? SyncAction::PUSH_DELETE : SyncAction::PUSH_UPDATE, $remoteId, $uri), $summary);
					}
					break;
				}
				$this->writeLocal($ctx, $item->id, $uri, $data, $hash, $remoteEtag, $existing);
				$summary['pulled']++;
				if ($action->conflict) {
					$summary['conflicts']++;
					$this->logger->info('TeamsLogic: {uri} in {name}: ' . $action->note, ['uri' => $uri, 'name' => $ctx->collection->name]);
				}
				break;

			case SyncAction::PULL_DELETE:
				$ctx->store->delete($ctx->localId, (string)$action->localUri);
				$this->dropItem($ctx, $remoteId);
				$summary['pulled']++;
				if ($action->conflict) {
					$summary['conflicts']++;
					$this->logger->info('TeamsLogic: {uri} in {name}: ' . $action->note, ['uri' => $action->localUri, 'name' => $ctx->collection->name]);
				}
				break;

			case SyncAction::FORGET:
				$this->dropItem($ctx, $remoteId);
				break;

			case SyncAction::PUSH_CREATE:
				$local = $ctx->store->get($ctx->localId, (string)$action->localUri);
				if ($local === null) {
					break;
				}
				$created = $ctx->api->create($ctx->collection, $local['data']);
				[$hash, $etag, $text] = $this->remoteState($ctx, $created->id, $local['data']);
				$localEtag = $this->takeJoinLink($ctx, (string)$action->localUri, $local, $text);
				$this->saveItem($ctx, $created->id, (string)$action->localUri, $etag ?? $created->etag, $localEtag, $hash);
				$summary['pushed']++;
				break;

			case SyncAction::PUSH_UPDATE:
				$local = $ctx->store->get($ctx->localId, (string)$action->localUri);
				if ($local === null) {
					break;
				}
				try {
					$updated = $ctx->api->update($ctx->collection, $remoteId, $knownItem?->getRemoteEtag(), $local['data']);
					[$hash, $etag, $text] = $this->remoteState($ctx, $remoteId, $local['data']);
					$localEtag = $this->takeJoinLink($ctx, (string)$action->localUri, $local, $text);
					$this->saveItem($ctx, $remoteId, (string)$action->localUri, $etag ?? $updated->etag, $localEtag, $hash);
					$summary['pushed']++;
				} catch (PreconditionFailedException) {
					$this->remoteWins($ctx, $remoteId, (string)$action->localUri, $summary);
				}
				break;

			case SyncAction::PUSH_DELETE:
				try {
					$ctx->api->delete($ctx->collection, $remoteId, $knownItem?->getRemoteEtag());
					$this->dropItem($ctx, $remoteId);
					$summary['pushed']++;
				} catch (PreconditionFailedException) {
					$this->remoteWins($ctx, $remoteId, (string)$action->localUri, $summary);
				}
				break;
		}
	}

	/** 412 on a push: re-read the remote item and keep its version (logged). */
	private function remoteWins(SyncContext $ctx, string $remoteId, string $uri, array &$summary): void {
		$summary['conflicts']++;
		$this->logger->info('TeamsLogic: {uri} in {name} changed remotely while pushing: remote wins', ['uri' => $uri, 'name' => $ctx->collection->name]);
		$item = $ctx->api->fetch($ctx->collection, $remoteId);
		if ($item === null) {
			$ctx->store->delete($ctx->localId, $uri);
			$this->dropItem($ctx, $remoteId);
			return;
		}
		$existing = $ctx->store->get($ctx->localId, $uri);
		$data = $ctx->api->toLocal($ctx->collection, $item, $existing['data'] ?? null);
		$this->writeLocal($ctx, $item->id, $uri, $data, ContentHash::of($data), $item->etag, $existing);
	}

	/**
	 * Writes the local copy (unless it already says the same) and records both sides.
	 *
	 * @param array{data: string, etag: string}|null $existing
	 */
	private function writeLocal(SyncContext $ctx, string $remoteId, string $uri, string $data, string $hash, ?string $remoteEtag, ?array $existing): void {
		$etag = $existing !== null && ContentHash::of($existing['data']) === $hash
			? $existing['etag']
			: $ctx->store->put($ctx->localId, $uri, $data);
		$this->saveItem($ctx, $remoteId, $uri, $remoteEtag, $etag, $hash);
	}

	/**
	 * The remote item as it is just after a push: its content hash (as the next pull will see
	 * it) and etag. Nulls if it can't be read now; the next pull then compares by etag only.
	 *
	 * @return array{0: ?string, 1: ?string, 2: ?string} hash, etag, and the item as local text
	 */
	private function remoteState(SyncContext $ctx, string $remoteId, string $local): array {
		try {
			$item = $ctx->api->fetch($ctx->collection, $remoteId);
			if ($item === null) {
				return [null, null, null];
			}
			$text = $ctx->api->toLocal($ctx->collection, $item, $local);
			return [ContentHash::of($text), $item->etag, $text];
		} catch (Throwable $e) {
			$this->logger->info('TeamsLogic: could not re-read {id} after pushing: ' . $e->getMessage(), ['id' => $remoteId]);
			return [null, null, null];
		}
	}

	/**
	 * A push that asked the provider for an online meeting (Teams, Meet) comes back with its join
	 * link: the local copy takes the provider's version at once, so the link shows straight away.
	 * Returns the local etag afterwards.
	 *
	 * @param array{data: string, etag: string} $local
	 */
	private function takeJoinLink(SyncContext $ctx, string $uri, array $local, ?string $remoteText): string {
		if ($remoteText === null || !str_contains($remoteText, ICal::JOIN_URL . ':') || str_contains($local['data'], ICal::JOIN_URL . ':')) {
			return $local['etag'];
		}
		return $ctx->store->put($ctx->localId, $uri, $remoteText);
	}

	/** @param string|false|null $remoteHash false keeps the recorded hash */
	private function saveItem(SyncContext $ctx, string $remoteId, string $uri, ?string $remoteEtag, ?string $localEtag, string|false|null $remoteHash = false): void {
		$item = $ctx->known[$remoteId] ?? new SyncItem();
		$item->setConnectionId((int)$ctx->connection->getId());
		$item->setKind($ctx->api->kind());
		$item->setCollectionId($ctx->localId);
		$item->setRemoteId($remoteId);
		$item->setLocalUri($uri);
		$item->setRemoteEtag($remoteEtag !== null ? substr($remoteEtag, 0, 255) : null);
		$item->setLocalEtag($localEtag);
		if ($remoteHash !== false) {
			$item->setRemoteHash($remoteHash);
		}
		$item->setUpdatedAt($this->time->getTime());
		$ctx->known[$remoteId] = $item->getId() === null ? $this->items->insert($item) : $this->items->update($item);
	}

	private function dropItem(SyncContext $ctx, string $remoteId): void {
		if (isset($ctx->known[$remoteId])) {
			$this->items->delete($ctx->known[$remoteId]);
			unset($ctx->known[$remoteId]);
		}
	}

	private function newUri(SyncContext $ctx, string $remoteId): string {
		return 'tl-' . sha1($ctx->collection->id . "\0" . $remoteId) . $ctx->store->extension();
	}

	/**
	 * Removes a connection's synced copies (calendars and address books), or with $keepData only
	 * the links to them, which leaves them as ordinary local calendars and address books.
	 */
	public function forget(Connection $connection, bool $keepData): void {
		if (!$keepData) {
			foreach ($connection->getState() as $kind => $collections) {
				$store = $this->store((string)$kind);
				foreach ((array)$collections as $cs) {
					if (isset($cs['localId'])) {
						try {
							$store->deleteCollection((int)$cs['localId']);
						} catch (Throwable $e) {
							$this->logger->info('TeamsLogic: synced copy not deleted: ' . $e->getMessage());
						}
					}
				}
			}
		}
		$this->items->deleteForConnection((int)$connection->getId());
	}

	private function store(string $kind): LocalStore {
		return match ($kind) {
			SyncItem::CALENDAR => new LocalCalendarStore($this->calDav, 'VEVENT'),
			SyncItem::TASKS => new LocalCalendarStore($this->calDav, 'VTODO'),
			default => new LocalAddressBookStore($this->cardDav),
		};
	}

	public static function collectionUri(Connection $connection, string $kind, string $remoteId): string {
		return 'teamslogic-' . $connection->getId() . '-' . substr(sha1($kind . "\0" . $remoteId), 0, 16);
	}
}
