<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Remote;

use DateTimeZone;
use OCA\TeamsLogic\Convert\GoogleEventConverter;
use OCA\TeamsLogic\Convert\ICal;
use OCA\TeamsLogic\Db\SyncItem;
use OCA\TeamsLogic\Sync\ChangeSet;
use OCA\TeamsLogic\Sync\RemoteApi;
use OCA\TeamsLogic\Sync\RemoteCollection;
use OCA\TeamsLogic\Sync\RemoteItem;
use Sabre\VObject\Component\VCalendar;

/**
 * Google Calendar v3: calendarList, and events.list with syncToken.
 *
 * Items are series: a change to one instance ("recurringEventId") is reported as a change to its
 * series with the instance attached, and merged into the local object.
 */
class GoogleCalendarApi implements RemoteApi {
	private const BASE = 'https://www.googleapis.com/calendar/v3';

	public function __construct(
		private Http $http,
	) {
	}

	public function kind(): string {
		return SyncItem::CALENDAR;
	}

	public function label(): string {
		return 'Google';
	}

	public function collections(): array {
		$out = [];
		$url = self::BASE . '/users/me/calendarList?maxResults=250&showHidden=true';
		while ($url !== null) {
			$page = $this->http->request('GET', $url)['json'] ?? [];
			foreach ((array)($page['items'] ?? []) as $c) {
				if (!empty($c['deleted'])) {
					continue;
				}
				$out[] = new RemoteCollection(
					(string)$c['id'],
					(string)($c['summaryOverride'] ?? (!empty($c['primary']) ? 'Calendar' : ($c['summary'] ?? $c['id']))),
					!in_array($c['accessRole'] ?? 'reader', ['owner', 'writer'], true),
					$c['backgroundColor'] ?? null,
					$c['timeZone'] ?? null,
				);
			}
			$url = isset($page['nextPageToken'])
				? self::BASE . '/users/me/calendarList?maxResults=250&showHidden=true&pageToken=' . rawurlencode((string)$page['nextPageToken'])
				: null;
		}
		return $out;
	}

	public function changes(RemoteCollection $collection, ?string $token): ChangeSet {
		$base = self::BASE . '/calendars/' . rawurlencode($collection->id) . '/events?maxResults=2500&showDeleted=true&singleEvents=false';
		if ($token !== null) {
			$base .= '&syncToken=' . rawurlencode($token);
		}
		$masters = [];
		$instances = [];
		$next = null;
		$pageToken = null;
		do {
			$url = $base . ($pageToken !== null ? '&pageToken=' . rawurlencode($pageToken) : '');
			$response = $this->http->request('GET', $url);
			if ($response['status'] === 410) {
				throw new SyncTokenExpiredException('Google sync token expired', 410);
			}
			$page = $response['json'] ?? [];
			foreach ((array)($page['items'] ?? []) as $event) {
				if (!empty($event['recurringEventId'])) {
					$instances[(string)$event['recurringEventId']][] = $event;
				} else {
					$masters[(string)$event['id']] = $event;
				}
			}
			$pageToken = $page['nextPageToken'] ?? null;
			$next = $page['nextSyncToken'] ?? $next;
		} while ($pageToken !== null);

		$items = [];
		foreach ($masters as $id => $master) {
			$deleted = ($master['status'] ?? '') === 'cancelled';
			if ($deleted && $token === null) {
				continue;
			}
			$items[$id] = new RemoteItem($id, $deleted ? null : (string)($master['etag'] ?? ''), $deleted,
				$deleted ? null : ['master' => $master, 'instances' => $instances[$id] ?? []]);
		}
		foreach ($instances as $id => $list) {
			if (!isset($items[$id]) && !isset($masters[$id])) {
				$items[$id] = new RemoteItem($id, null, false, ['master' => null, 'instances' => $list]);
			}
		}
		return new ChangeSet($items, $token === null, $next !== null ? (string)$next : null);
	}

	public function fetch(RemoteCollection $collection, string $id): ?RemoteItem {
		$response = $this->http->request('GET', $this->eventUrl($collection, $id));
		$event = $response['json'];
		if ($response['status'] === 404 || $response['status'] === 410 || $event === null || ($event['status'] ?? '') === 'cancelled') {
			return null;
		}
		return new RemoteItem($id, (string)($event['etag'] ?? ''), false, ['master' => $event, 'instances' => []]);
	}

