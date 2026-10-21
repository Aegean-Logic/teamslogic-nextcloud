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
 * iCalendar ⇄ Microsoft Graph events.
 *
 * Graph sends times in UTC unless asked otherwise; `originalStartTimeZone` (a Windows name) says
 * where the organiser made the event, so times are written in that zone and a weekly 09:00
 * meeting stays at 09:00 across daylight saving. A series master carries its changed occurrences
 * in `exceptionOccurrences` and its deleted ones in `cancelledOccurrences`
 * ("OID.{masterId}.{yyyy-MM-dd}").
 */
final class GraphEventConverter {
	/** Outlook's free/busy value, kept exactly (TRANSP only knows free and busy). */
	public const BUSY_STATUS = 'X-MICROSOFT-CDO-BUSYSTATUS';

	private const PARTSTAT_IN = [
		'none' => 'NEEDS-ACTION', 'notResponded' => 'NEEDS-ACTION', 'organizer' => 'ACCEPTED',
		'accepted' => 'ACCEPTED', 'tentativelyAccepted' => 'TENTATIVE', 'declined' => 'DECLINED',
	];
	private const PARTSTAT_OUT = [
		'NEEDS-ACTION' => 'none', 'ACCEPTED' => 'accepted', 'TENTATIVE' => 'tentativelyAccepted', 'DECLINED' => 'declined',
	];
	private const SHOW_AS = ['free', 'tentative', 'busy', 'oof', 'workingElsewhere'];

	/**
	 * A series master (with its exceptionOccurrences and cancelledOccurrences) or a single
	 * event as a calendar object.
	 *
	 * @param array<string, mixed> $e
	 */
	public function toCalendar(array $e): VCalendar {
		$cal = ICal::newCalendar();
		$master = $this->toEvent($cal, $e);
		$cal->add($master);
		$start = ICal::start($master, new DateTimeZone('UTC'));

		if (isset($e['recurrence']) && is_array($e['recurrence'])) {
			$master->RRULE = GraphRecurrence::toRrule($e['recurrence'], $start['allDay'], $start['at']->getTimezone());
			foreach ((array)($e['cancelledOccurrences'] ?? []) as $oid) {
				if (preg_match('/(\d{4}-\d{2}-\d{2})$/', (string)$oid, $m)) {
					$day = new DateTimeImmutable($m[1] . ' ' . $start['at']->format('H:i:s'), $start['at']->getTimezone());
					if ($start['allDay']) {
						$master->add('EXDATE', $day->format('Ymd'), ['VALUE' => 'DATE']);
					} else {
						$master->add('EXDATE', $day);
					}
				}
			}
			foreach ((array)($e['exceptionOccurrences'] ?? []) as $occurrence) {
				$this->applyException($cal, (array)$occurrence);
			}
		}
		return $cal;
	}

	/**
	 * Puts a changed occurrence into the series, replacing any exception for the same occurrence.
	 *
	 * @param array<string, mixed> $occurrence
	 */
	public function applyException(VCalendar $cal, array $occurrence): void {
		$master = ICal::masterOf($cal);
		if ($master === null || empty($occurrence['originalStart'])) {
			return;
		}
		$start = ICal::start($master, new DateTimeZone('UTC'));
		$original = (new DateTimeImmutable((string)$occurrence['originalStart']))->setTimezone($start['at']->getTimezone());
		ICal::removeException($cal, $original, $start['allDay']);
		if (!empty($occurrence['isCancelled'])) {
			return;
		}
		$event = $this->toEvent($cal, $occurrence, $start['at']->getTimezone());
		$event->UID = (string)$master->UID;
		ICal::setWhen($event, 'RECURRENCE-ID', $original, $start['allDay']);
		$cal->add($event);
	}

