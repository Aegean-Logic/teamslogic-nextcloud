<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Service;

use OCA\TeamsLogic\AppInfo\Application;
use OCA\TeamsLogic\Db\Connection;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;

/**
 * The admin settings: each provider's OAuth client, the sync interval and whether connecting an
 * account also sets up Nextcloud Mail. Secrets are stored encrypted and never sent back to the
 * browser (the form shows only whether one is set).
 */
class SettingsService {
	public const GOOGLE_CLIENT_ID = 'google_client_id';
	public const GOOGLE_CLIENT_SECRET = 'google_client_secret';
	public const MICROSOFT_CLIENT_ID = 'microsoft_client_id';
	public const MICROSOFT_CLIENT_SECRET = 'microsoft_client_secret';
	public const MICROSOFT_TENANT = 'microsoft_tenant';
	public const SYNC_INTERVAL = 'sync_interval';
	public const CONNECT_MAIL = 'connect_mail';

	public const DEFAULT_INTERVAL = 5;
	public const MIN_INTERVAL = 1;

	public function __construct(
		private IAppConfig $appConfig,
		private ICrypto $crypto,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function clientId(string $provider): string {
		return $this->appConfig->getValueString(Application::APP_ID, $provider === Connection::GOOGLE ? self::GOOGLE_CLIENT_ID : self::MICROSOFT_CLIENT_ID);
	}

	public function clientSecret(string $provider): string {
		$stored = $this->appConfig->getValueString(Application::APP_ID, $provider === Connection::GOOGLE ? self::GOOGLE_CLIENT_SECRET : self::MICROSOFT_CLIENT_SECRET);
		if ($stored === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($stored);
		} catch (\Exception) {
			return '';
		}
	}

	public function tenant(): string {
		$tenant = trim($this->appConfig->getValueString(Application::APP_ID, self::MICROSOFT_TENANT, 'common'));
		return $tenant === '' ? 'common' : $tenant;
	}

	public function isConfigured(string $provider): bool {
		return $this->clientId($provider) !== '' && $this->clientSecret($provider) !== '';
	}

	/** Minutes between background syncs of a connection. */
	public function syncInterval(): int {
		return max(self::MIN_INTERVAL, $this->appConfig->getValueInt(Application::APP_ID, self::SYNC_INTERVAL, self::DEFAULT_INTERVAL));
	}

	public function connectMail(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::CONNECT_MAIL, true);
	}

	/** The address the provider sends the user back to; it must be registered with the provider exactly. */
	public function redirectUri(string $provider): string {
		return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.oauth.callback', ['provider' => $provider]);
	}

	/**
	 * Saves the form. An empty secret keeps the stored one (the form never shows it).
	 *
	 * @param array<string, mixed> $values
	 */
	public function save(array $values): void {
		$app = Application::APP_ID;
		foreach ([self::GOOGLE_CLIENT_ID, self::MICROSOFT_CLIENT_ID] as $key) {
			if (array_key_exists($key, $values)) {
				$this->appConfig->setValueString($app, $key, trim((string)$values[$key]));
			}
		}
		if (array_key_exists(self::MICROSOFT_TENANT, $values)) {
			$tenant = trim((string)$values[self::MICROSOFT_TENANT]);
			$this->appConfig->setValueString($app, self::MICROSOFT_TENANT, preg_match('/^[A-Za-z0-9.-]+$/', $tenant) ? $tenant : 'common');
		}
		foreach ([self::GOOGLE_CLIENT_SECRET, self::MICROSOFT_CLIENT_SECRET] as $key) {
			$secret = trim((string)($values[$key] ?? ''));
			if ($secret !== '') {
				$this->appConfig->setValueString($app, $key, $this->crypto->encrypt($secret), sensitive: true);
			}
		}
		if (array_key_exists(self::SYNC_INTERVAL, $values)) {
			$this->appConfig->setValueInt($app, self::SYNC_INTERVAL, max(self::MIN_INTERVAL, min(1440, (int)$values[self::SYNC_INTERVAL])));
		}
		if (array_key_exists(self::CONNECT_MAIL, $values)) {
			$this->appConfig->setValueBool($app, self::CONNECT_MAIL, filter_var($values[self::CONNECT_MAIL], FILTER_VALIDATE_BOOLEAN));
		}
	}

	public function clearSecret(string $provider): void {
		$this->appConfig->deleteKey(Application::APP_ID, $provider === Connection::GOOGLE ? self::GOOGLE_CLIENT_SECRET : self::MICROSOFT_CLIENT_SECRET);
	}

	/** @return array<string, mixed> what the admin form shows */
	public function forForm(): array {
		return [
			'googleClientId' => $this->clientId(Connection::GOOGLE),
			'googleSecretSet' => $this->clientSecret(Connection::GOOGLE) !== '',
			'googleRedirectUri' => $this->redirectUri(Connection::GOOGLE),
			'microsoftClientId' => $this->clientId(Connection::MICROSOFT),
			'microsoftSecretSet' => $this->clientSecret(Connection::MICROSOFT) !== '',
			'microsoftTenant' => $this->tenant(),
			'microsoftRedirectUri' => $this->redirectUri(Connection::MICROSOFT),
			'syncInterval' => $this->syncInterval(),
			'connectMail' => $this->connectMail(),
		];
	}
}
