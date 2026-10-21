<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Remote;

use OCA\TeamsLogic\Convert\GoogleTaskConverter;
use OCA\TeamsLogic\Convert\ICal;
use OCA\TeamsLogic\Convert\Tasks;
use OCA\TeamsLogic\Db\SyncItem;
use OCA\TeamsLogic\Sync\ChangeSet;
use OCA\TeamsLogic\Sync\RemoteApi;
use OCA\TeamsLogic\Sync\RemoteCollection;
use OCA\TeamsLogic\Sync\RemoteItem;

/**
 * Google Tasks: task lists, and their tasks changed since the last round.
 *
 * Google Tasks has no sync token: the token is the time the last listing started (less a minute
 * for clock skew), passed as updatedMin; deleted tasks come back with deleted: true.
 */
class GoogleTasksApi implements RemoteApi {
	private const BASE = 'https://tasks.googleapis.com/tasks/v1';
	private const SKEW = 60;

	private GoogleTaskConverter $converter;

	public function __construct(
		private Http $http,
	) {
		$this->converter = new GoogleTaskConverter();
	}

	public function kind(): string {
		return SyncItem::TASKS;
	}

	public function label(): string {
		return 'Google';
	}

	public function collections(): array {
		$out = [];
		$pageToken = null;
		do {
			$page = $this->http->request('GET', self::BASE . '/users/@me/lists?maxResults=100' . ($pageToken !== null ? '&pageToken=' . rawurlencode($pageToken) : ''))['json'] ?? [];
			foreach ((array)($page['items'] ?? []) as $list) {
				$out[] = new RemoteCollection((string)$list['id'], (string)($list['title'] ?? 'Tasks'));
			}
			$pageToken = $page['nextPageToken'] ?? null;
		} while ($pageToken !== null);
		return $out;
	}

	public function changes(RemoteCollection $collection, ?string $token): ChangeSet {
		$started = time();
		$base = self::BASE . '/lists/' . rawurlencode($collection->id) . '/tasks?maxResults=100&showCompleted=true&showHidden=true&showDeleted=true';
		if ($token !== null) {
			$base .= '&updatedMin=' . rawurlencode($token);
		}
		$items = [];
		$pageToken = null;
		do {
			$page = $this->http->request('GET', $base . ($pageToken !== null ? '&pageToken=' . rawurlencode($pageToken) : ''))['json'] ?? [];
			foreach ((array)($page['items'] ?? []) as $task) {
				$id = (string)$task['id'];
				$deleted = !empty($task['deleted']);
				if ($deleted && $token === null) {
					continue;
				}
				$items[$id] = new RemoteItem($id, $deleted ? null : (string)($task['etag'] ?? ''), $deleted, $deleted ? null : $task);
			}
			$pageToken = $page['nextPageToken'] ?? null;
		} while ($pageToken !== null);
		return new ChangeSet($items, $token === null, gmdate('Y-m-d\TH:i:s.000\Z', $started - self::SKEW));
	}

	public function fetch(RemoteCollection $collection, string $id): ?RemoteItem {
		$response = $this->http->request('GET', $this->taskUrl($collection, $id));
		$task = $response['json'];
		if ($response['status'] === 404 || $task === null || !empty($task['deleted'])) {
			return null;
		}
		return new RemoteItem($id, (string)($task['etag'] ?? ''), false, $task);
	}

	public function toLocal(RemoteCollection $collection, RemoteItem $item, ?string $existing): string {
		$task = (array)$item->data;
		$uid = $existing !== null ? (Tasks::todoOf(ICal::parse($existing))?->UID ?? null) : null;
		$parent = isset($task['parent']) ? self::uidFor((string)$task['parent']) : null;
		return $this->converter->toCalendar($task, $uid !== null ? (string)$uid : self::uidFor($item->id), $parent)->serialize();
	}

	public function create(RemoteCollection $collection, string $local): RemoteItem {
		$body = array_filter($this->converter->fromCalendar(ICal::parse($local)), static fn ($value) => $value !== null);
		$created = $this->http->request('POST', self::BASE . '/lists/' . rawurlencode($collection->id) . '/tasks', $body)['json'] ?? [];
		$id = (string)($created['id'] ?? throw new RemoteException('Google returned no task id'));
		return new RemoteItem($id, (string)($created['etag'] ?? ''));
	}

	public function update(RemoteCollection $collection, string $id, ?string $etag, string $local): RemoteItem {
		$headers = $etag !== null && $etag !== '' ? ['If-Match' => $etag] : [];
		$response = $this->http->request('PATCH', $this->taskUrl($collection, $id), $this->converter->fromCalendar(ICal::parse($local)), $headers);
		if ($response['status'] === 404) {
			throw new PreconditionFailedException('The task was deleted in Google Tasks', 404);
		}
		return new RemoteItem($id, (string)($response['json']['etag'] ?? ''));
	}

	public function delete(RemoteCollection $collection, string $id, ?string $etag): void {
		$this->http->request('DELETE', $this->taskUrl($collection, $id));
	}

	/** The local UID of a task pulled from Google (so a subtask can name its parent). */
	public static function uidFor(string $taskId): string {
		return 'teamslogic-google-task-' . sha1($taskId);
	}

	private function taskUrl(RemoteCollection $collection, string $id): string {
		return self::BASE . '/lists/' . rawurlencode($collection->id) . '/tasks/' . rawurlencode($id);
	}
}
