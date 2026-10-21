<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

/**
 * One step of a sync round, as SyncPlanner decides it.
 */
final class SyncAction {
	/** Remote → local: a new item. */
	public const PULL_CREATE = 'pull-create';
	/** Remote → local: overwrite the local copy (a remote change, a conflict, or undoing an edit to a read-only item). */
	public const PULL_UPDATE = 'pull-update';
	/** Remote → local: the remote item is gone. */
	public const PULL_DELETE = 'pull-delete';
	/** Local → remote. */
	public const PUSH_CREATE = 'push-create';
	public const PUSH_UPDATE = 'push-update';
	public const PUSH_DELETE = 'push-delete';
	/** Both sides deleted it: drop the mapping only. */
	public const FORGET = 'forget';

	public function __construct(
		public readonly string $type,
		public readonly ?string $remoteId,
		public readonly ?string $localUri,
		/** Both sides changed; remote won. */
		public readonly bool $conflict = false,
		/** Why, for the log. */
		public readonly string $note = '',
	) {
	}

	public function isPull(): bool {
		return str_starts_with($this->type, 'pull-');
	}

	public function isPush(): bool {
		return str_starts_with($this->type, 'push-');
	}
}
