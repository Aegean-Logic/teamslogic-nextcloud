<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

/**
 * Decides one sync round for one collection (a calendar or an address book), from:
 *
 * - the remote changes since the remote sync token (or every remote item, on a full listing),
 * - the local changes since the local sync token (URI => current ETag, null when deleted),
 * - what the last round left: remote id => local URI, remote ETag and local ETag.
 *
 * Rules: remote changes are applied first. An item changed on both sides takes the remote
 * version (logged as a conflict). A local change whose ETag is the one we wrote last time is our
 * own write coming back and is skipped. A read-only collection never pushes: a local edit is
 * overwritten from the remote copy, a local new item stays local. The executor still meets 412
 * Precondition Failed on a push (the remote changed after the listing); it re-reads, remote wins.
 */
final class SyncPlanner {
	/**
	 * @param array<string, array{etag: ?string, deleted: bool}> $remote remote id => change
	 * @param bool $remoteComplete $remote lists every remote item: known items missing from it were deleted
	 * @param array<string, ?string> $local local URI => current ETag (null: deleted)
	 * @param array<string, array{localUri: string, remoteEtag: ?string, localEtag: ?string}> $known remote id => last round
	 * @return list<SyncAction>
	 */
	public function plan(array $remote, bool $remoteComplete, array $local, array $known, bool $readOnly): array {
		$byUri = [];
		foreach ($known as $remoteId => $item) {
			$byUri[$item['localUri']] = (string)$remoteId;
		}
		$pulls = [];
		$pushes = [];
		$doneUris = [];

		$localState = function (string $remoteId) use ($known, $local): string {
			$uri = $known[$remoteId]['localUri'];
			if (!array_key_exists($uri, $local)) {
				return 'unchanged';
			}
			if ($local[$uri] === null) {
				return 'deleted';
			}
			return $local[$uri] === $known[$remoteId]['localEtag'] ? 'unchanged' : 'modified';
		};

		foreach ($remote as $remoteId => $change) {
			$remoteId = (string)$remoteId;
			if (!isset($known[$remoteId])) {
				if (!$change['deleted']) {
					$pulls[] = new SyncAction(SyncAction::PULL_CREATE, $remoteId, null);
				}
				continue;
			}
			$uri = $known[$remoteId]['localUri'];
			$doneUris[$uri] = true;
			$state = $localState($remoteId);
			if ($change['deleted']) {
				$pulls[] = match ($state) {
					'deleted' => new SyncAction(SyncAction::FORGET, $remoteId, $uri),
					'modified' => new SyncAction(SyncAction::PULL_DELETE, $remoteId, $uri, true, 'deleted remotely, changed locally: remote wins'),
					default => new SyncAction(SyncAction::PULL_DELETE, $remoteId, $uri),
				};
				continue;
			}
			$remoteChanged = $change['etag'] === null || $change['etag'] !== $known[$remoteId]['remoteEtag'];
			if (!$remoteChanged) {
				// Unchanged remotely (a full listing, or our own push coming back).
				$doneUris[$uri] = false;
				continue;
			}
			$pulls[] = match ($state) {
				'deleted' => new SyncAction(SyncAction::PULL_CREATE, $remoteId, $uri, true, 'deleted locally, changed remotely: remote wins'),
				'modified' => new SyncAction(SyncAction::PULL_UPDATE, $remoteId, $uri, true, 'changed on both sides: remote wins'),
				default => new SyncAction(SyncAction::PULL_UPDATE, $remoteId, $uri),
			};
		}

		if ($remoteComplete) {
			foreach ($known as $remoteId => $item) {
				$remoteId = (string)$remoteId;
				if (isset($remote[$remoteId])) {
					continue;
				}
				$doneUris[$item['localUri']] = true;
				$pulls[] = match ($localState($remoteId)) {
					'deleted' => new SyncAction(SyncAction::FORGET, $remoteId, $item['localUri']),
					'modified' => new SyncAction(SyncAction::PULL_DELETE, $remoteId, $item['localUri'], true, 'deleted remotely, changed locally: remote wins'),
					default => new SyncAction(SyncAction::PULL_DELETE, $remoteId, $item['localUri']),
				};
			}
		}

		foreach ($local as $uri => $etag) {
			$uri = (string)$uri;
			if (!empty($doneUris[$uri])) {
				continue;
			}
			$remoteId = $byUri[$uri] ?? null;
			if ($remoteId === null) {
				if ($etag === null) {
					continue;
				}
				if ($readOnly) {
					continue;
				}
				$pushes[] = new SyncAction(SyncAction::PUSH_CREATE, null, $uri);
				continue;
			}
			if ($etag !== null && $etag === $known[$remoteId]['localEtag']) {
				continue;
			}
			if ($readOnly) {
				$pulls[] = $etag === null
					? new SyncAction(SyncAction::PULL_CREATE, $remoteId, $uri, false, 'read-only: restored')
					: new SyncAction(SyncAction::PULL_UPDATE, $remoteId, $uri, false, 'read-only: local edit undone');
				continue;
			}
			$pushes[] = $etag === null
				? new SyncAction(SyncAction::PUSH_DELETE, $remoteId, $uri)
				: new SyncAction(SyncAction::PUSH_UPDATE, $remoteId, $uri);
		}

		return array_merge($pulls, $pushes);
	}
}
