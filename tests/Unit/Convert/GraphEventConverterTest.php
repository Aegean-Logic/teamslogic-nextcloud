<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Tests\Unit\Convert;

use OCA\TeamsLogic\Convert\GraphEventConverter;
use OCA\TeamsLogic\Convert\ICal;
use OCA\TeamsLogic\Convert\UnsupportedException;
use OCA\TeamsLogic\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

class GraphEventConverterTest extends TestCase {
	private GraphEventConverter $converter;

	protected function setUp(): void {
		$this->converter = new GraphEventConverter();
	}

	public function testSingleEventIsWrittenInTheOrganisersZone(): void {
		$cal = $this->converter->toCalendar(Fixtures::json('graph/event-single.json'));
		$event = $cal->VEVENT;

		$this->assertSame('040000008200E00074C5B7101A82E00800000000A1B2C3D4E5F6D9010000000000000000100000001', (string)$event->UID);
		// 08:00 UTC on 21 October is 09:00 British Summer Time.
		$this->assertSame('Europe/London', (string)$event->DTSTART['TZID']);
		$this->assertSame('20261021T090000', (string)$event->DTSTART);
		$this->assertSame('20261021T100000', (string)$event->DTEND);
		$this->assertSame('Supplier review', (string)$event->SUMMARY);
		$this->assertSame('TENTATIVE', (string)$event->{GraphEventConverter::BUSY_STATUS});
		$this->assertSame('OPAQUE', (string)$event->TRANSP);
		$this->assertSame('PUBLIC', (string)$event->CLASS);
		$this->assertSame('Red category', (string)$event->CATEGORIES);

		$description = (string)$event->DESCRIPTION;
		$this->assertStringStartsWith("Please read the attached figures before the call.\n\nThanks & regards", $description);
		$this->assertStringContainsString(ICal::JOIN_PREFIX . 'https://teams.microsoft.com/l/meetup-join/', $description);
		// No room: the join link is the location.
		$this->assertStringStartsWith('https://teams.microsoft.com/', (string)$event->LOCATION);

		$this->assertSame('mailto:gaz@contoso.com', (string)$event->ORGANIZER);
		$attendees = ICal::attendees($event);
		$this->assertSame(['ACCEPTED', 'TENTATIVE', 'NEEDS-ACTION'], array_column($attendees, 'partstat'));
		$this->assertSame([false, true, false], array_column($attendees, 'optional'));
		$this->assertSame([['minutes' => 15, 'email' => false]], ICal::reminders($event));
	}

	public function testSingleEventBackToGraph(): void {
		$cal = $this->converter->toCalendar(Fixtures::json('graph/event-single.json'));
		$body = $this->converter->fromEvent($cal->VEVENT);

		$this->assertSame(['dateTime' => '2026-10-21T09:00:00', 'timeZone' => 'GMT Standard Time'], $body['start']);
		$this->assertSame(['dateTime' => '2026-10-21T10:00:00', 'timeZone' => 'GMT Standard Time'], $body['end']);
		$this->assertFalse($body['isAllDay']);
		$this->assertSame('tentative', $body['showAs']);
		$this->assertSame('normal', $body['sensitivity']);
		$this->assertSame(['Red category'], $body['categories']);
		$this->assertTrue($body['isReminderOn']);
		$this->assertSame(15, $body['reminderMinutesBeforeStart']);
		$this->assertSame('text', $body['body']['contentType']);
		$this->assertSame([
			['emailAddress' => ['address' => 'alice@contoso.com', 'name' => 'Alice Smith'], 'type' => 'required'],
			['emailAddress' => ['address' => 'bob@contoso.com', 'name' => 'Bob Jones'], 'type' => 'optional'],
			['emailAddress' => ['address' => 'carol@fabrikam.com', 'name' => 'Carol'], 'type' => 'required'],
		], $body['attendees']);
		$this->assertArrayNotHasKey('recurrence', $body);
	}

	public function testTeamsMeeting(): void {
		$event = $this->converter->toCalendar(Fixtures::json('graph/event-single.json'))->VEVENT;
		$this->assertSame('teams', (string)$event->{ICal::ONLINE_MEETING});
		$this->assertStringStartsWith('https://teams.microsoft.com/l/meetup-join/', (string)$event->{ICal::JOIN_URL});

		// Back to Outlook: the meeting stays a Teams meeting; our join line and location aren't sent.
		$body = $this->converter->fromEvent($event, 'teamsForBusiness');
		$this->assertTrue($body['isOnlineMeeting']);
		$this->assertSame('teamsForBusiness', $body['onlineMeetingProvider']);
		$this->assertSame("Please read the attached figures before the call.\n\nThanks & regards", $body['body']['content']);
		$this->assertSame('', $body['location']['displayName']);
	}

