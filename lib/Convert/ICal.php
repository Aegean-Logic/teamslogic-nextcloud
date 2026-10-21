<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Convert;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader;

/**
 * Shared iCalendar helpers for the Google and Graph event converters.
 */
final class ICal {
	public const PRODID = '-//Aegean Logic//TeamsLogic for Nextcloud//EN';
	/** The line we add to a description for a meeting's join link. */
	public const JOIN_PREFIX = 'Join online: ';

	public static function newCalendar(): VCalendar {
		$cal = new VCalendar();
		$cal->PRODID = self::PRODID;
		return $cal;
	}

	public static function parse(string $ics): VCalendar {
		/** @var VCalendar $cal */
		$cal = Reader::read($ics, Reader::OPTION_FORGIVING);
		return $cal;
	}

	public static function setText(VEvent $event, string $name, ?string $value): void {
		unset($event->{$name});
		if ($value !== null && $value !== '') {
			$event->add($name, $value);
		}
	}

	public static function text(VEvent $event, string $name): ?string {
		$value = isset($event->{$name}) ? (string)$event->{$name} : '';
		return $value === '' ? null : $value;
	}

	/**
	 * A content line from a provider ("EXDATE;TZID=Europe/London:20261028T090000") as a property
	 * of $event, parsed the way the iCalendar reader would.
	 */
	public static function addContentLine(VEvent $event, string $line): void {
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:x\r\n" . trim($line) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$parsed = Reader::read($ics, Reader::OPTION_FORGIVING);
		foreach ($parsed->VEVENT->children() as $prop) {
			if ($prop instanceof Property && $prop->name !== 'UID') {
				$event->add($prop->name, $prop->getParts(), $prop->parameters());
			}
		}
	}

	/** A property as one unfolded content line ("RRULE:FREQ=WEEKLY;BYDAY=MO"). */
	public static function contentLine(Property $prop): string {
		return rtrim(str_replace(["\r\n ", "\r\n\t"], '', $prop->serialize()), "\r\n");
	}

	/**
	 * Start of an event.
	 *
	 * @return array{at: DateTimeImmutable, allDay: bool, floating: bool}
	 */
	public static function start(VEvent $event, DateTimeZone $default): array {
		/** @var DateTimeProperty $prop */
		$prop = $event->DTSTART;
		return self::when($prop, $default);
	}

	/**
	 * End of an event: DTEND, or DTSTART + DURATION, or the start (a day later for all-day).
	 *
	 * @return array{at: DateTimeImmutable, allDay: bool, floating: bool}
	 */
	public static function end(VEvent $event, DateTimeZone $default): array {
		if (isset($event->DTEND)) {
			/** @var DateTimeProperty $prop */
			$prop = $event->DTEND;
			return self::when($prop, $default);
		}
		$start = self::start($event, $default);
		if (isset($event->DURATION)) {
			$start['at'] = $start['at']->add(new DateInterval(ltrim((string)$event->DURATION, '+')));
		} elseif ($start['allDay']) {
			$start['at'] = $start['at']->modify('+1 day');
		}
		return $start;
	}

	/** @return array{at: DateTimeImmutable, allDay: bool, floating: bool} */
	public static function when(DateTimeProperty $prop, DateTimeZone $default): array {
		$allDay = !$prop->hasTime();
		$floating = $prop->isFloating();
		$at = DateTimeImmutable::createFromInterface($prop->getDateTime($default));
		return ['at' => $at, 'allDay' => $allDay, 'floating' => $floating];
	}

	/** Sets a DATE or DATE-TIME property; a time in UTC is written with Z, others with TZID. */
	public static function setWhen(VEvent $event, string $name, DateTimeInterface $at, bool $allDay): void {
		unset($event->{$name});
		if ($allDay) {
			$event->add($name, $at->format('Ymd'), ['VALUE' => 'DATE']);
		} else {
			$event->add($name, $at);
		}
	}

	/** The recurrence id of an exception, as a UTC instant (date only for all-day series). */
	public static function recurrenceId(VEvent $event, DateTimeZone $default): ?array {
		if (!isset($event->{'RECURRENCE-ID'})) {
			return null;
		}
		/** @var DateTimeProperty $prop */
		$prop = $event->{'RECURRENCE-ID'};
		return self::when($prop, $default);
	}

	public static function masterOf(VCalendar $cal): ?VEvent {
		foreach ($cal->select('VEVENT') as $event) {
			if (!isset($event->{'RECURRENCE-ID'})) {
				/** @var VEvent $event */
				return $event;
			}
		}
		return null;
	}

	/** @return list<VEvent> */
	public static function exceptionsOf(VCalendar $cal): array {
		$out = [];
		foreach ($cal->select('VEVENT') as $event) {
			if (isset($event->{'RECURRENCE-ID'})) {
				/** @var VEvent $event */
				$out[] = $event;
			}
		}
		return $out;
	}

	/** Removes the exception for the occurrence at $when (same instant, or same date for all-day). */
	public static function removeException(VCalendar $cal, DateTimeInterface $when, bool $allDay): void {
		foreach (self::exceptionsOf($cal) as $exception) {
			$id = self::recurrenceId($exception, new DateTimeZone('UTC'));
			if ($id !== null && self::sameOccurrence($id['at'], $when, $allDay || $id['allDay'])) {
				$cal->remove($exception);
			}
		}
	}

