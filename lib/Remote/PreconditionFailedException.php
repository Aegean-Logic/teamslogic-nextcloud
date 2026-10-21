<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Remote;

/**
 * 412 Precondition Failed (or Google People's FAILED_PRECONDITION): the remote item changed
 * since we last read it. The sync re-reads it and the remote version wins.
 */
class PreconditionFailedException extends RemoteException {
}
