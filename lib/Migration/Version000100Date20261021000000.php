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
 * Connected Google / Microsoft accounts, and which local calendar object or card is which
 * remote event or contact.
 */
class Version000100Date20261021000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('teamslogic_connections')) {
			$t = $schema->createTable('teamslogic_connections');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			// google | microsoft
			$t->addColumn('provider', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('account_email', Types::STRING, ['notnull' => true, 'default' => '', 'length' => 255]);
			// Tokens, encrypted with the server secret (ICrypto).
			$t->addColumn('access_token', Types::TEXT, ['notnull' => false]);
			$t->addColumn('refresh_token', Types::TEXT, ['notnull' => false]);
			$t->addColumn('token_expires', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			// The scopes the user granted, space separated.
			$t->addColumn('scopes', Types::TEXT, ['notnull' => false]);
			$t->addColumn('mail_enabled', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('calendar_enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('contacts_enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			// The Nextcloud Mail account the connection set up.
			$t->addColumn('mail_account_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			// Per remote calendar / address book: its local copy and both sides' sync tokens (JSON).
			$t->addColumn('sync_state', Types::TEXT, ['notnull' => false]);
			$t->addColumn('last_sync', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$t->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['user_id'], 'tl_conn_user');
			$t->addUniqueIndex(['user_id', 'provider', 'account_email'], 'tl_conn_account');
		}

		if (!$schema->hasTable('teamslogic_sync_items')) {
			$t = $schema->createTable('teamslogic_sync_items');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->addColumn('connection_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// calendar | contacts
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			// The local calendar or address book id.
			$t->addColumn('collection_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('local_uri', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('remote_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			// Google's etag, or Graph's @odata.etag (which carries the changeKey).
			$t->addColumn('remote_etag', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('local_etag', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['connection_id', 'kind', 'collection_id', 'remote_id'], 'tl_items_remote');
			$t->addIndex(['collection_id', 'local_uri'], 'tl_items_local');
		}
		return $schema;
	}
}
