<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Tests\Unit\Convert;

use DateTimeImmutable;
use DateTimeZone;
use OCA\TeamsLogic\Convert\GoogleEventConverter;
use OCA\TeamsLogic\Convert\ICal;
use OCA\TeamsLogic\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

class GoogleEventConverterTest extends TestCase {
	private GoogleEventConverter $converter;

	protected function setUp(): void {
		$this->converter = new GoogleEventConverter(new DateTimeZone('Europe/London'));
	}

	public function testSingleEventToICalendar(): void {
		$cal = $this->converter->toCalendar(Fixtures::json('google/event-single.json'));
		$this->assertCount(1, $cal->select('VEVENT'));
		$event = $cal->VEVENT;

		$this->assertSame('7l4bq0abc123single@google.com', (string)$event->UID);
		$this->assertSame('Quarterly planning', (string)$event->SUMMARY);
		$this->assertSame('Board room', (string)$event->LOCATION);
		$this->assertSame('Europe/London', (string)$event->DTSTART['TZID']);
		$this->assertSame('20261021T090000', (string)$event->DTSTART);
		$this->assertSame('20261021T103000', (string)$event->DTEND);
		$this->assertSame('PRIVATE', (string)$event->CLASS);
		$this->assertSame('OPAQUE', (string)$event->TRANSP);
		$this->assertSame('2', (string)$event->SEQUENCE);
		$this->assertSame('mailto:gaz@example.com', (string)$event->ORGANIZER);
		$this->assertSame('Gaz Cripps', (string)$event->ORGANIZER['CN']);

		$attendees = ICal::attendees($event);
		$this->assertCount(4, $attendees);
		$this->assertSame(['email' => 'alice@example.org', 'name' => 'Alice Smith', 'partstat' => 'TENTATIVE', 'optional' => false, 'resource' => false], $attendees[1]);
		$this->assertTrue($attendees[2]['optional']);
		$this->assertSame('NEEDS-ACTION', $attendees[2]['partstat']);
		$this->assertTrue($attendees[3]['resource']);

		$this->assertSame([['minutes' => 10, 'email' => false], ['minutes' => 1440, 'email' => true]], ICal::reminders($event));
		$this->assertStringContainsString(ICal::JOIN_PREFIX . 'https://meet.google.com/abc-defg-hij', (string)$event->DESCRIPTION);
		$this->assertStringStartsWith("Agenda:\n1. Numbers", (string)$event->DESCRIPTION);
	}

	public function testSingleEventRoundTrip(): void {
		$cal = $this->converter->toCalendar(Fixtures::json('google/event-single.json'));
		$body = $this->converter->fromEvent($cal->VEVENT);

		$this->assertSame('Quarterly planning', $body['summary']);
		$this->assertSame(['dateTime' => '2026-10-21T09:00:00', 'timeZone' => 'Europe/London'], $body['start']);
		$this->assertSame(['dateTime' => '2026-10-21T10:30:00', 'timeZone' => 'Europe/London'], $body['end']);
		$this->assertSame('private', $body['visibility']);
		$this->assertSame('opaque', $body['transparency']);
		$this->assertSame('5', $body['colorId']);
		$this->assertArrayNotHasKey('recurrence', $body);
		$this->assertSame(['useDefault' => false, 'overrides' => [
			['method' => 'popup', 'minutes' => 10],
			['method' => 'email', 'minutes' => 1440],
		]], $body['reminders']);
		$this->assertSame(['email' => 'alice@example.org', 'displayName' => 'Alice Smith', 'responseStatus' => 'tentative'], $body['attendees'][1]);
		$this->assertSame(['email' => 'bob@example.org', 'responseStatus' => 'needsAction', 'optional' => true], $body['attendees'][2]);
		$this->assertTrue($body['attendees'][3]['resource']);

		// Reading what we'd send gives the same event back.
		$again = $this->converter->toCalendar($body + ['id' => 'x', 'iCalUID' => '7l4bq0abc123single@google.com']);
		$this->assertSame((string)$cal->VEVENT->DTSTART, (string)$again->VEVENT->DTSTART);
		$this->assertSame((string)$cal->VEVENT->DESCRIPTION, (string)$again->VEVENT->DESCRIPTION);
	}

	public function testAllDayEvent(): void {
		$cal = $this->converter->toCalendar(Fixtures::json('google/event-allday.json'));
		$event = $cal->VEVENT;
		$this->assertSame('DATE', (string)$event->DTSTART['VALUE']);
		$this->assertSame('20261102', (string)$event->DTSTART);
		$this->assertSame('20261104', (string)$event->DTEND);
		$this->assertSame('TRANSPARENT', (string)$event->TRANSP);
		$this->assertSame('TRUE', (string)$event->{GoogleEventConverter::DEFAULT_REMINDERS});
		$this->assertCount(0, $event->select('VALARM'));

		$body = $this->converter->fromEvent($event);
		$this->assertSame(['date' => '2026-11-02'], $body['start']);
		$this->assertSame(['date' => '2026-11-04'], $body['end']);
		$this->assertSame(['useDefault' => true], $body['reminders']);
		$this->assertSame('transparent', $body['transparency']);
	}

