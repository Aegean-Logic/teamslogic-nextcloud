<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Sync;

use OCA\TeamsLogic\Db\Connection;
use OCA\TeamsLogic\Remote\GoogleCalendarApi;
use OCA\TeamsLogic\Remote\GoogleContactsApi;
use OCA\TeamsLogic\Remote\GraphCalendarApi;
use OCA\TeamsLogic\Remote\GraphContactsApi;
use OCA\TeamsLogic\Remote\Http;
use OCP\Http\Client\IClientService;

/**
 * The remote APIs a connection syncs: its provider's calendars and contacts, as switched on.
 */
class RemoteApiFactory {
	public function __construct(
		private IClientService $clientService,
	) {
	}

	/** @return list<RemoteApi> */
	public function forConnection(Connection $connection, string $accessToken): array {
		$http = new Http($this->clientService, $accessToken);
		$google = $connection->getProvider() === Connection::GOOGLE;
		$apis = [];
		if ($connection->getCalendarEnabled()) {
			$apis[] = $google ? new GoogleCalendarApi($http) : new GraphCalendarApi($http);
		}
		if ($connection->getContactsEnabled()) {
			$apis[] = $google ? new GoogleContactsApi($http) : new GraphContactsApi($http);
		}
		return $apis;
	}
}
