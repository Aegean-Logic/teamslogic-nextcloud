<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\TeamsLogic\Db\Connection;
use OCA\TeamsLogic\Db\ConnectionMapper;
use OCA\TeamsLogic\Db\SyncItem;
use OCA\TeamsLogic\OAuth\OAuthException;
use OCA\TeamsLogic\OAuth\OAuthService;
use OCA\TeamsLogic\OAuth\Providers;
use OCA\TeamsLogic\Sync\SyncNowJob;
use OCA\TeamsLogic\Sync\SyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * A user's connections: connecting (with Mail set up from the same sign-in), switching mail,
 * calendars, contacts and tasks on and off, syncing now and disconnecting.
 */
class ConnectionService {
	public function __construct(
		private ConnectionMapper $connections,
		private OAuthService $oauth,
		private SettingsService $settings,
		private MailSetup $mail,
		private SyncService $sync,
		private IJobList $jobs,
		private IUserManager $users,
		private CalDavBackend $calDav,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array{mail?: bool, calendar?: bool, contacts?: bool, tasks?: bool} $features
	 * @return array{url: string, state: string, redirectUri: string, scopes: list<string>, mail: bool}
	 */
	public function startConnect(IUser $user, string $provider, array $features): array {
		$mailPossible = $this->settings->connectMail() && $this->mail->isAvailable($user);
		$result = $this->oauth->authorizationUrl($user, $provider, $features, $mailPossible);
		return $result + ['mail' => $mailPossible && ($features['mail'] ?? true)];
	}

	/** The provider sent the user back: store the account, set up Mail, queue the first sync. */
	public function finishConnect(string $provider, string $code, string $state): Connection {
		$result = $this->oauth->finish($provider, $code, $state);
		$connection = $result['connection'];
		if ($connection->getMailEnabled()) {
			$connection = $this->setUpMail($connection, $result['displayName']);
		}
		$this->jobs->add(SyncNowJob::class, ['id' => $connection->getId()]);
		return $connection;
	}

	/** @return list<Connection> */
	public function list(string $userId): array {
		return $this->connections->findAllForUser($userId);
	}

	/**
	 * The user's synced calendars, by local calendar URI, with the online meeting their provider
	 * adds to an event that asks for one (ICal::ONLINE_MEETING): "teams" or "meet".
	 *
	 * @return list<array{connectionId: int, provider: string, email: string, calendarUri: string, name: string, readOnly: bool, onlineMeeting: ?string}>
	 */
	public function calendars(string $userId): array {
		$out = [];
		foreach ($this->connections->findAllForUser($userId) as $connection) {
			if (!$connection->getCalendarEnabled()) {
				continue;
			}
			foreach ((array)($connection->getState()[SyncItem::CALENDAR] ?? []) as $cs) {
				$calendar = isset($cs['localId']) ? $this->calDav->getCalendarById((int)$cs['localId']) : null;
				if ($calendar === null || $calendar['principaluri'] !== 'principals/users/' . $userId) {
					continue;
				}
				$meeting = (string)($cs['meeting'] ?? '');
				$out[] = [
					'connectionId' => (int)$connection->getId(),
					'provider' => $connection->getProvider(),
					'email' => $connection->getAccountEmail(),
					'calendarUri' => (string)$calendar['uri'],
					'name' => (string)($cs['name'] ?? ''),
					'readOnly' => (bool)($cs['readOnly'] ?? false),
					'onlineMeeting' => match (true) {
						empty($cs['readOnly']) && $meeting === 'hangoutsMeet' => 'meet',
						empty($cs['readOnly']) && str_starts_with($meeting, 'teams') => 'teams',
						default => null,
					},
				];
			}
		}
		return $out;
	}

	/** @throws DoesNotExistException */
	public function get(string $userId, int $id): Connection {
		return $this->connections->findForUser($id, $userId);
	}

	/**
	 * Switches features. Calendars and contacts that are switched off stop syncing and keep their
	 * local copies; mail switched off removes the Mail account (the mailbox stays at the provider).
	 *
	 * @param array{mail?: bool, calendar?: bool, contacts?: bool, tasks?: bool} $features
	 * @throws OAuthException mail asked for but not granted ($reconnect)
	 */
	public function update(string $userId, int $id, array $features): Connection {
		$connection = $this->connections->findForUser($id, $userId);
		if (array_key_exists('calendar', $features)) {
			$connection->setCalendarEnabled((bool)$features['calendar']);
		}
		if (array_key_exists('contacts', $features)) {
			$connection->setContactsEnabled((bool)$features['contacts']);
		}
		if (array_key_exists('tasks', $features)) {
			if ($features['tasks'] && !Providers::grantsTasks($connection->getProvider(), $connection->getScopeList())) {
				throw new OAuthException('Tasks were not granted; connect the account again to sync them', true);
			}
			$connection->setTasksEnabled((bool)$features['tasks']);
		}
		$connection = $this->connections->update($connection);
		if (array_key_exists('mail', $features) && (bool)$features['mail'] !== (bool)$connection->getMailEnabled()) {
			if ($features['mail']) {
				// Throws, asking for a new sign-in, when mail wasn't granted the first time.
				$this->oauth->mailTokens($connection);
				$connection->setMailEnabled(true);
				$connection = $this->setUpMail($this->connections->update($connection), '');
			} else {
				if ($connection->getMailAccountId() !== null) {
					$this->mail->removeAccount($userId, $connection->getMailAccountId());
				}
				$connection->setMailEnabled(false);
				$connection->setMailAccountId(null);
				$connection = $this->connections->update($connection);
			}
		}
		return $connection;
	}

	/** @return array{connection: Connection, summary: array} */
	public function syncNow(string $userId, int $id): array {
		$connection = $this->connections->findForUser($id, $userId);
		$summary = $this->sync->sync($connection);
		return ['connection' => $this->connections->findById($id), 'summary' => $summary];
	}

	/**
	 * Disconnects: revokes the grant (Google), removes the Mail account and either removes the
	 * synced calendars and address books or ($keepData) leaves them as plain local ones.
	 *
	 * @return array{revoked: bool}
	 */
	public function delete(string $userId, int $id, bool $keepData): array {
		$connection = $this->connections->findForUser($id, $userId);
		$revoked = $this->oauth->revoke($connection);
		if ($connection->getMailAccountId() !== null) {
			$this->mail->removeAccount($userId, $connection->getMailAccountId());
		}
		$this->sync->forget($connection, $keepData);
		$this->connections->delete($connection);
		return ['revoked' => $revoked];
	}

	/** Everything of a deleted user. */
	public function deleteAllForUser(string $userId): void {
		foreach ($this->connections->findAllForUser($userId) as $connection) {
			$this->oauth->revoke($connection);
			$this->sync->forget($connection, true);
			$this->connections->delete($connection);
		}
	}

	private function setUpMail(Connection $connection, string $displayName): Connection {
		$user = $this->users->get($connection->getUserId());
		if ($user === null) {
			return $connection;
		}
		try {
			$tokens = $this->oauth->mailTokens($connection);
		} catch (OAuthException $e) {
			$this->logger->warning('TeamsLogic: no mail token for {email}: ' . $e->getMessage(), ['email' => $connection->getAccountEmail()]);
			$connection->setMailEnabled(false);
			$connection->setLastError('Mail: ' . $e->getMessage());
			return $this->connections->update($connection);
		}
		$accountId = $this->mail->setUpAccount($user, $connection, $displayName !== '' ? $displayName : $user->getDisplayName(), $tokens['access'], $tokens['refresh'], $tokens['expires']);
		if ($accountId === null) {
			$connection->setMailEnabled(false);
			$connection->setLastError('Mail: Nextcloud Mail is not available or could not add the account');
		} else {
			$connection->setMailAccountId($accountId);
		}
		return $this->connections->update($connection);
	}
}
