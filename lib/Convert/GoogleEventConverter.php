<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Convert;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * iCalendar ⇄ Google Calendar v3 events.
 *
 * A Google series is a master event (with RRULE/EXDATE lines in `recurrence`) plus instances that
 * differ from it (`recurringEventId`, `originalStartTime`). Locally a series is one calendar
 * object: the master VEVENT and a VEVENT per changed instance (RECURRENCE-ID); a cancelled
 * instance becomes an EXDATE.
 */
final class GoogleEventConverter {
	/** Google's "use the calendar's default reminders" survives a round trip through this. */
	public const DEFAULT_REMINDERS = 'X-TEAMSLOGIC-DEFAULT-REMINDERS';
	public const COLOR = 'X-TEAMSLOGIC-GOOGLE-COLOR';

	private const PARTSTAT_IN = [
		'needsAction' => 'NEEDS-ACTION', 'accepted' => 'ACCEPTED',
		'declined' => 'DECLINED', 'tentative' => 'TENTATIVE',
	];

	/** @param DateTimeZone $calendarZone the calendar's zone, for times Google sends without one */
	public function __construct(
		private DateTimeZone $calendarZone = new DateTimeZone('UTC'),
	) {
	}

	/**
	 * A series (or single event) as a calendar object. Pass the existing local object to keep the
	 * instances this change set doesn't mention; with only instances changed, $master may be null.
	 *
	 * @param array<string, mixed>|null $master
	 * @param list<array<string, mixed>> $instances
	 */
	public function toCalendar(?array $master, array $instances = [], ?VCalendar $existing = null): VCalendar {
		$cal = ICal::newCalendar();
		$kept = [];
		if ($existing !== null) {
			if ($master === null && ($old = ICal::masterOf($existing)) !== null) {
				$cal->add(clone $old);
			}
			foreach (ICal::exceptionsOf($existing) as $exception) {
				$kept[] = clone $exception;
			}
		}
		if ($master !== null) {
			$cal->add($this->toEvent($cal, $master));
		}
		foreach ($kept as $exception) {
			$cal->add($exception);
		}
		foreach ($instances as $instance) {
			$this->applyInstance($cal, $instance);
		}
		return $cal;
	}

	/**
	 * Puts a changed instance into a series: a cancelled one becomes an EXDATE on the master
	 * (dropping any exception for it); any other replaces or adds its RECURRENCE-ID VEVENT.
	 *
	 * @param array<string, mixed> $instance
	 */
	public function applyInstance(VCalendar $cal, array $instance): void {
		[$original, $allDay] = $this->time((array)($instance['originalStartTime'] ?? []));
		ICal::removeException($cal, $original, $allDay);
		$master = ICal::masterOf($cal);
		if (($instance['status'] ?? '') === 'cancelled') {
			if ($master !== null) {
				$this->addExdate($master, $original, $allDay);
			}
			return;
		}
		$event = $this->toEvent($cal, $instance);
		unset($event->RRULE, $event->EXDATE, $event->RDATE);
		if ($master !== null) {
			// The master's zone decides how the occurrence is named.
			$zone = ICal::start($master, $this->calendarZone)['at']->getTimezone();
			$original = $original->setTimezone($zone);
		}
		ICal::setWhen($event, 'RECURRENCE-ID', $original, $allDay);
		$cal->add($event);
	}