	public static function sameOccurrence(DateTimeInterface $a, DateTimeInterface $b, bool $byDate): bool {
		return $byDate ? $a->format('Ymd') === $b->format('Ymd') : $a->getTimestamp() === $b->getTimestamp();
	}

	/** Adds the meeting join link to the description (once) and to an empty location. */
	public static function addJoinLink(VEvent $event, ?string $url): void {
		if ($url === null || $url === '') {
			return;
		}
		$description = self::text($event, 'DESCRIPTION') ?? '';
		if (!str_contains($description, $url)) {
			self::setText($event, 'DESCRIPTION', ltrim($description . "\n\n" . self::JOIN_PREFIX . $url));
		}
		if (self::text($event, 'LOCATION') === null) {
			self::setText($event, 'LOCATION', $url);
		}
	}

	/**
	 * Reminders: minutes before the start of each VALARM with a relative trigger.
	 *
	 * @return list<array{minutes: int, email: bool}>
	 */
	public static function reminders(VEvent $event): array {
		$out = [];
		foreach ($event->select('VALARM') as $alarm) {
			if (!isset($alarm->TRIGGER)) {
				continue;
			}
			$trigger = $alarm->TRIGGER;
			if (isset($trigger['VALUE']) && strtoupper((string)$trigger['VALUE']) === 'DATE-TIME') {
				continue;
			}
			$related = isset($trigger['RELATED']) ? strtoupper((string)$trigger['RELATED']) : 'START';
			if ($related !== 'START') {
				continue;
			}
			try {
				$interval = new DateInterval(ltrim((string)$trigger, '+-'));
			} catch (\Exception) {
				continue;
			}
			$minutes = $interval->d * 1440 + $interval->h * 60 + $interval->i + intdiv($interval->s, 60);
			if (!str_starts_with((string)$trigger, '-')) {
				$minutes = $minutes === 0 ? 0 : -$minutes;
			}
			if ($minutes < 0) {
				continue;
			}
			$action = isset($alarm->ACTION) ? strtoupper((string)$alarm->ACTION) : 'DISPLAY';
			$out[] = ['minutes' => $minutes, 'email' => $action === 'EMAIL'];
		}
		return $out;
	}

	public static function addReminder(VEvent $event, int $minutes, bool $email = false): void {
		$alarm = $event->add('VALARM', [
			'ACTION' => $email ? 'EMAIL' : 'DISPLAY',
			'TRIGGER' => self::trigger($minutes),
			'DESCRIPTION' => self::text($event, 'SUMMARY') ?? 'Reminder',
		]);
		if ($email) {
			$alarm->add('SUMMARY', self::text($event, 'SUMMARY') ?? 'Reminder');
		}
	}

	private static function trigger(int $minutes): string {
		if ($minutes === 0) {
			return 'PT0S';
		}
		if ($minutes % 1440 === 0) {
			return '-P' . intdiv($minutes, 1440) . 'D';
		}
		if ($minutes % 60 === 0) {
			return '-PT' . intdiv($minutes, 60) . 'H';
		}
		return '-PT' . $minutes . 'M';
	}

	/** An address from "mailto:x@y" (any case), or the value as is. */
	public static function mailto(string $value): string {
		return preg_replace('/^mailto:/i', '', trim($value)) ?? $value;
	}

	/**
	 * Attendees of an event.
	 *
	 * @return list<array{email: string, name: ?string, partstat: string, optional: bool, resource: bool}>
	 */
	public static function attendees(VEvent $event): array {
		$out = [];
		foreach ($event->select('ATTENDEE') as $att) {
			$email = self::mailto((string)$att);
			if ($email === '' || !str_contains($email, '@')) {
				continue;
			}
			$out[] = [
				'email' => $email,
				'name' => isset($att['CN']) ? (string)$att['CN'] : null,
				'partstat' => isset($att['PARTSTAT']) ? strtoupper((string)$att['PARTSTAT']) : 'NEEDS-ACTION',
				'optional' => isset($att['ROLE']) && in_array(strtoupper((string)$att['ROLE']), ['OPT-PARTICIPANT', 'NON-PARTICIPANT'], true),
				'resource' => isset($att['CUTYPE']) && in_array(strtoupper((string)$att['CUTYPE']), ['RESOURCE', 'ROOM'], true),
			];
		}
		return $out;
	}

	public static function addAttendee(VEvent $event, string $email, ?string $name, string $partstat, bool $optional, bool $resource): void {
		$params = [
			'PARTSTAT' => $partstat,
			'ROLE' => $optional ? 'OPT-PARTICIPANT' : 'REQ-PARTICIPANT',
		];
		if ($name !== null && $name !== '') {
			$params['CN'] = $name;
		}
		if ($resource) {
			$params['CUTYPE'] = 'RESOURCE';
		}
		$event->add('ATTENDEE', 'mailto:' . $email, $params);
	}

	public static function setOrganizer(VEvent $event, ?string $email, ?string $name): void {
		unset($event->ORGANIZER);
		if ($email === null || $email === '') {
			return;
		}
		$params = [];
		if ($name !== null && $name !== '') {
			$params['CN'] = $name;
		}
		$event->add('ORGANIZER', 'mailto:' . $email, $params);
	}

	/** Plain text from an HTML body (Graph sends HTML). */
	public static function htmlToText(string $html): string {
		$html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
		$html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
		$html = preg_replace('#</(p|div|li|tr|h[1-6])>#i', "\n", $html) ?? $html;
		$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = str_replace("\u{00A0}", ' ', $text);
		$text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
		return trim($text);
	}
}
