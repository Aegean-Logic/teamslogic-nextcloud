<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

use OCA\TeamsLogic\Db\ConnectionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * The first sync of a new connection, queued by the sign-in callback so the browser isn't kept
 * waiting while every event and contact comes across.
 */
class SyncNowJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private ConnectionMapper $connections,
		private SyncService $sync,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		try {
			$connection = $this->connections->findById((int)($argument['id'] ?? 0));
		} catch (DoesNotExistException) {
			return;
		}
		$this->sync->sync($connection);
	}
}
