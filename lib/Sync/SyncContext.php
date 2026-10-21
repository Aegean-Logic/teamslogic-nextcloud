<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

use OCA\TeamsLogic\Db\Connection;
use OCA\TeamsLogic\Db\SyncItem;

/**
 * What a round for one collection works with; `$known` is kept up to date as items are saved.
 */
final class SyncContext {
	/**
	 * @param array<string, SyncItem> $known remote id => item
	 */
	public function __construct(
		public readonly Connection $connection,
		public readonly RemoteApi $api,
		public readonly LocalStore $store,
		public readonly RemoteCollection $collection,
		public readonly int $localId,
		public array $known,
		public readonly ChangeSet $remote,
	) {
	}
}
