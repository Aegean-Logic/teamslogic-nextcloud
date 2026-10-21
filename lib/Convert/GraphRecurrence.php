<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Convert;

use DateTimeImmutable;
use DateTimeZone;

/**
 * RRULE ⇄ Microsoft Graph patternedRecurrence.
 *
 * Graph has six patterns (daily, weekly, absolute/relative monthly, absolute/relative yearly)
 * and three ranges (end date, count, no end). Rules beyond those (BYHOUR, several month days,
 * hourly…) throw UnsupportedException.
 */
final class GraphRecurrence {
	private const DAYS = [
		'SU' => 'sunday', 'MO' => 'monday', 'TU' => 'tuesday', 'WE' => 'wednesday',
		'TH' => 'thursday', 'FR' => 'friday', 'SA' => 'saturday',
	];
	private const INDEX = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'last' => -1];

	/**
	 * The RRULE value for a Graph recurrence.
	 *
	 * @param array<string, mixed> $recurrence {pattern, range}
	 * @param DateTimeZone $zone the series' zone, for an end date
	 */
	public static function toRrule(array $recurrence, bool $allDay, DateTimeZone $zone): string {
		$pattern = (array)($recurrence['pattern'] ?? []);
		$range = (array)($recurrence['range'] ?? []);
		$interval = max(1, (int)($pattern['interval'] ?? 1));
		$days = array_map(
			static fn ($d) => (string)array_search(strtolower((string)$d), self::DAYS, true),
			(array)($pattern['daysOfWeek'] ?? []),
		);
		$days = array_values(array_filter($days));
		$index = self::INDEX[strtolower((string)($pattern['index'] ?? 'first'))] ?? 1;

		$parts = [];
		switch ((string)($pattern['type'] ?? '')) {
			case 'daily':
				$parts['FREQ'] = 'DAILY';
				break;
			case 'weekly':
				$parts['FREQ'] = 'WEEKLY';
				if ($days !== []) {
					$parts['BYDAY'] = implode(',', $days);
				}
				if (!empty($pattern['firstDayOfWeek'])) {
					$wkst = array_search(strtolower((string)$pattern['firstDayOfWeek']), self::DAYS, true);
					if ($wkst !== false) {
						$parts['WKST'] = $wkst;
					}
				}
				break;
			case 'absoluteMonthly':
				$parts['FREQ'] = 'MONTHLY';
				$parts['BYMONTHDAY'] = (string)(int)$pattern['dayOfMonth'];
				break;
			case 'relativeMonthly':
				$parts['FREQ'] = 'MONTHLY';
				$parts += self::relativeDays($days, $index);
				break;
			case 'absoluteYearly':
				$parts['FREQ'] = 'YEARLY';
				$parts['BYMONTH'] = (string)(int)$pattern['month'];
				$parts['BYMONTHDAY'] = (string)(int)$pattern['dayOfMonth'];
				break;
			case 'relativeYearly':
				$parts['FREQ'] = 'YEARLY';
				$parts['BYMONTH'] = (string)(int)$pattern['month'];
				$parts += self::relativeDays($days, $index);
				break;
			default:
				throw new UnsupportedException('Unknown recurrence pattern ' . json_encode($pattern['type'] ?? null));
		}
		if ($interval > 1) {
			$parts = ['FREQ' => $parts['FREQ'], 'INTERVAL' => (string)$interval] + $parts;
		}

		switch ((string)($range['type'] ?? 'noEnd')) {
			case 'endDate':
				$end = (string)($range['endDate'] ?? '');
				if ($end !== '' && $end !== '0001-01-01') {
					if ($allDay) {
						$parts['UNTIL'] = str_replace('-', '', $end);
					} else {
						// Graph's end date is inclusive and local to the series.
						$until = new DateTimeImmutable($end . ' 23:59:59', $zone);
						$parts['UNTIL'] = $until->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
					}
				}
				break;
			case 'numbered':
				$parts['COUNT'] = (string)max(1, (int)($range['numberOfOccurrences'] ?? 1));
				break;
		}

		$out = [];
		foreach ($parts as $key => $value) {
			$out[] = $key . '=' . $value;
		}
		return implode(';', $out);
	}

	/**
	 * A Graph recurrence for an RRULE.
	 *
	 * @param DateTimeImmutable $start DTSTART, in the series' zone
	 * @return array<string, mixed>
	 */
	public static function fromRrule(string $rrule, DateTimeImmutable $start, bool $allDay): array {
		$rule = [];
		foreach (explode(';', strtoupper(trim(preg_replace('/^RRULE:/i', '', $rrule) ?? $rrule))) as $part) {
			if ($part === '') {
				continue;
			}
			[$key, $value] = array_pad(explode('=', $part, 2), 2, '');
			$rule[$key] = $value;
		}
		foreach (['BYSECOND', 'BYMINUTE', 'BYHOUR', 'BYWEEKNO', 'BYYEARDAY'] as $unsupported) {
			if (isset($rule[$unsupported])) {
				throw new UnsupportedException("Outlook has no recurrence pattern for $unsupported");
			}
		}
		$freq = $rule['FREQ'] ?? '';
		$interval = max(1, (int)($rule['INTERVAL'] ?? 1));
		$byDay = isset($rule['BYDAY']) ? explode(',', $rule['BYDAY']) : [];
		$setPos = $rule['BYSETPOS'] ?? null;
		if ($setPos !== null && str_contains($setPos, ',')) {
			throw new UnsupportedException('Outlook recurrences take one BYSETPOS');
		}
		if (isset($rule['BYMONTHDAY']) && (str_contains($rule['BYMONTHDAY'], ',') || (int)$rule['BYMONTHDAY'] < 1)) {
			throw new UnsupportedException('Outlook recurrences take one positive BYMONTHDAY');
		}
		if (isset($rule['BYMONTH']) && str_contains($rule['BYMONTH'], ',')) {
			throw new UnsupportedException('Outlook recurrences take one BYMONTH');
		}

		$pattern = ['interval' => $interval];
		switch ($freq) {
			case 'DAILY':
				if ($byDay === []) {
					$pattern['type'] = 'daily';
					break;
				}
				if ($interval !== 1) {
					throw new UnsupportedException('Outlook has no "every n days on weekdays" pattern');
				}
				// FREQ=DAILY;BYDAY=MO,…,FR is a weekly pattern on those days.
				$pattern['type'] = 'weekly';
				$pattern['daysOfWeek'] = self::plainDays($byDay);
				$pattern['firstDayOfWeek'] = 'monday';
				break;
			case 'WEEKLY':
				$pattern['type'] = 'weekly';
				$pattern['daysOfWeek'] = $byDay === []
					? [self::DAYS[strtoupper(substr($start->format('D'), 0, 2))]]
					: self::plainDays($byDay);
				$pattern['firstDayOfWeek'] = self::DAYS[$rule['WKST'] ?? 'MO'] ?? 'monday';
				break;
			case 'MONTHLY':
				if ($byDay !== []) {
					$pattern['type'] = 'relativeMonthly';
					$pattern += self::relative($byDay, $setPos);
				} else {
					$pattern['type'] = 'absoluteMonthly';
					$pattern['dayOfMonth'] = (int)($rule['BYMONTHDAY'] ?? $start->format('j'));
				}
				break;
			case 'YEARLY':
				$pattern['month'] = (int)($rule['BYMONTH'] ?? $start->format('n'));
				if ($byDay !== []) {
					$pattern['type'] = 'relativeYearly';
					$pattern += self::relative($byDay, $setPos);
				} else {
					$pattern['type'] = 'absoluteYearly';
					$pattern['dayOfMonth'] = (int)($rule['BYMONTHDAY'] ?? $start->format('j'));
				}
				break;
			default:
				throw new UnsupportedException("Outlook has no $freq recurrence");
		}

		$range = [
			'type' => 'noEnd',
			'startDate' => $start->format('Y-m-d'),
			'recurrenceTimeZone' => $allDay ? 'UTC' : TimeZones::toWindows($start->getTimezone()),
		];
		if (isset($rule['COUNT'])) {
			$range['type'] = 'numbered';
			$range['numberOfOccurrences'] = (int)$rule['COUNT'];
		} elseif (isset($rule['UNTIL'])) {
			$range['type'] = 'endDate';
			$range['endDate'] = self::untilDate($rule['UNTIL'], $start->getTimezone());
		}

		return ['pattern' => $pattern, 'range' => $range];
	}

	/** @return array<string, string> BYDAY (and BYSETPOS) for a relative pattern */
	private static function relativeDays(array $days, int $index): array {
		if (count($days) === 1) {
			return ['BYDAY' => $index . $days[0]];
		}
		return ['BYDAY' => implode(',', $days), 'BYSETPOS' => (string)$index];
	}

	/** @return list<string> */
	private static function plainDays(array $byDay): array {
		$out = [];
		foreach ($byDay as $day) {
			if (!preg_match('/^([+-]?\d+)?(SU|MO|TU|WE|TH|FR|SA)$/', $day, $m)) {
				throw new UnsupportedException("Unknown BYDAY $day");
			}
			if ($m[1] !== '') {
				throw new UnsupportedException('Outlook weekly patterns take plain weekdays');
			}
			$out[] = self::DAYS[$m[2]];
		}
		return $out;
	}

	/** @return array{daysOfWeek: list<string>, index: string} */
	private static function relative(array $byDay, ?string $setPos): array {
		$ordinals = [];
		$days = [];
		foreach ($byDay as $day) {
			if (!preg_match('/^([+-]?\d+)?(SU|MO|TU|WE|TH|FR|SA)$/', $day, $m)) {
				throw new UnsupportedException("Unknown BYDAY $day");
			}
			$days[] = self::DAYS[$m[2]];
			if ($m[1] !== '') {
				$ordinals[] = (int)$m[1];
			}
		}
		if ($setPos !== null) {
			$ordinals[] = (int)$setPos;
		}
		$ordinals = array_values(array_unique($ordinals));
		if (count($ordinals) !== 1) {
			throw new UnsupportedException('Outlook relative patterns take one week of the month');
		}
		$index = array_search($ordinals[0], self::INDEX, true);
		if ($index === false) {
			throw new UnsupportedException("Outlook has no week {$ordinals[0]} of the month");
		}
		return ['daysOfWeek' => $days, 'index' => $index];
	}

	private static function untilDate(string $until, DateTimeZone $zone): string {
		if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $until, $m)) {
			return "$m[1]-$m[2]-$m[3]";
		}
		$utc = str_ends_with($until, 'Z');
		$at = DateTimeImmutable::createFromFormat('Ymd\THis', rtrim($until, 'Z'), $utc ? new DateTimeZone('UTC') : $zone);
		if ($at === false) {
			throw new UnsupportedException("Unreadable UNTIL $until");
		}
		return $at->setTimezone($zone)->format('Y-m-d');
	}
}