	public function toLocal(RemoteCollection $collection, RemoteItem $item, ?string $existing): string {
		$data = (array)$item->data;
		$master = $data['master'] ?? null;
		$old = $existing !== null ? ICal::parse($existing) : null;
		if ($master === null && ($old === null || ICal::masterOf($old) === null)) {
			// An instance changed in a series we don't have yet.
			$master = $this->fetch($collection, $item->id)?->data['master'] ?? null;
		}
		$cal = $this->converter($collection)->toCalendar($master, (array)($data['instances'] ?? []), $old);
		self::keepUid($cal, $old);
		return $cal->serialize();
	}

	public function create(RemoteCollection $collection, string $local): RemoteItem {
		$cal = ICal::parse($local);
		$master = ICal::masterOf($cal) ?? throw new RemoteException('No event in the calendar object');
		$body = $this->converter($collection)->fromEvent($master);
		$body['iCalUID'] = (string)$master->UID;
		$created = $this->http->request('POST', self::BASE . '/calendars/' . rawurlencode($collection->id) . '/events', $body)['json'] ?? [];
		$id = (string)($created['id'] ?? throw new RemoteException('Google returned no event id'));
		$etag = $this->pushInstances($collection, $id, $cal) ?? (string)($created['etag'] ?? '');
		return new RemoteItem($id, $etag);
	}

	public function update(RemoteCollection $collection, string $id, ?string $etag, string $local): RemoteItem {
		$cal = ICal::parse($local);
		$master = ICal::masterOf($cal) ?? throw new RemoteException('No event in the calendar object');
		$headers = $etag !== null && $etag !== '' ? ['If-Match' => $etag] : [];
		$response = $this->http->request('PATCH', $this->eventUrl($collection, $id), $this->converter($collection)->fromEvent($master), $headers);
		if ($response['status'] === 404 || $response['status'] === 410) {
			throw new PreconditionFailedException('The event was deleted in Google', $response['status']);
		}
		$newEtag = $this->pushInstances($collection, $id, $cal) ?? (string)($response['json']['etag'] ?? '');
		return new RemoteItem($id, $newEtag);
	}

	public function delete(RemoteCollection $collection, string $id, ?string $etag): void {
		$headers = $etag !== null && $etag !== '' ? ['If-Match' => $etag] : [];
		$this->http->request('DELETE', $this->eventUrl($collection, $id), null, $headers);
	}

	/**
	 * Sends each RECURRENCE-ID exception as a change to its Google instance (cancelled
	 * occurrences travel as EXDATE lines on the master). Returns the series' etag afterwards.
	 */
	private function pushInstances(RemoteCollection $collection, string $masterId, VCalendar $cal): ?string {
		$exceptions = ICal::exceptionsOf($cal);
		if ($exceptions === []) {
			return null;
		}
		$converter = $this->converter($collection);
		foreach ($exceptions as $exception) {
			$rid = ICal::recurrenceId($exception, $this->zone($collection));
			if ($rid === null) {
				continue;
			}
			$instanceId = GoogleEventConverter::instanceId($masterId, $rid['at'], $rid['allDay']);
			$this->http->request('PATCH', $this->eventUrl($collection, $instanceId), $converter->fromEvent($exception));
		}
		$master = $this->http->request('GET', $this->eventUrl($collection, $masterId))['json'] ?? [];
		return isset($master['etag']) ? (string)$master['etag'] : null;
	}

	private function eventUrl(RemoteCollection $collection, string $id): string {
		return self::BASE . '/calendars/' . rawurlencode($collection->id) . '/events/' . rawurlencode($id);
	}

	private function converter(RemoteCollection $collection): GoogleEventConverter {
		return new GoogleEventConverter($this->zone($collection));
	}

	private function zone(RemoteCollection $collection): DateTimeZone {
		try {
			return new DateTimeZone($collection->timeZone ?? 'UTC');
		} catch (\Exception) {
			return new DateTimeZone('UTC');
		}
	}

	/** A series keeps the UID its local copy already had. */
	public static function keepUid(VCalendar $cal, ?VCalendar $old): void {
		$uid = $old !== null ? (string)(ICal::masterOf($old)?->UID ?? '') : '';
		if ($uid === '') {
			return;
		}
		foreach ($cal->select('VEVENT') as $event) {
			$event->UID = $uid;
		}
	}
}
