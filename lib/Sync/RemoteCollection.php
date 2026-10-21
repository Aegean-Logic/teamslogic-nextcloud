<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

/**
 * A remote calendar or address book.
 */
final class RemoteCollection {
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly bool $readOnly = false,
		public readonly ?string $color = null,
		/** The calendar's time zone (Google), for times sent without one. */
		public readonly ?string $timeZone = null,
	) {
	}
}
