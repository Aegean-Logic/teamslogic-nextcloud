<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

use OCA\DAV\CardDAV\CardDavBackend;

/**
 * Synced address books, through Nextcloud's CardDAV backend.
 */
class LocalAddressBookStore implements LocalStore {
	public function __construct(
		private CardDavBackend $backend,
	) {
	}

	public function ensureCollection(string $userId, ?int $knownId, string $uri, string $displayName, ?string $color): int {
		$principal = 'principals/users/' . $userId;
		if ($knownId !== null) {
			$book = $this->backend->getAddressBookById($knownId);
			if ($book !== null && $book['principaluri'] === $principal) {
				return $knownId;
			}
		}
		$existing = $this->backend->getAddressBooksByUri($principal, $uri);
		if (is_array($existing)) {
			return (int)$existing['id'];
		}
		return (int)$this->backend->createAddressBook($principal, $uri, ['{DAV:}displayname' => $displayName]);
	}

	public function deleteCollection(int $id): void {
		$this->backend->deleteAddressBook($id);
	}

	public function changes(int $id, ?string $token): array {
		$result = $this->backend->getChangesForAddressBook($id, $token, 1);
		if ($result === null) {
			return ['token' => (string)$token, 'changed' => []];
		}
		$changed = [];
		$present = array_values(array_unique(array_merge($result['added'] ?? [], $result['modified'] ?? [])));
		foreach (array_chunk($present, 200) as $chunk) {
			foreach ($this->backend->getMultipleCards($id, $chunk) as $row) {
				$changed[(string)$row['uri']] = (string)$row['etag'];
			}
		}
		foreach ($result['deleted'] ?? [] as $uri) {
			if (!isset($changed[$uri])) {
				$changed[(string)$uri] = null;
			}
		}
		return ['token' => (string)$result['syncToken'], 'changed' => $changed];
	}

	public function get(int $id, string $uri): ?array {
		$row = $this->backend->getCard($id, $uri);
		if (!is_array($row)) {
			return null;
		}
		return ['data' => (string)$row['carddata'], 'etag' => (string)$row['etag']];
	}

	public function put(int $id, string $uri, string $data): string {
		if (is_array($this->backend->getCard($id, $uri))) {
			return (string)$this->backend->updateCard($id, $uri, $data);
		}
		return (string)$this->backend->createCard($id, $uri, $data);
	}

	public function delete(int $id, string $uri): void {
		if (is_array($this->backend->getCard($id, $uri))) {
			$this->backend->deleteCard($id, $uri);
		}
	}

	public function extension(): string {
		return '.vcf';
	}
}
