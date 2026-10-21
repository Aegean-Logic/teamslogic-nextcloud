<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Tasks (Microsoft To Do, Google Tasks) as a feature a connection syncs.
 */
class Version000200Date20261021150000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('teamslogic_connections');
		if (!$table->hasColumn('tasks_enabled')) {
			$table->addColumn('tasks_enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			return $schema;
		}
		return null;
	}
}