	/** @param array<string, mixed> $e */
	public function toEvent(VCalendar $cal, array $e): VEvent {
		/** @var VEvent $event */
		$event = $cal->createComponent('VEVENT');
		$event->UID = (string)($e['iCalUID'] ?? $e['id']);
		$updated = isset($e['updated']) ? new DateTimeImmutable((string)$e['updated']) : new DateTimeImmutable('now');
		$event->DTSTAMP = $updated->setTimezone(new DateTimeZone('UTC'));
		if (isset($e['created'])) {
			$event->CREATED = (new DateTimeImmutable((string)$e['created']))->setTimezone(new DateTimeZone('UTC'));
		}
		$event->{'LAST-MODIFIED'} = $updated->setTimezone(new DateTimeZone('UTC'));
		if (isset($e['sequence'])) {
			$event->SEQUENCE = (int)$e['sequence'];
		}

		[$start, $allDay] = $this->time((array)($e['start'] ?? []));
		[$end] = $this->time((array)($e['end'] ?? $e['start'] ?? []));
		ICal::setWhen($event, 'DTSTART', $start, $allDay);
		ICal::setWhen($event, 'DTEND', $end, $allDay);

		ICal::setText($event, 'SUMMARY', isset($e['summary']) ? (string)$e['summary'] : null);
		ICal::setText($event, 'DESCRIPTION', isset($e['description']) ? (string)$e['description'] : null);
		ICal::setText($event, 'LOCATION', isset($e['location']) ? (string)$e['location'] : null);

		foreach ((array)($e['recurrence'] ?? []) as $line) {
			ICal::addContentLine($event, (string)$line);
		}

		$status = strtoupper((string)($e['status'] ?? 'confirmed'));
		if (in_array($status, ['CONFIRMED', 'TENTATIVE', 'CANCELLED'], true)) {
			$event->STATUS = $status;
		}
		$event->TRANSP = ($e['transparency'] ?? 'opaque') === 'transparent' ? 'TRANSPARENT' : 'OPAQUE';
		$visibility = (string)($e['visibility'] ?? 'default');
		if ($visibility === 'private' || $visibility === 'confidential' || $visibility === 'public') {
			$event->CLASS = strtoupper($visibility);
		}
		if (isset($e['colorId'])) {
			$event->{self::COLOR} = (string)$e['colorId'];
		}

		$organizer = (array)($e['organizer'] ?? []);
		ICal::setOrganizer($event, $organizer['email'] ?? null, $organizer['displayName'] ?? null);
		foreach ((array)($e['attendees'] ?? []) as $att) {
			if (empty($att['email'])) {
				continue;
			}
			ICal::addAttendee(
				$event,
				(string)$att['email'],
				$att['displayName'] ?? null,
				self::PARTSTAT_IN[$att['responseStatus'] ?? 'needsAction'] ?? 'NEEDS-ACTION',
				(bool)($att['optional'] ?? false),
				(bool)($att['resource'] ?? false),
			);
		}

		$reminders = (array)($e['reminders'] ?? ['useDefault' => true]);
		if (!empty($reminders['useDefault'])) {
			$event->{self::DEFAULT_REMINDERS} = 'TRUE';
		}
		foreach ((array)($reminders['overrides'] ?? []) as $override) {
			ICal::addReminder($event, (int)($override['minutes'] ?? 0), ($override['method'] ?? 'popup') === 'email');
		}

		ICal::addJoinLink($event, $this->joinUrl($e));
		if (($e['conferenceData']['conferenceSolution']['key']['type'] ?? null) === 'hangoutsMeet' || isset($e['hangoutLink'])) {
			$event->{ICal::ONLINE_MEETING} = 'meet';
		}
		return $event;
	}

	/**
	 * The Google body for a VEVENT (master or exception). Recurrence lines go on a master only;
	 * Google takes the organiser from the calendar, so it isn't sent. Text fields and attendees are
	 * always present (empty when cleared) so a PATCH clears what was removed locally.
	 *
	 * @return array<string, mixed>
	 */
	public function fromEvent(VEvent $event): array {
		$start = ICal::start($event, $this->calendarZone);
		$end = ICal::end($event, $this->calendarZone);
		$body = [
			'summary' => ICal::text($event, 'SUMMARY') ?? '',
			'description' => ICal::descriptionForProvider($event),
			'location' => ICal::locationForProvider($event),
			'start' => $this->googleTime($start['at'], $start['allDay']),
			'end' => $this->googleTime($end['at'], $end['allDay']),
		];
		if (!isset($event->{'RECURRENCE-ID'})) {
			$recurrence = [];
			foreach (['RRULE', 'EXRULE', 'RDATE', 'EXDATE'] as $name) {
				foreach ($event->select($name) as $prop) {
					$recurrence[] = ICal::contentLine($prop);
				}
			}
			if ($recurrence !== []) {
				$body['recurrence'] = $recurrence;
			}
		}

		$status = strtolower(ICal::text($event, 'STATUS') ?? 'confirmed');
		if (in_array($status, ['confirmed', 'tentative'], true)) {
			$body['status'] = $status;
		}
		$body['transparency'] = strtoupper(ICal::text($event, 'TRANSP') ?? 'OPAQUE') === 'TRANSPARENT' ? 'transparent' : 'opaque';
		$class = strtolower(ICal::text($event, 'CLASS') ?? '');
		$body['visibility'] = in_array($class, ['private', 'confidential', 'public'], true) ? $class : 'default';
		if (($color = ICal::text($event, self::COLOR)) !== null) {
			$body['colorId'] = $color;
		}

		$attendees = [];
		foreach (ICal::attendees($event) as $att) {
			$row = ['email' => $att['email']];
			if ($att['name'] !== null) {
				$row['displayName'] = $att['name'];
			}
			$row['responseStatus'] = array_search($att['partstat'], self::PARTSTAT_IN, true) ?: 'needsAction';
			if ($att['optional']) {
				$row['optional'] = true;
			}
			if ($att['resource']) {
				$row['resource'] = true;
			}
			$attendees[] = $row;
		}
		$body['attendees'] = $attendees;

		$reminders = ICal::reminders($event);
		if ($reminders === [] && ICal::text($event, self::DEFAULT_REMINDERS) !== null) {
			$body['reminders'] = ['useDefault' => true];
		} else {
			$body['reminders'] = [
				'useDefault' => false,
				'overrides' => array_map(
					static fn (array $r) => ['method' => $r['email'] ? 'email' : 'popup', 'minutes' => $r['minutes']],
					array_slice($reminders, 0, 5),
				),
			];
		}

		if (strtolower(ICal::text($event, ICal::ONLINE_MEETING) ?? '') === 'meet' && ICal::text($event, ICal::JOIN_URL) === null) {
			// Ask Google for a Meet link (needs conferenceDataVersion=1 on the request). The same
			// request id for the same event, so asking twice makes one meeting.
			$body['conferenceData'] = ['createRequest' => [
				'requestId' => 'teamslogic-' . substr(sha1((string)$event->UID . "\0" . (string)($event->{'RECURRENCE-ID'} ?? '')), 0, 24),
				'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
			]];
		}
		return array_filter($body, static fn ($v) => $v !== null);
	}

