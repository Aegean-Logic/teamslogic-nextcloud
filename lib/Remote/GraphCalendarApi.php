<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Remote;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCA\TeamsLogic\Convert\GraphEventConverter;
use OCA\TeamsLogic\Convert\ICal;
use OCA\TeamsLogic\Db\SyncItem;
use OCA\TeamsLogic\Sync\ChangeSet;
use OCA\TeamsLogic\Sync\RemoteApi;
use OCA\TeamsLogic\Sync\RemoteCollection;
use OCA\TeamsLogic\Sync\RemoteItem;
use Sabre\VObject\Component\VCalendar;

/**
 * Microsoft Graph calendars.
 *
 * Changes come from `/me/calendars/{id}/events/delta`, which returns series masters and single
 * events (not expanded occurrences). Graph v1.0 only offers delta on a calendar view of a date
 * range, so this one endpoint is Graph beta; if it's refused, the calendar falls back to listing
 * its events each round (v1.0) and comparing etags. Everything else is v1.0.
 *
 * A changed series is re-read with its changed occurrences (`/events/{id}/instances`, those of
 * type "exception") so the local object is whole.
 */
class GraphCalendarApi implements RemoteApi {
	public const V1 = 'https://graph.microsoft.com/v1.0';
	public const BETA = 'https://graph.microsoft.com/beta';
	/** A token value meaning "this calendar is listed in full each round". */
	public const LISTING = 'listing';
	/** How far around now a series' changed occurrences are looked for. */
	private const EXCEPTION_WINDOW = ['P1Y', 'P2Y'];

	private GraphEventConverter $converter;

	public function __construct(
		private Http $http,
	) {
		$this->converter = new GraphEventConverter();
	}

	public function kind(): string {
		return SyncItem::CALENDAR;
	}

	public function label(): string {
		return 'Outlook';
	}

	public function collections(): array {
		$out = [];
		$url = self::V1 . '/me/calendars?$top=100';
		while ($url !== null) {
			$page = $this->http->request('GET', $url)['json'] ?? [];
			foreach ((array)($page['value'] ?? []) as $c) {
				$color = isset($c['hexColor']) && $c['hexColor'] !== '' ? (string)$c['hexColor'] : null;
				$out[] = new RemoteCollection((string)$c['id'], (string)($c['name'] ?? 'Calendar'), empty($c['canEdit']), $color, null, self::meetingProvider($c));
			}
			$url = $page['@odata.nextLink'] ?? null;
		}
		return $out;
	}

	/** The calendar's online meeting provider: its default if allowed, else the first allowed. */
	private static function meetingProvider(array $calendar): ?string {
		$allowed = array_values(array_filter(
			array_map('strval', (array)($calendar['allowedOnlineMeetingProviders'] ?? [])),
			static fn ($p) => $p !== 'unknown' && $p !== '',
		));
		$default = (string)($calendar['defaultOnlineMeetingProvider'] ?? '');
		if (in_array($default, $allowed, true)) {
			return $default;
		}
		return $allowed[0] ?? null;
	}

	public function changes(RemoteCollection $collection, ?string $token): ChangeSet {
		if ($token === self::LISTING) {
			return $this->listing($collection);
		}
		$url = $token ?? self::BETA . '/me/calendars/' . rawurlencode($collection->id) . '/events/delta';
		$items = [];
		$deltaLink = null;
		while ($url !== null) {
			try {
				$response = $this->http->request('GET', $url, null, ['Prefer' => 'odata.maxpagesize=100']);
			} catch (RemoteException $e) {
				if ($token === null && in_array($e->status, [400, 403, 404, 501], true)) {
					// No events delta for this calendar: list it instead, every round.
					return $this->listing($collection);
				}
				throw $e;
			}
			if ($response['status'] === 410 || $response['status'] === 404) {
				if ($token === null) {
					return $this->listing($collection);
				}
				throw new SyncTokenExpiredException('Graph delta link expired', $response['status']);
			}
			$page = $response['json'] ?? [];
			foreach ((array)($page['value'] ?? []) as $event) {
				$this->collect($items, $event);
			}
			$url = $page['@odata.nextLink'] ?? null;
			$deltaLink = $page['@odata.deltaLink'] ?? $deltaLink;
		}
		return new ChangeSet($items, $token === null, $deltaLink !== null ? (string)$deltaLink : self::LISTING);
	}