	public function testSeriesWithChangedAndCancelledInstances(): void {
		$items = Fixtures::json('google/events-series.json')['items'];
		$cal = $this->converter->toCalendar($items[0], [$items[1], $items[2]]);

		$master = ICal::masterOf($cal);
		$this->assertNotNull($master);
		$this->assertSame('FREQ=WEEKLY;WKST=MO;UNTIL=20261221T235959Z;BYDAY=MO,WE,FR', (string)$master->RRULE);
		$exdates = [];
		foreach ($master->select('EXDATE') as $exdate) {
			foreach ($exdate->getDateTimes() as $dt) {
				$exdates[] = $dt->format('Y-m-d H:i e');
			}
		}
		$this->assertSame(['2026-10-12 09:30 Europe/London', '2026-10-16 09:30 Europe/London'], $exdates);

		$exceptions = ICal::exceptionsOf($cal);
		$this->assertCount(1, $exceptions);
		$this->assertSame('weekly1standup@google.com', (string)$exceptions[0]->UID);
		$this->assertSame('20261014T093000', (string)$exceptions[0]->{'RECURRENCE-ID'});
		$this->assertSame('Europe/London', (string)$exceptions[0]->{'RECURRENCE-ID'}['TZID']);
		$this->assertSame('20261014T100000', (string)$exceptions[0]->DTSTART);
		$this->assertSame('Stand-up (longer: demo)', (string)$exceptions[0]->SUMMARY);
		$this->assertFalse(isset($exceptions[0]->RRULE));

		$body = $this->converter->fromEvent($master);
		$this->assertSame([
			'RRULE:FREQ=WEEKLY;WKST=MO;UNTIL=20261221T235959Z;BYDAY=MO,WE,FR',
			'EXDATE;TZID=Europe/London:20261012T093000',
			'EXDATE;TZID=Europe/London:20261016T093000',
		], $body['recurrence']);
	}

	public function testInstanceChangeKeepsTheRestOfTheSeries(): void {
		$items = Fixtures::json('google/events-series.json')['items'];
		$existing = $this->converter->toCalendar($items[0], [$items[1]]);

		// Only the 14 October instance changed again: the master isn't in the change set.
		$changed = $items[1];
		$changed['summary'] = 'Stand-up (demo moved)';
		$cal = $this->converter->toCalendar(null, [$changed], $existing);

		$this->assertSame('Stand-up', (string)ICal::masterOf($cal)->SUMMARY);
		$exceptions = ICal::exceptionsOf($cal);
		$this->assertCount(1, $exceptions);
		$this->assertSame('Stand-up (demo moved)', (string)$exceptions[0]->SUMMARY);

		// Cancelling that instance drops its exception and adds an EXDATE.
		$cancelled = ['id' => $changed['id'], 'status' => 'cancelled', 'recurringEventId' => 'weekly1standup', 'originalStartTime' => $changed['originalStartTime']];
		$cal = $this->converter->toCalendar(null, [$cancelled], $cal);
		$this->assertCount(0, ICal::exceptionsOf($cal));
		$this->assertCount(2, ICal::masterOf($cal)->select('EXDATE'));
	}

	public function testInstanceId(): void {
		$at = new DateTimeImmutable('2026-10-14 09:30', new DateTimeZone('Europe/London'));
		$this->assertSame('weekly1standup_20261014T083000Z', GoogleEventConverter::instanceId('weekly1standup', $at, false));
		$this->assertSame('allday_20261014', GoogleEventConverter::instanceId('allday', $at, true));
	}

	public function testLocalEventWithoutTimeZoneIsSentInUtc(): void {
		$cal = ICal::parse("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:local-1\r\nDTSTART:20261021T080000Z\r\nDURATION:PT45M\r\nSUMMARY:Call\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT1H\r\nEND:VALARM\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
		$body = $this->converter->fromEvent($cal->VEVENT);
		$this->assertSame(['dateTime' => '2026-10-21T08:00:00Z', 'timeZone' => 'UTC'], $body['start']);
		$this->assertSame(['dateTime' => '2026-10-21T08:45:00Z', 'timeZone' => 'UTC'], $body['end']);
		$this->assertSame([['method' => 'popup', 'minutes' => 60]], $body['reminders']['overrides']);
	}
}
