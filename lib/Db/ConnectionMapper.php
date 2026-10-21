<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Connection>
 */
class ConnectionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'teamslogic_connections', Connection::class);
	}

	/** @throws DoesNotExistException */
	public function findForUser(int $id, string $userId): Connection {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntity($qb);
	}

	/** @throws DoesNotExistException */
	public function findById(int $id): Connection {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		return $this->findEntity($qb);
	}

	/** @return list<Connection> */
	public function findAllForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	public function findAccount(string $userId, string $provider, string $email): ?Connection {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('provider', $qb->createNamedParameter($provider)))
			->andWhere($qb->expr()->eq('account_email', $qb->createNamedParameter($email)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Connections not synced since $before, oldest first.
	 *
	 * @return list<Connection>
	 */
	public function findDue(int $before, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->lt('last_sync', $qb->createNamedParameter($before)))
			->orderBy('last_sync')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}
}