	/**
	 * Google's id for one occurrence of a series: the master id, "_", and the original start in UTC
	 * (or the date, for all-day series).
	 */
	public static function instanceId(string $masterId, DateTimeImmutable $originalStart, bool $allDay): string {
		return $masterId . '_' . ($allDay
			? $originalStart->format('Ymd')
			: $originalStart->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'));
	}

	/** The join link of a Meet (or other) conference. */
	private function joinUrl(array $e): ?string {
		foreach ((array)($e['conferenceData']['entryPoints'] ?? []) as $entry) {
			if (($entry['entryPointType'] ?? '') === 'video' && !empty($entry['uri'])) {
				return (string)$entry['uri'];
			}
		}
		return isset($e['hangoutLink']) ? (string)$e['hangoutLink'] : null;
	}

	/**
	 * @param array<string, mixed> $t Google's {date} or {dateTime, timeZone}
	 * @return array{0: DateTimeImmutable, 1: bool}
	 */
	private function time(array $t): array {
		if (isset($t['date'])) {
			return [new DateTimeImmutable((string)$t['date'], $this->calendarZone), true];
		}
		$zone = isset($t['timeZone']) ? TimeZones::toIana((string)$t['timeZone']) : $this->calendarZone;
		$at = new DateTimeImmutable((string)($t['dateTime'] ?? 'now'), $zone);
		return [$at->setTimezone($zone), false];
	}

	/** @return array<string, string> */
	private function googleTime(DateTimeImmutable $at, bool $allDay): array {
		if ($allDay) {
			return ['date' => $at->format('Y-m-d')];
		}
		$zone = $at->getTimezone()->getName();
		if (!TimeZones::isUtc($zone) && str_contains($zone, '/')) {
			return ['dateTime' => $at->format('Y-m-d\TH:i:s'), 'timeZone' => $zone];
		}
		$utc = $at->setTimezone(new DateTimeZone('UTC'));
		return ['dateTime' => $utc->format('Y-m-d\TH:i:s\Z'), 'timeZone' => 'UTC'];
	}

	private function addExdate(VEvent $master, DateTimeImmutable $when, bool $allDay): void {
		foreach ($master->select('EXDATE') as $exdate) {
			foreach ($exdate->getDateTimes() as $existing) {
				if (ICal::sameOccurrence($existing, $when, $allDay)) {
					return;
				}
			}
		}
		if ($allDay) {
			$master->add('EXDATE', $when->format('Ymd'), ['VALUE' => 'DATE']);
			return;
		}
		$zone = ICal::start($master, $this->calendarZone)['at']->getTimezone();
		$master->add('EXDATE', $when->setTimezone($zone));
	}
}
