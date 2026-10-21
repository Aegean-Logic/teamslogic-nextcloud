<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Tests\Unit\Convert;

use DateTimeImmutable;
use DateTimeZone;
use OCA\TeamsLogic\Convert\GraphRecurrence;
use OCA\TeamsLogic\Convert\UnsupportedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GraphRecurrenceTest extends TestCase {
	public static function patterns(): array {
		$noEnd = ['type' => 'noEnd', 'startDate' => '2026-10-05', 'recurrenceTimeZone' => 'GMT Standard Time'];
		return [
			'daily every 2 days, 10 times' => [
				['pattern' => ['interval' => 2, 'type' => 'daily'], 'range' => ['type' => 'numbered', 'startDate' => '2026-10-05', 'recurrenceTimeZone' => 'GMT Standard Time', 'numberOfOccurrences' => 10]],
				'FREQ=DAILY;INTERVAL=2;COUNT=10',
			],
			'fortnightly on Tuesday and Thursday' => [
				['pattern' => ['interval' => 2, 'type' => 'weekly', 'daysOfWeek' => ['tuesday', 'thursday'], 'firstDayOfWeek' => 'sunday'], 'range' => $noEnd],
				'FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,TH;WKST=SU',
			],
			'monthly on the 15th' => [
				['pattern' => ['interval' => 1, 'type' => 'absoluteMonthly', 'dayOfMonth' => 15], 'range' => $noEnd],
				'FREQ=MONTHLY;BYMONTHDAY=15',
			],
			'second Tuesday of every month' => [
				['pattern' => ['interval' => 1, 'type' => 'relativeMonthly', 'daysOfWeek' => ['tuesday'], 'index' => 'second'], 'range' => $noEnd],
				'FREQ=MONTHLY;BYDAY=2TU',
			],
			'last weekday of every 3 months' => [
				['pattern' => ['interval' => 3, 'type' => 'relativeMonthly', 'daysOfWeek' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'index' => 'last'], 'range' => $noEnd],
				'FREQ=MONTHLY;INTERVAL=3;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1',
			],
			'every 17 March' => [
				['pattern' => ['interval' => 1, 'type' => 'absoluteYearly', 'month' => 3, 'dayOfMonth' => 17], 'range' => $noEnd],
				'FREQ=YEARLY;BYMONTH=3;BYMONTHDAY=17',
			],
			'last Friday of November' => [
				['pattern' => ['interval' => 1, 'type' => 'relativeYearly', 'month' => 11, 'daysOfWeek' => ['friday'], 'index' => 'last'], 'range' => $noEnd],
				'FREQ=YEARLY;BYMONTH=11;BYDAY=-1FR',
			],
		];
	}

	#[DataProvider('patterns')]
	public function testPatternToRrule(array $recurrence, string $rrule): void {
		$this->assertSame($rrule, GraphRecurrence::toRrule($recurrence, false, new DateTimeZone('Europe/London')));
	}

	#[DataProvider('patterns')]
	public function testRruleBackToTheSamePattern(array $recurrence, string $rrule): void {
		$start = new DateTimeImmutable('2026-10-05 09:30', new DateTimeZone('Europe/London'));
		$back = GraphRecurrence::fromRrule($rrule, $start, false);
		$this->assertSame($recurrence['pattern']['type'], $back['pattern']['type']);
		$this->assertSame($recurrence['pattern']['interval'], $back['pattern']['interval']);
		foreach (['daysOfWeek', 'dayOfMonth', 'month', 'index', 'firstDayOfWeek'] as $key) {
			if (isset($recurrence['pattern'][$key])) {
				$this->assertSame($recurrence['pattern'][$key], $back['pattern'][$key], $key);
			}
		}
		$this->assertSame($recurrence['range']['type'], $back['range']['type']);
		$this->assertSame('GMT Standard Time', $back['range']['recurrenceTimeZone']);
		$this->assertSame('2026-10-05', $back['range']['startDate']);
	}

	public function testEndDateIsInclusiveAndLocal(): void {
		$recurrence = ['pattern' => ['interval' => 1, 'type' => 'daily'], 'range' => ['type' => 'endDate', 'startDate' => '2026-10-05', 'endDate' => '2026-10-20']];
		// The end of 20 October in London (BST) is 22:59:59 UTC.
		$this->assertSame('FREQ=DAILY;UNTIL=20261020T225959Z', GraphRecurrence::toRrule($recurrence, false, new DateTimeZone('Europe/London')));
		$this->assertSame('FREQ=DAILY;UNTIL=20261020', GraphRecurrence::toRrule($recurrence, true, new DateTimeZone('Europe/London')));
		// After the clocks go back (25 October), London is UTC again.
		$recurrence['range']['endDate'] = '2026-10-30';
		$this->assertSame('FREQ=DAILY;UNTIL=20261030T235959Z', GraphRecurrence::toRrule($recurrence, false, new DateTimeZone('Europe/London')));

		$start = new DateTimeImmutable('2026-10-05 09:30', new DateTimeZone('Europe/London'));
		$this->assertSame('2026-10-20', GraphRecurrence::fromRrule('FREQ=DAILY;UNTIL=20261020T225959Z', $start, false)['range']['endDate']);
		$this->assertSame('2026-10-20', GraphRecurrence::fromRrule('FREQ=DAILY;UNTIL=20261020', $start, true)['range']['endDate']);
	}

	public function testDefaultsComeFromTheStart(): void {
		$start = new DateTimeImmutable('2026-10-07 09:30', new DateTimeZone('Europe/London'));
		$this->assertSame(['wednesday'], GraphRecurrence::fromRrule('FREQ=WEEKLY', $start, false)['pattern']['daysOfWeek']);
		$this->assertSame(7, GraphRecurrence::fromRrule('FREQ=MONTHLY', $start, false)['pattern']['dayOfMonth']);
		$yearly = GraphRecurrence::fromRrule('FREQ=YEARLY', $start, false)['pattern'];
		$this->assertSame([10, 7], [$yearly['month'], $yearly['dayOfMonth']]);
	}

	public function testWeekdaysDailyIsWeekly(): void {
		$start = new DateTimeImmutable('2026-10-05 09:30', new DateTimeZone('Europe/London'));
		$pattern = GraphRecurrence::fromRrule('FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR', $start, false)['pattern'];
		$this->assertSame('weekly', $pattern['type']);
		$this->assertSame(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], $pattern['daysOfWeek']);
	}

	public static function unsupported(): array {
		return [
			['FREQ=HOURLY'],
			['FREQ=DAILY;BYHOUR=9,17'],
			['FREQ=MONTHLY;BYMONTHDAY=1,15'],
			['FREQ=MONTHLY;BYMONTHDAY=-1'],
			['FREQ=MONTHLY;BYDAY=1MO,3MO'],
			['FREQ=YEARLY;BYMONTH=1,7'],
			['FREQ=WEEKLY;BYDAY=1MO'],
		];
	}

	#[DataProvider('unsupported')]
	public function testUnsupportedRules(string $rrule): void {
		$this->expectException(UnsupportedException::class);
		GraphRecurrence::fromRrule($rrule, new DateTimeImmutable('2026-10-05 09:30', new DateTimeZone('UTC')), false);
	}
}
