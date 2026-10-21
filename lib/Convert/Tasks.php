<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Convert;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;

/**
 * Shared VTODO helpers for the Google Tasks and Microsoft To Do converters. Due and start dates are
 * whole days in both services, so they are written as DATE values.
 */
final class Tasks {
	public static function newCalendar(): VCalendar {
		return ICal::newCalendar();
	}

	public static function todoOf(VCalendar $cal): ?Component {
		$todo = $cal->select('VTODO')[0] ?? null;
		return $todo instanceof Component ? $todo : null;
	}

	public static function text(Component $todo, string $name): ?string {
		$value = isset($todo->{$name}) ? trim((string)$todo->{$name}) : '';
		return $value === '' ? null : $value;
	}

	public static function setText(Component $todo, string $name, ?string $value): void {
		unset($todo->{$name});
		if ($value !== null && trim($value) !== '') {
			$todo->add($name, $value);
		}
	}

	/** A DATE property from a provider's date or date-time (its day, as written). */
	public static function setDay(Component $todo, string $name, ?string $value): void {
		unset($todo->{$name});
		if ($value !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
			$todo->add($name, "$m[1]$m[2]$m[3]", ['VALUE' => 'DATE']);
		}
	}

	/** The day of a DATE or DATE-TIME property, as Y-m-d. */
	public static function day(Component $todo, string $name): ?string {
		if (!isset($todo->{$name})) {
			return null;
		}
		/** @var DateTimeProperty $prop */
		$prop = $todo->{$name};
		return $prop->getDateTime(new DateTimeZone('UTC'))->format('Y-m-d');
	}

	public static function setInstant(Component $todo, string $name, ?string $value): void {
		unset($todo->{$name});
		if ($value !== null && $value !== '') {
			$todo->add($name, (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC')));
		}
	}

	public static function instant(Component $todo, string $name): ?DateTimeInterface {
		if (!isset($todo->{$name})) {
			return null;
		}
		/** @var DateTimeProperty $prop */
		$prop = $todo->{$name};
		return $prop->getDateTime(new DateTimeZone('UTC'));
	}

	public static function isCompleted(Component $todo): bool {
		return strtoupper(self::text($todo, 'STATUS') ?? '') === 'COMPLETED' || isset($todo->COMPLETED);
	}

	/** Marks a task done (with when) or not done. */
	public static function setCompleted(Component $todo, bool $done, ?string $when = null): void {
		unset($todo->COMPLETED, $todo->{'PERCENT-COMPLETE'});
		if ($done) {
			$todo->STATUS = 'COMPLETED';
			$todo->add('COMPLETED', (new DateTimeImmutable($when ?? 'now'))->setTimezone(new DateTimeZone('UTC')));
			$todo->add('PERCENT-COMPLETE', 100);
		}
	}

	/** The first absolute reminder (VALARM with a DATE-TIME trigger), in UTC. */
	public static function reminder(Component $todo): ?DateTimeImmutable {
		foreach ($todo->select('VALARM') as $alarm) {
			if (!isset($alarm->TRIGGER)) {
				continue;
			}
			$trigger = $alarm->TRIGGER;
			if (isset($trigger['VALUE']) && strtoupper((string)$trigger['VALUE']) === 'DATE-TIME') {
				/** @var DateTimeProperty $trigger */
				return DateTimeImmutable::createFromInterface($trigger->getDateTime(new DateTimeZone('UTC')));
			}
		}
		return null;
	}

	public static function setReminder(Component $todo, ?DateTimeInterface $at): void {
		foreach ($todo->select('VALARM') as $alarm) {
			$todo->remove($alarm);
		}
		if ($at === null) {
			return;
		}
		$utc = DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'));
		$alarm = $todo->add('VALARM', [
			'ACTION' => 'DISPLAY',
			'DESCRIPTION' => self::text($todo, 'SUMMARY') ?? 'Reminder',
		]);
		$alarm->add('TRIGGER', $utc->format('Ymd\THis\Z'), ['VALUE' => 'DATE-TIME']);
	}

	public static function stamp(Component $todo, ?string $modified, ?string $created): void {
		$utc = new DateTimeZone('UTC');
		$at = $modified !== null && $modified !== '' ? new DateTimeImmutable($modified) : new DateTimeImmutable('now');
		$todo->DTSTAMP = $at->setTimezone($utc);
		$todo->{'LAST-MODIFIED'} = $at->setTimezone($utc);
		if ($created !== null && $created !== '') {
			$todo->CREATED = (new DateTimeImmutable($created))->setTimezone($utc);
		}
	}
}
