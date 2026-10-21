<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

/**
 * A remote event series or contact as a change set reports it. `$data` is the provider's own
 * payload (null when only the id is known and it has to be fetched); `$etag` is null when the
 * change doesn't carry one (a Google instance changed, not its series).
 */
final class RemoteItem {
	public function __construct(
		public readonly string $id,
		public readonly ?string $etag,
		public readonly bool $deleted = false,
		public readonly mixed $data = null,
	) {
	}
}