	/** @param array<string, mixed> $e */
	public function toEvent(VCalendar $cal, array $e, ?DateTimeZone $zone = null): VEvent {
		/** @var VEvent $event */
		$event = $cal->createComponent('VEVENT');
		$event->UID = (string)($e['iCalUId'] ?? $e['id']);
		$modified = isset($e['lastModifiedDateTime']) ? new DateTimeImmutable((string)$e['lastModifiedDateTime']) : new DateTimeImmutable('now');
		$utc = new DateTimeZone('UTC');
		$event->DTSTAMP = $modified->setTimezone($utc);
		$event->{'LAST-MODIFIED'} = $modified->setTimezone($utc);
		if (isset($e['createdDateTime'])) {
			$event->CREATED = (new DateTimeImmutable((string)$e['createdDateTime']))->setTimezone($utc);
		}

		$allDay = (bool)($e['isAllDay'] ?? false);
		$zone ??= TimeZones::toIana($e['originalStartTimeZone'] ?? ($e['start']['timeZone'] ?? null));
		$start = $this->time((array)($e['start'] ?? []), $zone, $allDay);
		$end = $this->time((array)($e['end'] ?? $e['start'] ?? []), $zone, $allDay);
		ICal::setWhen($event, 'DTSTART', $start, $allDay);
		ICal::setWhen($event, 'DTEND', $end, $allDay);

		ICal::setText($event, 'SUMMARY', isset($e['subject']) ? (string)$e['subject'] : null);
		$body = (array)($e['body'] ?? []);
		$description = (string)($body['content'] ?? '');
		if (strtolower((string)($body['contentType'] ?? 'text')) === 'html') {
			$description = ICal::htmlToText($description);
		}
		ICal::setText($event, 'DESCRIPTION', $description);
		ICal::setText($event, 'LOCATION', isset($e['location']['displayName']) ? (string)$e['location']['displayName'] : null);

		$event->STATUS = !empty($e['isCancelled']) ? 'CANCELLED' : 'CONFIRMED';
		$showAs = (string)($e['showAs'] ?? 'busy');
		$event->TRANSP = $showAs === 'free' ? 'TRANSPARENT' : 'OPAQUE';
		if (in_array($showAs, self::SHOW_AS, true)) {
			$event->{self::BUSY_STATUS} = strtoupper($showAs);
		}
		$event->CLASS = match ((string)($e['sensitivity'] ?? 'normal')) {
			'private', 'personal' => 'PRIVATE',
			'confidential' => 'CONFIDENTIAL',
			default => 'PUBLIC',
		};
		$categories = array_values(array_filter(array_map('strval', (array)($e['categories'] ?? []))));
		if ($categories !== []) {
			$event->add('CATEGORIES', $categories);
		}

		$organizer = (array)($e['organizer']['emailAddress'] ?? []);
		ICal::setOrganizer($event, $organizer['address'] ?? null, $organizer['name'] ?? null);
		foreach ((array)($e['attendees'] ?? []) as $att) {
			$address = (array)($att['emailAddress'] ?? []);
			if (empty($address['address'])) {
				continue;
			}
			ICal::addAttendee(
				$event,
				(string)$address['address'],
				$address['name'] ?? null,
				self::PARTSTAT_IN[$att['status']['response'] ?? 'none'] ?? 'NEEDS-ACTION',
				($att['type'] ?? 'required') === 'optional',
				($att['type'] ?? 'required') === 'resource',
			);
		}

		if (!empty($e['isReminderOn'])) {
			ICal::addReminder($event, (int)($e['reminderMinutesBeforeStart'] ?? 15));
		}
		ICal::addJoinLink($event, $e['onlineMeeting']['joinUrl'] ?? ($e['onlineMeetingUrl'] ?? null));
		if (!empty($e['isOnlineMeeting'])) {
			$event->{ICal::ONLINE_MEETING} = 'teams';
		}
		return $event;
	}

