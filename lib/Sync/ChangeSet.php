<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

/**
 * What changed remotely since a sync token: the items, whether the listing is complete (a first
 * or restarted sync: anything known but missing was deleted), and the token for next time.
 */
final class ChangeSet {
	/**
	 * @param array<string, RemoteItem> $items remote id => item
	 */
	public function __construct(
		public readonly array $items,
		public readonly bool $complete,
		public readonly ?string $token,
	) {
	}

	/** @return array<string, array{etag: ?string, deleted: bool}> for SyncPlanner */
	public function forPlanner(): array {
		$out = [];
		foreach ($this->items as $id => $item) {
			$out[(string)$id] = ['etag' => $item->etag, 'deleted' => $item->deleted];
		}
		return $out;
	}
}
