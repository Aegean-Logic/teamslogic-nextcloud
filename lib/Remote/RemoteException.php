<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Remote;

use RuntimeException;

/**
 * A Google or Graph request that failed. `$status` is the HTTP status (0: no response).
 */
class RemoteException extends RuntimeException {
	public function __construct(string $message, public readonly int $status = 0, public readonly string $body = '', ?\Throwable $previous = null) {
		parent::__construct($message, $status, $previous);
	}

	/** Worth retrying next round (rate limit, server trouble, network). */
	public function isTransient(): bool {
		return $this->status === 0 || $this->status === 429 || $this->status >= 500;
	}
}
