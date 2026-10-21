<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

use OCA\TeamsLogic\Convert\UnsupportedException;
use OCA\TeamsLogic\Remote\PreconditionFailedException;
use OCA\TeamsLogic\Remote\RemoteException;
use OCA\TeamsLogic\Remote\SyncTokenExpiredException;

/**
 * One provider's calendars or address books, as the sync engine sees them. Local items are
 * iCalendar or vCard text.
 */
interface RemoteApi {
	/** SyncItem::CALENDAR or SyncItem::CONTACTS */
	public function kind(): string;

	/** The provider's name in local collection names ("Google · Work"). */
	public function label(): string;

	/** @return list<RemoteCollection> */
	public function collections(): array;

	/**
	 * Changes since $token, or everything for null.
	 *
	 * @throws SyncTokenExpiredException
	 * @throws RemoteException
	 */
	public function changes(RemoteCollection $collection, ?string $token): ChangeSet;

	/** The item as it is now, or null if it's gone. */
	public function fetch(RemoteCollection $collection, string $id): ?RemoteItem;

	/**
	 * The local text for a remote item. $existing is the current local copy (kept parts: a Google
	 * series' other instances, the local UID).
	 */
	public function toLocal(RemoteCollection $collection, RemoteItem $item, ?string $existing): string;

	/**
	 * @throws UnsupportedException
	 * @throws RemoteException
	 */
	public function create(RemoteCollection $collection, string $local): RemoteItem;

	/**
	 * @throws PreconditionFailedException the remote changed since $etag
	 * @throws UnsupportedException
	 * @throws RemoteException
	 */
	public function update(RemoteCollection $collection, string $id, ?string $etag, string $local): RemoteItem;

	/**
	 * @throws PreconditionFailedException
	 * @throws RemoteException
	 */
	public function delete(RemoteCollection $collection, string $id, ?string $etag): void;
}
