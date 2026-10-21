<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

/**
 * The user's local calendars or address books (Nextcloud's CalDAV / CardDAV backends).
 */
interface LocalStore {
	/** The local copy of a remote collection: the one recorded if it still exists, else a new one. */
	public function ensureCollection(string $userId, ?int $knownId, string $uri, string $displayName, ?string $color): int;

	public function deleteCollection(int $id): void;

	/**
	 * Changes since $token (everything for null): URI => ETag, null for deleted.
	 *
	 * @return array{token: string, changed: array<string, ?string>}
	 */
	public function changes(int $id, ?string $token): array;

	/** @return array{data: string, etag: string}|null */
	public function get(int $id, string $uri): ?array;

	/** Creates or replaces an object; returns its new ETag. */
	public function put(int $id, string $uri, string $data): string;

	public function delete(int $id, string $uri): void;

	/** ".ics" or ".vcf" */
	public function extension(): string;
}
