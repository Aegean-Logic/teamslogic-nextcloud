<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

use Sabre\VObject\Component;
use Sabre\VObject\Reader;
use Throwable;

/**
 * A hash of what an event or contact says, ignoring the stamps that change on every write
 * (DTSTAMP, LAST-MODIFIED, CREATED, SEQUENCE, REV, PRODID).
 *
 * Providers change an item's etag without changing the item (Outlook does it a few seconds after
 * an event is created). Comparing this hash of the remote item with the one from the last round
 * tells a real remote edit from a new etag on the same content.
 */
final class ContentHash {
	private const STAMPS = ['PRODID', 'DTSTAMP', 'LAST-MODIFIED', 'CREATED', 'SEQUENCE', 'REV'];

	public static function of(string $text): string {
		try {
			$doc = Reader::read($text, Reader::OPTION_FORGIVING);
			self::strip($doc);
			$text = $doc->serialize();
		} catch (Throwable) {
			// Unreadable: hash it as it is.
		}
		return hash('sha256', $text);
	}

	private static function strip(Component $component): void {
		foreach (self::STAMPS as $name) {
			unset($component->{$name});
		}
		foreach ($component->children() as $child) {
			if ($child instanceof Component) {
				self::strip($child);
			}
		}
	}
}