	/**
	 * The Graph body for a VEVENT (master or exception).
	 *
	 * @param string|null $meetingProvider the calendar's online meeting provider (teamsForBusiness…),
	 *                                     used when the event asks for a meeting (ICal::ONLINE_MEETING)
	 * @return array<string, mixed>
	 * @throws UnsupportedException for a rule Outlook has no pattern for
	 */
	public function fromEvent(VEvent $event, ?string $meetingProvider = null): array {
		$utc = new DateTimeZone('UTC');
		$start = ICal::start($event, $utc);
		$end = ICal::end($event, $utc);
		$allDay = $start['allDay'];
		$body = [
			'subject' => ICal::text($event, 'SUMMARY') ?? '',
			'body' => ['contentType' => 'text', 'content' => ICal::descriptionForProvider($event)],
			'start' => $this->graphTime($start['at'], $allDay),
			'end' => $this->graphTime($end['at'], $allDay),
			'isAllDay' => $allDay,
			'location' => ['displayName' => ICal::locationForProvider($event)],
		];
		if ($meetingProvider !== null && ICal::text($event, ICal::ONLINE_MEETING) !== null && !isset($event->{'RECURRENCE-ID'})) {
			// Outlook makes the Teams (or Skype) meeting and its link.
			$body['isOnlineMeeting'] = true;
			$body['onlineMeetingProvider'] = $meetingProvider;
		}

		if (!isset($event->{'RECURRENCE-ID'}) && isset($event->RRULE)) {
			$body['recurrence'] = GraphRecurrence::fromRrule((string)$event->RRULE, $start['at'], $allDay);
		}

		$busy = strtolower(ICal::text($event, self::BUSY_STATUS) ?? '');
		$match = array_values(array_filter(self::SHOW_AS, static fn ($s) => strtolower($s) === $busy));
		$body['showAs'] = $match[0] ?? (strtoupper(ICal::text($event, 'TRANSP') ?? '') === 'TRANSPARENT' ? 'free' : 'busy');
		$body['sensitivity'] = match (strtoupper(ICal::text($event, 'CLASS') ?? 'PUBLIC')) {
			'PRIVATE' => 'private',
			'CONFIDENTIAL' => 'confidential',
			default => 'normal',
		};
		if (isset($event->CATEGORIES)) {
			$body['categories'] = array_values(array_map('strval', $event->CATEGORIES->getParts()));
		}

		$attendees = [];
		foreach (ICal::attendees($event) as $att) {
			$row = [
				'emailAddress' => array_filter(['address' => $att['email'], 'name' => $att['name']], static fn ($v) => $v !== null),
				'type' => $att['resource'] ? 'resource' : ($att['optional'] ? 'optional' : 'required'),
			];
			$attendees[] = $row;
		}
		$body['attendees'] = $attendees;

		$reminders = ICal::reminders($event);
		$body['isReminderOn'] = $reminders !== [];
		if ($reminders !== []) {
			$body['reminderMinutesBeforeStart'] = $reminders[0]['minutes'];
		}
		return $body;
	}

	/** The participation status Graph uses for a PARTSTAT (for responding to an invitation). */
	public static function responseFor(string $partstat): string {
		return self::PARTSTAT_OUT[strtoupper($partstat)] ?? 'none';
	}

	/** @param array<string, mixed> $t Graph's {dateTime, timeZone} */
	private function time(array $t, DateTimeZone $zone, bool $allDay): DateTimeImmutable {
		$raw = (string)($t['dateTime'] ?? 'now');
		// Graph writes seven fractional digits; PHP reads six.
		$raw = preg_replace('/(\.\d{6})\d+/', '$1', $raw) ?? $raw;
		$from = TimeZones::toIana($t['timeZone'] ?? 'UTC');
		$at = new DateTimeImmutable($raw, $from);
		if ($allDay) {
			// All-day events are midnight to midnight wherever they're read; keep the date.
			return new DateTimeImmutable($at->format('Y-m-d'), $zone);
		}
		return $at->setTimezone($zone);
	}

	/** @return array{dateTime: string, timeZone: string} */
	private function graphTime(DateTimeImmutable $at, bool $allDay): array {
		if ($allDay) {
			return ['dateTime' => $at->format('Y-m-d') . 'T00:00:00', 'timeZone' => 'UTC'];
		}
		$windows = TimeZones::toWindows($at->getTimezone());
		if ($windows === 'UTC') {
			$at = $at->setTimezone(new DateTimeZone('UTC'));
		}
		return ['dateTime' => $at->format('Y-m-d\TH:i:s'), 'timeZone' => $windows];
	}
}
