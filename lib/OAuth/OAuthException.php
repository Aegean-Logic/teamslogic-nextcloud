<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\OAuth;

use RuntimeException;

/**
 * Signing in failed, or the grant was revoked (`$reconnect`: the user has to connect again).
 */
class OAuthException extends RuntimeException {
	public function __construct(string $message, public readonly bool $reconnect = false, ?\Throwable $previous = null) {
		parent::__construct($message, 0, $previous);
	}
}
