<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One synced event series or contact: its local URI and remote id, and the ETag each side had
 * after the last round.
 *
 * @method int getConnectionId()
 * @method void setConnectionId(int $connectionId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method int getCollectionId()
 * @method void setCollectionId(int $collectionId)
 * @method string getLocalUri()
 * @method void setLocalUri(string $localUri)
 * @method string getRemoteId()
 * @method void setRemoteId(string $remoteId)
 * @method string|null getRemoteEtag()
 * @method void setRemoteEtag(?string $remoteEtag)
 * @method string|null getLocalEtag()
 * @method void setLocalEtag(?string $localEtag)
 * @method string|null getRemoteHash()
 * @method void setRemoteHash(?string $remoteHash)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class SyncItem extends Entity {
	public const CALENDAR = 'calendar';
	public const CONTACTS = 'contacts';

	protected $connectionId = 0;
	protected $kind = '';
	protected $collectionId = 0;
	protected $localUri = '';
	protected $remoteId = '';
	protected $remoteEtag;
	protected $localEtag;
	/** ContentHash of the remote item as of the last round. */
	protected $remoteHash;
	protected $updatedAt = 0;

	public function __construct() {
		$this->addType('connectionId', 'integer');
		$this->addType('collectionId', 'integer');
		$this->addType('updatedAt', 'integer');
	}
}