	public function testNewEventAsksForATeamsMeeting(): void {
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:new-teams\r\nDTSTART;TZID=Europe/London:20261022T090000\r\n"
			. "DTEND;TZID=Europe/London:20261022T093000\r\nSUMMARY:Catch-up\r\n" . ICal::ONLINE_MEETING . ":teams\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$event = ICal::parse($ics)->VEVENT;
		$this->assertSame(['isOnlineMeeting' => true, 'onlineMeetingProvider' => 'teamsForBusiness'],
			array_intersect_key($this->converter->fromEvent($event, 'teamsForBusiness'), ['isOnlineMeeting' => 1, 'onlineMeetingProvider' => 1]));
		// A calendar that can't make online meetings: an ordinary event.
		$this->assertArrayNotHasKey('isOnlineMeeting', $this->converter->fromEvent($event));
	}

	public function testAllDayEvent(): void {
		$cal = $this->converter->toCalendar(Fixtures::json('graph/event-allday.json'));
		$event = $cal->VEVENT;
		$this->assertSame('DATE', (string)$event->DTSTART['VALUE']);
		$this->assertSame('20261225', (string)$event->DTSTART);
		$this->assertSame('20261226', (string)$event->DTEND);
		$this->assertSame('TRANSPARENT', (string)$event->TRANSP);
		$this->assertSame('PRIVATE', (string)$event->CLASS);
		$this->assertCount(0, $event->select('VALARM'));

		$body = $this->converter->fromEvent($event);
		$this->assertTrue($body['isAllDay']);
		$this->assertSame(['dateTime' => '2026-12-25T00:00:00', 'timeZone' => 'UTC'], $body['start']);
		$this->assertSame(['dateTime' => '2026-12-26T00:00:00', 'timeZone' => 'UTC'], $body['end']);
		$this->assertSame('free', $body['showAs']);
		$this->assertSame('private', $body['sensitivity']);
		$this->assertFalse($body['isReminderOn']);
	}

	public function testSeriesWithExceptionAndCancellation(): void {
		$cal = $this->converter->toCalendar(Fixtures::json('graph/event-series.json'));
		$master = ICal::masterOf($cal);
		$this->assertNotNull($master);
		$this->assertSame('20261005T093000', (string)$master->DTSTART);
		$this->assertSame('Europe/London', (string)$master->DTSTART['TZID']);
		$this->assertSame('FREQ=WEEKLY;BYDAY=MO,WE,FR;WKST=MO;UNTIL=20261221T235959Z', (string)$master->RRULE);
		$this->assertSame('20261012T093000', (string)$master->EXDATE);
		$this->assertSame('Europe/London', (string)$master->EXDATE['TZID']);

		$exceptions = ICal::exceptionsOf($cal);
		$this->assertCount(1, $exceptions);
		$this->assertSame((string)$master->UID, (string)$exceptions[0]->UID);
		$this->assertSame('20261014T093000', (string)$exceptions[0]->{'RECURRENCE-ID'});
		$this->assertSame('20261014T100000', (string)$exceptions[0]->DTSTART);
		$this->assertSame('Stand-up (longer: demo)', (string)$exceptions[0]->SUMMARY);

		$body = $this->converter->fromEvent($master);
		$this->assertSame([
			'pattern' => ['interval' => 1, 'type' => 'weekly', 'daysOfWeek' => ['monday', 'wednesday', 'friday'], 'firstDayOfWeek' => 'monday'],
			'range' => ['type' => 'endDate', 'startDate' => '2026-10-05', 'recurrenceTimeZone' => 'GMT Standard Time', 'endDate' => '2026-12-21'],
		], $body['recurrence']);

		$exceptionBody = $this->converter->fromEvent($exceptions[0]);
		$this->assertArrayNotHasKey('recurrence', $exceptionBody);
		$this->assertSame(['dateTime' => '2026-10-14T10:00:00', 'timeZone' => 'GMT Standard Time'], $exceptionBody['start']);
	}

	public function testRuleOutlookCannotHoldIsRefused(): void {
		$cal = ICal::parse("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:hourly\r\nDTSTART:20261021T080000Z\r\nDTEND:20261021T081500Z\r\nRRULE:FREQ=HOURLY;COUNT=3\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
		$this->expectException(UnsupportedException::class);
		$this->converter->fromEvent($cal->VEVENT);
	}

	public function testZoneWithoutItsOwnWindowsNameKeepsItsOffsets(): void {
		$cal = ICal::parse("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:madrid\r\nDTSTART;TZID=Europe/Madrid:20261021T090000\r\nDTEND;TZID=Europe/Madrid:20261021T100000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
		$body = $this->converter->fromEvent($cal->VEVENT);
		$this->assertSame('2026-10-21T09:00:00', $body['start']['dateTime']);
		$this->assertContains($body['start']['timeZone'], ['Romance Standard Time', 'W. Europe Standard Time', 'Central Europe Standard Time', 'Central European Standard Time']);
	}
}
