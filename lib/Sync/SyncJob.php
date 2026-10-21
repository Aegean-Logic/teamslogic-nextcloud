<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

use OCA\TeamsLogic\Db\ConnectionMapper;
use OCA\TeamsLogic\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Syncs the connections whose last sync is older than the admin's interval (5 minutes by
 * default), oldest first, for up to four minutes per run.
 */
class SyncJob extends TimedJob {
	private const BATCH = 50;
	private const BUDGET = 240;

	public function __construct(
		ITimeFactory $time,
		private ConnectionMapper $connections,
		private SyncService $sync,
		private SettingsService $settings,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60 * $this->settings->syncInterval());
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$started = $this->time->getTime();
		// A little slack so a connection synced just under an interval ago isn't skipped a round.
		$due = $started - 60 * $this->settings->syncInterval() + 30;
		foreach ($this->connections->findDue($due, self::BATCH) as $connection) {
			if ($this->time->getTime() - $started > self::BUDGET) {
				break;
			}
			try {
				$this->sync->sync($connection);
			} catch (Throwable $e) {
				$this->logger->error('TeamsLogic: sync of connection {id} failed: ' . $e->getMessage(), ['id' => $connection->getId(), 'exception' => $e]);
			}
		}
	}
}