	/** Every master and single event in the calendar (v1.0). */
	private function listing(RemoteCollection $collection): ChangeSet {
		$items = [];
		$url = self::V1 . '/me/calendars/' . rawurlencode($collection->id) . '/events?$top=100&$select=id,type,seriesMasterId';
		while ($url !== null) {
			$page = $this->http->request('GET', $url)['json'] ?? [];
			foreach ((array)($page['value'] ?? []) as $event) {
				$this->collect($items, $event);
			}
			$url = $page['@odata.nextLink'] ?? null;
		}
		return new ChangeSet($items, true, self::LISTING);
	}

	/** @param array<string, RemoteItem> $items */
	private function collect(array &$items, array $event): void {
		if (isset($event['@removed'])) {
			$id = (string)$event['id'];
			$items[$id] = new RemoteItem($id, null, true);
			return;
		}
		$type = (string)($event['type'] ?? 'singleInstance');
		if (in_array($type, ['occurrence', 'exception'], true)) {
			// A changed occurrence: its series is re-read.
			$master = (string)($event['seriesMasterId'] ?? '');
			if ($master !== '' && !isset($items[$master])) {
				$items[$master] = new RemoteItem($master, null);
			}
			return;
		}
		$id = (string)$event['id'];
		$items[$id] = new RemoteItem($id, isset($event['@odata.etag']) ? (string)$event['@odata.etag'] : null);
	}

	public function fetch(RemoteCollection $collection, string $id): ?RemoteItem {
		$response = $this->http->request('GET', self::V1 . '/me/events/' . rawurlencode($id), null, ['Prefer' => 'outlook.body-content-type="text"']);
		$event = $response['json'];
		if ($response['status'] === 404 || $response['status'] === 410 || $event === null) {
			return null;
		}
		if (($event['type'] ?? '') === 'seriesMaster') {
			$event['exceptionOccurrences'] = $this->exceptions($id);
		}
		return new RemoteItem($id, (string)($event['@odata.etag'] ?? ''), false, $event);
	}

	public function toLocal(RemoteCollection $collection, RemoteItem $item, ?string $existing): string {
		$event = is_array($item->data) ? $item->data : ($this->fetch($collection, $item->id)?->data ?? throw new RemoteException('The event is gone', 404));
		$cal = $this->converter->toCalendar($event);
		GoogleCalendarApi::keepUid($cal, $existing !== null ? ICal::parse($existing) : null);
		return $cal->serialize();
	}

	public function create(RemoteCollection $collection, string $local): RemoteItem {
		$cal = ICal::parse($local);
		$master = ICal::masterOf($cal) ?? throw new RemoteException('No event in the calendar object');
		$created = $this->http->request('POST', self::V1 . '/me/calendars/' . rawurlencode($collection->id) . '/events', $this->converter->fromEvent($master, $collection->meetingProvider))['json'] ?? [];
		$id = (string)($created['id'] ?? throw new RemoteException('Graph returned no event id'));
		$etag = $this->pushOccurrences($id, $cal) ?? (string)($created['@odata.etag'] ?? '');
		return new RemoteItem($id, $etag);
	}

	public function update(RemoteCollection $collection, string $id, ?string $etag, string $local): RemoteItem {
		$cal = ICal::parse($local);
		$master = ICal::masterOf($cal) ?? throw new RemoteException('No event in the calendar object');
		$headers = $etag !== null && $etag !== '' ? ['If-Match' => $etag] : [];
		$response = $this->http->request('PATCH', self::V1 . '/me/events/' . rawurlencode($id), $this->converter->fromEvent($master, $collection->meetingProvider), $headers);
		if ($response['status'] === 404) {
			throw new PreconditionFailedException('The event was deleted in Outlook', 404);
		}
		$newEtag = $this->pushOccurrences($id, $cal) ?? (string)($response['json']['@odata.etag'] ?? '');
		return new RemoteItem($id, $newEtag);
	}

