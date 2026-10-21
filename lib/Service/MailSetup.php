<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Service;

use OCA\TeamsLogic\Db\Connection;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IUser;
use OCP\Security\ICrypto;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Nextcloud Mail, set up from the same sign-in.
 *
 * Mail keeps its own copy of the OAuth client (it refreshes its tokens itself), so the admin's
 * Google / Microsoft credentials are written into Mail's integration settings too. A connected
 * account gets a Mail account with authMethod xoauth2 and the tokens already in it, the way
 * Mail's own "Sign in with Google / Microsoft" leaves one.
 *
 * Mail is optional and its classes are internal: everything here checks the version first and
 * gives up quietly (logged) on anything unexpected.
 */
class MailSetup {
	/** Mail versions whose account and integration classes this was written against. */
	public const MIN_VERSION = '5.0.0';
	public const MAX_MAJOR = 5;

	private const SERVERS = [
		Connection::GOOGLE => ['imap.gmail.com', 993, 'ssl', 'smtp.gmail.com', 465, 'ssl'],
		Connection::MICROSOFT => ['outlook.office365.com', 993, 'ssl', 'smtp.office365.com', 587, 'tls'],
	];

	public function __construct(
		private IAppManager $appManager,
		private ICrypto $crypto,
		private ITimeFactory $time,
		private IUserConfig $userConfig,
		private LoggerInterface $logger,
	) {
	}

	/** Mail is installed, enabled (for $user, if given) and a version this knows. */
	public function isAvailable(?IUser $user = null): bool {
		if (!$this->appManager->isInstalled('mail')) {
			return false;
		}
		if ($user !== null ? !$this->appManager->isEnabledForUser('mail', $user) : !$this->appManager->isEnabledForAnyone('mail')) {
			return false;
		}
		$version = $this->appManager->getAppVersion('mail');
		if (version_compare($version, self::MIN_VERSION, '<') || (int)explode('.', $version)[0] > self::MAX_MAJOR) {
			$this->logger->info('TeamsLogic: Nextcloud Mail {version} is not a version TeamsLogic sets up; skipping mail', ['version' => $version]);
			return false;
		}
		try {
			$this->appManager->loadApp('mail');
		} catch (Throwable) {
			return false;
		}
		return class_exists(\OCA\Mail\Service\SetupService::class) && class_exists(\OCA\Mail\Service\AccountService::class);
	}

	/** Writes the OAuth client into Mail's Google / Microsoft integration settings. */
	public function configureClients(SettingsService $settings): bool {
		if (!$this->isAvailable()) {
			return false;
		}
		try {
			if ($settings->isConfigured(Connection::GOOGLE)) {
				Server::get(\OCA\Mail\Integration\GoogleIntegration::class)
					->configure($settings->clientId(Connection::GOOGLE), $settings->clientSecret(Connection::GOOGLE));
			}
			if ($settings->isConfigured(Connection::MICROSOFT)) {
				Server::get(\OCA\Mail\Integration\MicrosoftIntegration::class)
					->configure($settings->tenant(), $settings->clientId(Connection::MICROSOFT), $settings->clientSecret(Connection::MICROSOFT));
			}
			return true;
		} catch (Throwable $e) {
			$this->logger->warning('TeamsLogic: could not configure Nextcloud Mail\'s OAuth clients: ' . $e->getMessage(), ['exception' => $e]);
			return false;
		}
	}

	/**
	 * Creates (or refreshes the tokens of) the user's Mail account for a connection.
	 *
	 * @param string $accessToken a token for IMAP/SMTP (Google: the same token; Microsoft: one for outlook.office.com)
	 * @return int|null the Mail account id
	 */
	public function setUpAccount(IUser $user, Connection $connection, string $displayName, string $accessToken, string $refreshToken, int $expiresAt): ?int {
		if (!$this->isAvailable($user)) {
			return null;
		}
		[$imapHost, $imapPort, $imapSsl, $smtpHost, $smtpPort, $smtpSsl] = self::SERVERS[$connection->getProvider()];
		$email = $connection->getAccountEmail();
		try {
			/** @var \OCA\Mail\Service\AccountService $accounts */
			$accounts = Server::get(\OCA\Mail\Service\AccountService::class);
			$mailAccount = null;
			if ($connection->getMailAccountId() !== null) {
				try {
					$mailAccount = $accounts->find($user->getUID(), $connection->getMailAccountId())->getMailAccount();
				} catch (Throwable) {
					$mailAccount = null;
				}
			}
			if ($mailAccount === null) {
				foreach ($accounts->findByUserIdAndAddress($user->getUID(), $email) as $existing) {
					$mailAccount = $existing->getMailAccount();
					break;
				}
			}
			if ($mailAccount === null) {
				/** @var \OCA\Mail\Service\SetupService $setup */
				$setup = Server::get(\OCA\Mail\Service\SetupService::class);
				$account = $setup->createNewAccount(
					$displayName !== '' ? $displayName : $email, $email,
					$imapHost, $imapPort, $imapSsl, $email, null,
					$smtpHost, $smtpPort, $smtpSsl, $email, null,
					$user->getUID(), 'xoauth2',
				);
				$mailAccount = $account->getMailAccount();
				// Mail syncs the accounts of people who've used it lately; count this as a use.
				$this->userConfig->setValueString($user->getUID(), 'mail', 'ui-heartbeat', (string)$this->time->getTime());
			}
			$mailAccount->setOauthAccessToken($this->crypto->encrypt($accessToken));
			$mailAccount->setOauthRefreshToken($this->crypto->encrypt($refreshToken));
			$mailAccount->setOauthTokenTtl($expiresAt);
			$accounts->update($mailAccount);
			return (int)$mailAccount->getId();
		} catch (Throwable $e) {
			$this->logger->warning('TeamsLogic: could not set up the Mail account for {email}: ' . $e->getMessage(), ['email' => $email, 'exception' => $e]);
			return null;
		}
	}

	public function removeAccount(string $userId, int $accountId): void {
		if (!$this->appManager->isInstalled('mail')) {
			return;
		}
		try {
			$this->appManager->loadApp('mail');
			Server::get(\OCA\Mail\Service\AccountService::class)->delete($userId, $accountId);
		} catch (Throwable $e) {
			$this->logger->info('TeamsLogic: Mail account {id} not removed: ' . $e->getMessage(), ['id' => $accountId]);
		}
	}
}
