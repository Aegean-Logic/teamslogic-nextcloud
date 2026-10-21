<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Remote;

/**
 * The remote sync token (Google syncToken, Graph deltaLink) is no longer valid: list everything.
 */
class SyncTokenExpiredException extends RemoteException {
}
