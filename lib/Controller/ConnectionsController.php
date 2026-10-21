<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Controller;

use OCA\TeamsLogic\AppInfo\Application;
use OCA\TeamsLogic\Db\Connection;
use OCA\TeamsLogic\OAuth\OAuthException;
use OCA\TeamsLogic\Service\ConnectionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The OCS API the TeamsLogic desktop app uses (`/ocs/v2.php/apps/teamslogic/api/v1/…`):
 * connect an account, list connections, switch features, sync now, disconnect.
 */
class ConnectionsController extends OCSController {
	public function __construct(
		IRequest $request,
		private ConnectionService $connections,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The provider's sign-in URL. Open it in a browser; the provider sends the user back to
	 * /apps/teamslogic/oauth/{provider}, which finishes the connection.
	 *
	 * @param 'google'|'microsoft' $provider
	 * @param bool|null $mail also set up Nextcloud Mail (default: yes, where the admin allows it)
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/connect/{provider}', requirements: ['provider' => 'google|microsoft'])]
	public function connect(string $provider, ?bool $mail = null, ?bool $calendar = null, ?bool $contacts = null): DataResponse {
		$features = array_filter(['mail' => $mail, 'calendar' => $calendar, 'contacts' => $contacts], static fn ($v) => $v !== null);
		try {
			return new DataResponse($this->connections->startConnect($this->user(), $provider, $features));
		} catch (OAuthException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_PRECONDITION_FAILED);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/connections')]
	public function index(): DataResponse {
		return new DataResponse(array_map(static fn (Connection $c) => $c->jsonSerialize(), $this->connections->list($this->user()->getUID())));
	}

	/** Switches mail, calendar or contacts on or off. */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PATCH', url: '/api/v1/connections/{id}', requirements: ['id' => '\d+'])]
	public function update(int $id, ?bool $mail = null, ?bool $calendar = null, ?bool $contacts = null): DataResponse {
		$features = array_filter(['mail' => $mail, 'calendar' => $calendar, 'contacts' => $contacts], static fn ($v) => $v !== null);
		try {
			return new DataResponse($this->connections->update($this->user()->getUID(), $id, $features)->jsonSerialize());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'No such connection'], Http::STATUS_NOT_FOUND);
		} catch (OAuthException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'reconnect' => $e->reconnect], Http::STATUS_CONFLICT);
		}
	}

	/** Syncs now and answers with the connection afterwards and what moved. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 300)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/connections/{id}/sync', requirements: ['id' => '\d+'])]
	public function sync(int $id): DataResponse {
		try {
			$result = $this->connections->syncNow($this->user()->getUID(), $id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'No such connection'], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($result['connection']->jsonSerialize() + ['summary' => $result['summary']]);
	}

	/**
	 * Disconnects. keepData=1 leaves the synced calendars and address books as ordinary local
	 * ones; keepData=0 (default) removes them.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/connections/{id}', requirements: ['id' => '\d+'])]
	public function destroy(int $id, bool $keepData = false): DataResponse {
		try {
			return new DataResponse($this->connections->delete($this->user()->getUID(), $id, $keepData));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'No such connection'], Http::STATUS_NOT_FOUND);
		}
	}

	private function user(): \OCP\IUser {
		return $this->userSession->getUser() ?? throw new \RuntimeException('Not signed in');
	}
}
