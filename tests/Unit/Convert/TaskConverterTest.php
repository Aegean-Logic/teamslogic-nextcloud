<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Tests\Unit\Convert;

use OCA\TeamsLogic\Convert\GoogleTaskConverter;
use OCA\TeamsLogic\Convert\GraphTaskConverter;
use OCA\TeamsLogic\Convert\ICal;
use OCA\TeamsLogic\Convert\Tasks;
use OCA\TeamsLogic\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

class TaskConverterTest extends TestCase {
	public function testGoogleTaskToVTodo(): void {
		$cal = (new GoogleTaskConverter())->toCalendar(Fixtures::json('google/task.json'), 'uid-1', 'uid-parent');
		$todo = Tasks::todoOf($cal);
		$this->assertNotNull($todo);
		$this->assertSame('uid-1', (string)$todo->UID);
		$this->assertSame('Send the quarterly figures', (string)$todo->SUMMARY);
		$this->assertSame("Include the hiring plan.\nCopy finance.", (string)$todo->DESCRIPTION);
		$this->assertSame('20261024', (string)$todo->DUE);
		$this->assertSame('DATE', (string)$todo->DUE['VALUE']);
		$this->assertSame('COMPLETED', (string)$todo->STATUS);
		$this->assertSame('20261021T093000Z', (string)$todo->COMPLETED);
		$this->assertSame('100', (string)$todo->{'PERCENT-COMPLETE'});
		$this->assertSame('uid-parent', (string)$todo->{'RELATED-TO'});
	}

	public function testGoogleRoundTrip(): void {
		$converter = new GoogleTaskConverter();
		$body = $converter->fromCalendar($converter->toCalendar(Fixtures::json('google/task.json'), 'uid-1'));
		$this->assertSame([
			'title' => 'Send the quarterly figures',
			'notes' => "Include the hiring plan.\nCopy finance.",
			'due' => '2026-10-24T00:00:00.000Z',
			'status' => 'completed',
			'completed' => '2026-10-21T09:30:00.000Z',
		], $body);

		// Reopened locally: not done, no completion time
		$cal = ICal::parse("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:x\r\nSUMMARY:Call back\r\nSTATUS:NEEDS-ACTION\r\nEND:VTODO\r\nEND:VCALENDAR\r\n");
		$this->assertSame(['title' => 'Call back', 'notes' => '', 'due' => null, 'status' => 'needsAction', 'completed' => null], $converter->fromCalendar($cal));
	}

	public function testGraphTaskToVTodo(): void {
		$cal = (new GraphTaskConverter())->toCalendar(Fixtures::json('graph/task.json'), 'uid-2');
		$todo = Tasks::todoOf($cal);
		$this->assertNotNull($todo);
		$this->assertSame('Book the venue', (string)$todo->SUMMARY);
		$this->assertSame('Ask about catering & parking.', (string)$todo->DESCRIPTION);
		$this->assertSame('20261030', (string)$todo->DUE);
		$this->assertSame('20261022', (string)$todo->DTSTART);
		$this->assertSame('NEEDS-ACTION', (string)$todo->STATUS);
		$this->assertSame('waitingOnOthers', (string)$todo->{GraphTaskConverter::STATUS});
		$this->assertSame('1', (string)$todo->PRIORITY);
		$this->assertSame('Events', (string)$todo->CATEGORIES);
		$this->assertSame('2026-10-29 09:00', Tasks::reminder($todo)?->format('Y-m-d H:i'));
		$this->assertFalse(isset($todo->COMPLETED));

		// The serialized task reads back the same (the reminder survives as a DATE-TIME trigger)
		$again = Tasks::todoOf(ICal::parse($cal->serialize()));
		$this->assertSame('2026-10-29 09:00', Tasks::reminder($again)?->format('Y-m-d H:i'));
	}

	public function testGraphRoundTrip(): void {
		$converter = new GraphTaskConverter();
		$body = $converter->fromCalendar($converter->toCalendar(Fixtures::json('graph/task.json'), 'uid-2'));
		$this->assertSame('Book the venue', $body['title']);
		$this->assertSame(['contentType' => 'text', 'content' => 'Ask about catering & parking.'], $body['body']);
		$this->assertSame('waitingOnOthers', $body['status']);
		$this->assertSame('high', $body['importance']);
		$this->assertSame(['dateTime' => '2026-10-30T00:00:00', 'timeZone' => 'UTC'], $body['dueDateTime']);
		$this->assertSame(['dateTime' => '2026-10-22T00:00:00', 'timeZone' => 'UTC'], $body['startDateTime']);
		$this->assertSame(['Events'], $body['categories']);
		$this->assertTrue($body['isReminderOn']);
		$this->assertSame(['dateTime' => '2026-10-29T09:00:00', 'timeZone' => 'UTC'], $body['reminderDateTime']);
	}

	public function testLocalTaskToGraph(): void {
		$cal = ICal::parse("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:local\r\nSUMMARY:Renew insurance\r\nDUE;VALUE=DATE:20261101\r\n"
			. "PRIORITY:9\r\nSTATUS:COMPLETED\r\nCOMPLETED:20261021T120000Z\r\nEND:VTODO\r\nEND:VCALENDAR\r\n");
		$body = (new GraphTaskConverter())->fromCalendar($cal);
		$this->assertSame('completed', $body['status']);
		$this->assertSame('low', $body['importance']);
		$this->assertSame(['dateTime' => '2026-11-01T00:00:00', 'timeZone' => 'UTC'], $body['dueDateTime']);
		$this->assertSame(['dateTime' => '2026-10-21T12:00:00', 'timeZone' => 'UTC'], $body['completedDateTime']);
		$this->assertNull($body['startDateTime']);
		$this->assertFalse($body['isReminderOn']);
	}
}
