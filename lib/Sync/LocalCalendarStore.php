<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

use OCA\DAV\CalDAV\CalDavBackend;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;

/**
 * Synced calendars, through Nextcloud's CalDAV backend (so the Calendar app, activity and
 * CalDAV clients see every change). Task lists are calendars of VTODOs (the Tasks app's lists).
 */
class LocalCalendarStore implements LocalStore {
	public function __construct(
		private CalDavBackend $backend,
		/** VEVENT for a calendar, VTODO for a task list */
		private string $component = 'VEVENT',
	) {
	}

	public function ensureCollection(string $userId, ?int $knownId, string $uri, string $displayName, ?string $color): int {
		$principal = 'principals/users/' . $userId;
		if ($knownId !== null) {
			$calendar = $this->backend->getCalendarById($knownId);
			if ($calendar !== null && $calendar['principaluri'] === $principal) {
				return $knownId;
			}
		}
		$existing = $this->backend->getCalendarByUri($principal, $uri);
		if (is_array($existing)) {
			return (int)$existing['id'];
		}
		$props = [
			'{DAV:}displayname' => $displayName,
			'{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet([$this->component]),
		];
		if ($color !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
			$props['{http://apple.com/ns/ical/}calendar-color'] = $color;
		}
		return (int)$this->backend->createCalendar($principal, $uri, $props);
	}

	public function deleteCollection(int $id): void {
		$this->backend->deleteCalendar($id, true);
	}

	public function changes(int $id, ?string $token): array {
		$result = $this->backend->getChangesForCalendar($id, $token, 1);
		if ($result === null) {
			return ['token' => (string)$token, 'changed' => []];
		}
		$changed = [];
		$present = array_values(array_unique(array_merge($result['added'] ?? [], $result['modified'] ?? [])));
		foreach (array_chunk($present, 200) as $chunk) {
			foreach ($this->backend->getMultipleCalendarObjects($id, $chunk) as $row) {
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
		$row = $this->backend->getCalendarObject($id, $uri);
		if (!is_array($row)) {
			return null;
		}
		return ['data' => (string)$row['calendardata'], 'etag' => (string)$row['etag']];
	}

	public function put(int $id, string $uri, string $data): string {
		if (is_array($this->backend->getCalendarObject($id, $uri))) {
			return (string)$this->backend->updateCalendarObject($id, $uri, $data);
		}
		return (string)$this->backend->createCalendarObject($id, $uri, $data);
	}

	public function delete(int $id, string $uri): void {
		if (is_array($this->backend->getCalendarObject($id, $uri))) {
			$this->backend->deleteCalendarObject($id, $uri, CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
		}
	}

	public function extension(): string {
		return '.ics';
	}
}
