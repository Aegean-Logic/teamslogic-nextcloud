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
 * What the remote item said at the last round (ContentHash), so a new etag on unchanged content
 * isn't taken for a remote edit.
 */
class Version000101Date20261021130000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('teamslogic_sync_items');
		if (!$table->hasColumn('remote_hash')) {
			$table->addColumn('remote_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			return $schema;
		}
		return null;
	}
}
