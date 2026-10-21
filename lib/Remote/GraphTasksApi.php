<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Remote;

use OCA\TeamsLogic\Convert\GraphTaskConverter;
use OCA\TeamsLogic\Convert\ICal;
use OCA\TeamsLogic\Convert\Tasks;
use OCA\TeamsLogic\Db\SyncItem;
use OCA\TeamsLogic\Sync\ChangeSet;
use OCA\TeamsLogic\Sync\RemoteApi;
use OCA\TeamsLogic\Sync\RemoteCollection;
use OCA\TeamsLogic\Sync\RemoteItem;

/**
 * Microsoft To Do (Graph v1.0): task lists, and their tasks with delta.
 */
class GraphTasksApi implements RemoteApi {
	private const V1 = GraphCalendarApi::V1;

	private GraphTaskConverter $converter;

	public function __construct(
		private Http $http,
	) {
		$this->converter = new GraphTaskConverter();
	}

	public function kind(): string {
		return SyncItem::TASKS;
	}

	public function label(): string {
		return 'Outlook';
	}

	public function collections(): array {
		$out = [];
		$url = self::V1 . '/me/todo/lists?$top=100';
		while ($url !== null) {
			$page = $this->http->request('GET', $url)['json'] ?? [];
			foreach ((array)($page['value'] ?? []) as $list) {
				// Flagged emails is To Do's view of flagged mail: tasks can't be added there
				$out[] = new RemoteCollection((string)$list['id'], (string)($list['displayName'] ?? 'Tasks'), ($list['wellknownListName'] ?? '') === 'flaggedEmails');
			}
			$url = $page['@odata.nextLink'] ?? null;
		}
		return $out;
	}

	public function changes(RemoteCollection $collection, ?string $token): ChangeSet {
		$url = $token ?? self::V1 . '/me/todo/lists/' . rawurlencode($collection->id) . '/tasks/delta';
		$items = [];
		$deltaLink = null;
		while ($url !== null) {
			$response = $this->http->request('GET', $url, null, ['Prefer' => 'odata.maxpagesize=100']);
			if ($token !== null && ($response['status'] === 410 || $response['status'] === 404)) {
				throw new SyncTokenExpiredException('Graph tasks delta link expired', $response['status']);
			}
			$page = $response['json'] ?? [];
			foreach ((array)($page['value'] ?? []) as $task) {
				$id = (string)$task['id'];
				$items[$id] = isset($task['@removed'])
					? new RemoteItem($id, null, true)
					: new RemoteItem($id, isset($task['@odata.etag']) ? (string)$task['@odata.etag'] : null, false, isset($task['title']) ? $task : null);
			}
			$url = $page['@odata.nextLink'] ?? null;
			$deltaLink = $page['@odata.deltaLink'] ?? $deltaLink;
		}
		return new ChangeSet($items, $token === null, $deltaLink !== null ? (string)$deltaLink : null);
	}

	public function fetch(RemoteCollection $collection, string $id): ?RemoteItem {
		$response = $this->http->request('GET', $this->taskUrl($collection, $id));
		if ($response['status'] === 404 || $response['json'] === null) {
			return null;
		}
		return new RemoteItem($id, (string)($response['json']['@odata.etag'] ?? ''), false, $response['json']);
	}

	public function toLocal(RemoteCollection $collection, RemoteItem $item, ?string $existing): string {
		$task = is_array($item->data) ? $item->data : ($this->fetch($collection, $item->id)?->data ?? []);
		$uid = $existing !== null ? (Tasks::todoOf(ICal::parse($existing))?->UID ?? null) : null;
		return $this->converter->toCalendar((array)$task, $uid !== null ? (string)$uid : 'teamslogic-graph-task-' . sha1($item->id))->serialize();
	}

	public function create(RemoteCollection $collection, string $local): RemoteItem {
		$created = $this->http->request('POST', self::V1 . '/me/todo/lists/' . rawurlencode($collection->id) . '/tasks',
			$this->body(ICal::parse($local), true))['json'] ?? [];
		$id = (string)($created['id'] ?? throw new RemoteException('Graph returned no task id'));
		return new RemoteItem($id, (string)($created['@odata.etag'] ?? ''));
	}

	public function update(RemoteCollection $collection, string $id, ?string $etag, string $local): RemoteItem {
		$headers = $etag !== null && $etag !== '' ? ['If-Match' => $etag] : [];
		$response = $this->http->request('PATCH', $this->taskUrl($collection, $id), $this->body(ICal::parse($local), false), $headers);
		if ($response['status'] === 404) {
			throw new PreconditionFailedException('The task was deleted in To Do', 404);
		}
		return new RemoteItem($id, (string)($response['json']['@odata.etag'] ?? ''));
	}

	public function delete(RemoteCollection $collection, string $id, ?string $etag): void {
		$headers = $etag !== null && $etag !== '' ? ['If-Match' => $etag] : [];
		$this->http->request('DELETE', $this->taskUrl($collection, $id), null, $headers);
	}

	/** @return array<string, mixed> */
	private function body(\Sabre\VObject\Component\VCalendar $cal, bool $create): array {
		$body = $this->converter->fromCalendar($cal);
		if ($create) {
			// Nothing to clear on a new task
			return array_filter($body, static fn ($value) => $value !== null);
		}
		return $body;
	}

	private function taskUrl(RemoteCollection $collection, string $id): string {
		return self::V1 . '/me/todo/lists/' . rawurlencode($collection->id) . '/tasks/' . rawurlencode($id);
	}
}
