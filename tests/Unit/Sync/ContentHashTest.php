<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Tests\Unit\Sync;

use OCA\TeamsLogic\Sync\ContentHash;
use PHPUnit\Framework\TestCase;

class ContentHashTest extends TestCase {
	private static function event(string $summary, string $stamp, string $modified, int $sequence = 0): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//$stamp//EN\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTAMP:$stamp\r\n"
			. "LAST-MODIFIED:$modified\r\nSEQUENCE:$sequence\r\nDTSTART:20261022T090000Z\r\nSUMMARY:$summary\r\n"
			. "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT10M\r\nEND:VALARM\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
	}

	public function testStampsDoNotCount(): void {
		$this->assertSame(
			ContentHash::of(self::event('Call', '20261021T100000Z', '20261021T100000Z')),
			ContentHash::of(self::event('Call', '20261021T120000Z', '20261021T120500Z', 3)),
		);
	}

	public function testContentDoes(): void {
		$this->assertNotSame(
			ContentHash::of(self::event('Call', '20261021T100000Z', '20261021T100000Z')),
			ContentHash::of(self::event('Call (moved)', '20261021T100000Z', '20261021T100000Z')),
		);
	}

	public function testVCardRevisionDoesNotCount(): void {
		$card = fn (string $rev, string $fn) => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:x\r\nREV:$rev\r\nFN:$fn\r\nEND:VCARD\r\n";
		$this->assertSame(ContentHash::of($card('20261021T100000Z', 'Sam')), ContentHash::of($card('20261021T120000Z', 'Sam')));
		$this->assertNotSame(ContentHash::of($card('20261021T100000Z', 'Sam')), ContentHash::of($card('20261021T100000Z', 'Samuel')));
	}
}
