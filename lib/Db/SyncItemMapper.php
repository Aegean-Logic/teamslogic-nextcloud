<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<SyncItem>
 */
class SyncItemMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'teamslogic_sync_items', SyncItem::class);
	}

	/** @return array<string, SyncItem> remote id => item */
	public function findForCollection(int $connectionId, string $kind, int $collectionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('connection_id', $qb->createNamedParameter($connectionId)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('collection_id', $qb->createNamedParameter($collectionId)));
		$out = [];
		foreach ($this->findEntities($qb) as $item) {
			$out[$item->getRemoteId()] = $item;
		}
		return $out;
	}

	public function deleteForCollection(int $connectionId, string $kind, int $collectionId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('connection_id', $qb->createNamedParameter($connectionId)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('collection_id', $qb->createNamedParameter($collectionId)));
		$qb->executeStatement();
	}

	public function deleteForConnection(int $connectionId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('connection_id', $qb->createNamedParameter($connectionId)));
		$qb->executeStatement();
	}
}