	public function delete(RemoteCollection $collection, string $id, ?string $etag): void {
		$headers = $etag !== null && $etag !== '' ? ['If-Match' => $etag] : [];
		$this->http->request('DELETE', self::V1 . '/me/events/' . rawurlencode($id), null, $headers);
	}

	/**
	 * Changed occurrences (RECURRENCE-ID) are patched onto their Graph instance and EXDATEs delete
	 * theirs. Returns the series' etag afterwards, or null if there was nothing to do.
	 */
	private function pushOccurrences(string $masterId, VCalendar $cal): ?string {
		$master = ICal::masterOf($cal);
		$exceptions = ICal::exceptionsOf($cal);
		$exdates = [];
		foreach ($master?->select('EXDATE') ?? [] as $prop) {
			foreach ($prop->getDateTimes() as $dt) {
				$exdates[] = DateTimeImmutable::createFromInterface($dt);
			}
		}
		if ($exceptions === [] && $exdates === []) {
			return null;
		}
		$utc = new DateTimeZone('UTC');
		foreach ($exceptions as $exception) {
			$rid = ICal::recurrenceId($exception, $utc);
			$instance = $rid !== null ? $this->instanceAt($masterId, $rid['at'], $rid['allDay']) : null;
			if ($instance !== null) {
				$body = $this->converter->fromEvent($exception);
				unset($body['recurrence']);
				$this->http->request('PATCH', self::V1 . '/me/events/' . rawurlencode($instance), $body);
			}
		}
		$allDay = $master !== null && !$master->DTSTART->hasTime();
		foreach ($exdates as $exdate) {
			$instance = $this->instanceAt($masterId, $exdate, $allDay);
			if ($instance !== null) {
				$this->http->request('DELETE', self::V1 . '/me/events/' . rawurlencode($instance));
			}
		}
		$event = $this->http->request('GET', self::V1 . '/me/events/' . rawurlencode($masterId) . '?$select=id')['json'] ?? [];
		return isset($event['@odata.etag']) ? (string)$event['@odata.etag'] : null;
	}

	/** The id of the occurrence of a series that was originally at $when. */
	private function instanceAt(string $masterId, DateTimeImmutable $when, bool $allDay): ?string {
		$utc = new DateTimeZone('UTC');
		$from = $when->setTimezone($utc)->sub(new DateInterval('P1D'))->format('Y-m-d\TH:i:s\Z');
		$to = $when->setTimezone($utc)->add(new DateInterval('P2D'))->format('Y-m-d\TH:i:s\Z');
		$url = self::V1 . '/me/events/' . rawurlencode($masterId) . '/instances?startDateTime=' . $from . '&endDateTime=' . $to . '&$select=id,originalStart';
		foreach ((array)($this->http->request('GET', $url)['json']['value'] ?? []) as $instance) {
			if (empty($instance['originalStart'])) {
				continue;
			}
			$original = new DateTimeImmutable((string)$instance['originalStart']);
			if (ICal::sameOccurrence($original, $when, $allDay)) {
				return (string)$instance['id'];
			}
		}
		return null;
	}

	/** @return list<array<string, mixed>> the series' changed occurrences within the window */
	private function exceptions(string $masterId): array {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$from = $now->sub(new DateInterval(self::EXCEPTION_WINDOW[0]))->format('Y-m-d\TH:i:s\Z');
		$to = $now->add(new DateInterval(self::EXCEPTION_WINDOW[1]))->format('Y-m-d\TH:i:s\Z');
		$out = [];
		$url = self::V1 . '/me/events/' . rawurlencode($masterId) . '/instances?startDateTime=' . $from . '&endDateTime=' . $to . '&$top=200';
		while ($url !== null) {
			$page = $this->http->request('GET', $url, null, ['Prefer' => 'outlook.body-content-type="text"'])['json'] ?? [];
			foreach ((array)($page['value'] ?? []) as $instance) {
				if (($instance['type'] ?? '') === 'exception') {
					$out[] = $instance;
				}
			}
			$url = $page['@odata.nextLink'] ?? null;
		}
		return $out;
	}
}
