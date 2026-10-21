<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Convert;

use RuntimeException;

/**
 * Something the other side can't represent (a recurrence rule Outlook has no pattern for, say).
 * The item is left out of the sync and the reason logged.
 */
class UnsupportedException extends RuntimeException {
}
