<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\OAuth;

use OCA\TeamsLogic\AppInfo\Application;
use OCA\TeamsLogic\Db\Connection;
use OCA\TeamsLogic\Db\ConnectionMapper;
use OCA\TeamsLogic\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\Http\Client\IClientService;
use OCP\IUser;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The authorization-code flow (with PKCE) for one user and provider, and the tokens afterwards.
 *
 * The state is "<base64url user id>.<random>": the callback works in any browser, signed in to
 * Nextcloud or not (the desktop app opens the system browser). Only a hash of the random part is
 * kept, in the user's settings, for 15 minutes and one use.
 */
class OAuthService {
	private const PENDING = 'oauth_pending_';
	private const STATE_TTL = 900;

	public function __construct(
		private SettingsService $settings,
		private ConnectionMapper $connections,
		private IClientService $clientService,
		private ICrypto $crypto,
		private ISecureRandom $random,
		private IUserConfig $userConfig,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The provider's sign-in page for $user.
	 *
	 * @param array{mail?: bool, calendar?: bool, contacts?: bool} $features
	 * @return array{url: string, state: string, redirectUri: string, scopes: list<string>}
	 */
	public function authorizationUrl(IUser $user, string $provider, array $features, bool $mailPossible): array {
		if (!$this->settings->isConfigured($provider)) {
			throw new OAuthException("$provider is not set up on this server");
		}
		$mail = $mailPossible && ($features['mail'] ?? true);
		$scopes = Providers::scopes($provider, $mail);
		$secret = $this->random->generate(40, ISecureRandom::CHAR_ALPHANUMERIC);
		$state = self::b64($user->getUID()) . '.' . $secret;
		$verifier = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->userConfig->setValueString($user->getUID(), Application::APP_ID, self::PENDING . $provider, json_encode([
			'state' => hash('sha256', $secret),
			'verifier' => $this->crypto->encrypt($verifier),
			'created' => $this->time->getTime(),
			'features' => [
				'mail' => $mail,
				'calendar' => (bool)($features['calendar'] ?? true),
				'contacts' => (bool)($features['contacts'] ?? true),
			],
		], JSON_THROW_ON_ERROR));

		$redirect = $this->settings->redirectUri($provider);
		$params = [
			'client_id' => $this->settings->clientId($provider),
			'redirect_uri' => $redirect,
			'response_type' => 'code',
			'scope' => implode(' ', $scopes),
			'state' => $state,
			'code_challenge' => self::b64(hash('sha256', $verifier, true)),
			'code_challenge_method' => 'S256',
		];
		if ($provider === Connection::GOOGLE) {
			// A refresh token every time, and the consent screen to grant it.
			$params += ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'];
			$url = Providers::GOOGLE_AUTHORIZE;
		} else {
			$params += ['response_mode' => 'query', 'prompt' => 'select_account'];
			$url = Providers::microsoftAuthorize($this->settings->tenant());
		}
		return [
			'url' => $url . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986),
			'state' => $state,
			'redirectUri' => $redirect,
			'scopes' => $scopes,
		];
	}

	/** The user a state belongs to (not yet checked). */
	public static function userFromState(string $state): ?string {
		$dot = strpos($state, '.');
		if ($dot === false) {
			return null;
		}
		$user = base64_decode(strtr(substr($state, 0, $dot), '-_', '+/'), true);
		return $user === false || $user === '' ? null : $user;
	}

	/**
	 * Checks the state and redeems the code. Returns the connection (new, or the existing one for
	 * the same account with new tokens) and the account's display name.
	 *
	 * @return array{connection: Connection, displayName: string}
	 */
	public function finish(string $provider, string $code, string $state): array {
		$userId = self::userFromState($state);
		if ($userId === null) {
			throw new OAuthException('Invalid state');
		}
		$pending = json_decode($this->userConfig->getValueString($userId, Application::APP_ID, self::PENDING . $provider), true);
		$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::PENDING . $provider);
		$secret = substr($state, strpos($state, '.') + 1);
		if (!is_array($pending) || !hash_equals((string)($pending['state'] ?? ''), hash('sha256', $secret))) {
			throw new OAuthException('Invalid state');
		}
		if ($this->time->getTime() - (int)($pending['created'] ?? 0) > self::STATE_TTL) {
			throw new OAuthException('The sign-in took too long; please try again');
		}
		$verifier = $this->crypto->decrypt((string)$pending['verifier']);
		$features = (array)$pending['features'];

		$form = [
			'client_id' => $this->settings->clientId($provider),
			'client_secret' => $this->settings->clientSecret($provider),
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $this->settings->redirectUri($provider),
			'code_verifier' => $verifier,
		];
		if ($provider === Connection::MICROSOFT) {
			$form['scope'] = implode(' ', Providers::GRAPH_SCOPES);
		}
		$tokens = $this->tokenRequest($provider, $form);
		if (empty($tokens['refresh_token'])) {
			throw new OAuthException('The provider did not return a refresh token');
		}

		$granted = array_values(array_filter(explode(' ', (string)($tokens['scope'] ?? ''))));
		[$email, $displayName] = $this->identify($provider, $tokens);
		$mail = !empty($features['mail']);
		if ($mail && $provider === Connection::GOOGLE && $granted !== []) {
			// The user can untick Gmail on Google's consent screen.
			$mail = Providers::grantsMail($provider, $granted);
		}
		if ($mail && $provider === Connection::MICROSOFT) {
			// The Graph token's scope lists Graph only; the Outlook scopes were consented to as well.
			$granted = array_merge($granted, Providers::OUTLOOK_MAIL_SCOPES);
		}

		$connection = $this->connections->findAccount($userId, $provider, $email) ?? new Connection();
		$isNew = $connection->getId() === null;
		$connection->setUserId($userId);
		$connection->setProvider($provider);
		$connection->setAccountEmail($email);
		$this->storeTokens($connection, $tokens);
		$connection->setScopes(implode(' ', $granted !== [] ? $granted : Providers::scopes($provider, $mail)));
		$connection->setMailEnabled($mail);
		$connection->setCalendarEnabled((bool)($features['calendar'] ?? true));
		$connection->setContactsEnabled((bool)($features['contacts'] ?? true));
		$connection->setLastError(null);
		if ($isNew) {
			$connection->setCreatedAt($this->time->getTime());
			$connection = $this->connections->insert($connection);
		} else {
			$connection = $this->connections->update($connection);
		}
		return ['connection' => $connection, 'displayName' => $displayName];
	}

	/**
	 * A token pair for Nextcloud Mail's IMAP/SMTP login. Google's token covers mail as it is;
	 * Microsoft's refresh token is redeemed again for the Outlook resource.
	 *
	 * @return array{access: string, refresh: string, expires: int}
	 */
	public function mailTokens(Connection $connection): array {
		if (!Providers::grantsMail($connection->getProvider(), $connection->getScopeList())) {
			throw new OAuthException('Mail was not granted; connect the account again with mail', true);
		}
		if ($connection->getProvider() === Connection::GOOGLE) {
			$access = $this->accessToken($connection);
			return [
				'access' => $access,
				'refresh' => $this->crypto->decrypt((string)$connection->getRefreshToken()),
				'expires' => $connection->getTokenExpires(),
			];
		}
		$refresh = $this->crypto->decrypt((string)$connection->getRefreshToken());
		$outlook = $this->tokenRequest(Connection::MICROSOFT, [
			'client_id' => $this->settings->clientId(Connection::MICROSOFT),
			'client_secret' => $this->settings->clientSecret(Connection::MICROSOFT),
			'grant_type' => 'refresh_token',
			'refresh_token' => $refresh,
			'scope' => implode(' ', array_merge(Providers::OUTLOOK_MAIL_SCOPES, ['offline_access'])),
		]);
		return [
			'access' => (string)$outlook['access_token'],
			'refresh' => (string)($outlook['refresh_token'] ?? $refresh),
			'expires' => $this->time->getTime() + (int)($outlook['expires_in'] ?? 3600),
		];
	}

	/** A current access token for the provider's API, refreshed when it's about to expire. */
	public function accessToken(Connection $connection): string {
		if ($connection->getAccessToken() !== null && $connection->getTokenExpires() > $this->time->getTime() + 60) {
			return $this->crypto->decrypt($connection->getAccessToken());
		}
		$refresh = $connection->getRefreshToken();
		if ($refresh === null || $refresh === '') {
			throw new OAuthException('Not signed in', true);
		}
		$provider = $connection->getProvider();
		$form = [
			'client_id' => $this->settings->clientId($provider),
			'client_secret' => $this->settings->clientSecret($provider),
			'grant_type' => 'refresh_token',
			'refresh_token' => $this->crypto->decrypt($refresh),
		];
		if ($provider === Connection::MICROSOFT) {
			$form['scope'] = implode(' ', Providers::GRAPH_SCOPES);
		}
		$tokens = $this->tokenRequest($provider, $form);
		$this->storeTokens($connection, $tokens);
		$this->connections->update($connection);
		return (string)$tokens['access_token'];
	}

	/** Revokes the grant where the provider allows it (Google). Microsoft has no per-app revoke for delegated tokens. */
	public function revoke(Connection $connection): bool {
		if ($connection->getProvider() !== Connection::GOOGLE || $connection->getRefreshToken() === null) {
			return false;
		}
		try {
			$this->clientService->newClient()->post(Providers::GOOGLE_REVOKE, [
				'form_params' => ['token' => $this->crypto->decrypt($connection->getRefreshToken())],
				'timeout' => 20,
			]);
			return true;
		} catch (Throwable $e) {
			$this->logger->info('TeamsLogic: Google token revoke failed: ' . $e->getMessage());
			return false;
		}
	}

	private function storeTokens(Connection $connection, array $tokens): void {
		$connection->setAccessToken($this->crypto->encrypt((string)$tokens['access_token']));
		if (!empty($tokens['refresh_token'])) {
			$connection->setRefreshToken($this->crypto->encrypt((string)$tokens['refresh_token']));
		}
		$connection->setTokenExpires($this->time->getTime() + (int)($tokens['expires_in'] ?? 3600));
	}

	/**
	 * The account's address and name: Google's from the id token (received straight from the
	 * token endpoint over TLS, so its claims are trusted as is), Microsoft's from Graph /me.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function identify(string $provider, array $tokens): array {
		if ($provider === Connection::GOOGLE) {
			$parts = explode('.', (string)($tokens['id_token'] ?? ''));
			$claims = json_decode((string)base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
			$email = is_array($claims) ? (string)($claims['email'] ?? '') : '';
			if ($email === '') {
				throw new OAuthException('Google did not say which account this is');
			}
			return [strtolower($email), (string)($claims['name'] ?? '')];
		}
		try {
			$response = $this->clientService->newClient()->get('https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName,displayName', [
				'headers' => ['Authorization' => 'Bearer ' . $tokens['access_token']],
				'timeout' => 30,
			]);
			$me = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			throw new OAuthException('Could not read the Microsoft account: ' . $e->getMessage(), false, $e);
		}
		$email = (string)($me['mail'] ?? '') ?: (string)($me['userPrincipalName'] ?? '');
		if ($email === '') {
			throw new OAuthException('Microsoft did not say which account this is');
		}
		return [strtolower($email), (string)($me['displayName'] ?? '')];
	}

	/** @return array<string, mixed> */
	private function tokenRequest(string $provider, array $form): array {
		$url = $provider === Connection::GOOGLE ? Providers::GOOGLE_TOKEN : Providers::microsoftToken($this->settings->tenant());
		try {
			$response = $this->clientService->newClient()->post($url, [
				'form_params' => $form,
				'headers' => ['Accept' => 'application/json'],
				'timeout' => 30,
			]);
			$data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$body = method_exists($e, 'getResponse') && $e->getResponse() !== null ? (string)$e->getResponse()->getBody() : '';
			$error = json_decode($body, true);
			$code = is_array($error) ? (string)($error['error'] ?? '') : '';
			$description = is_array($error) ? (string)($error['error_description'] ?? $code) : $e->getMessage();
			throw new OAuthException("Token request failed: $description", $code === 'invalid_grant', $e);
		}
		if (!is_array($data) || empty($data['access_token'])) {
			throw new OAuthException('The token response had no access token');
		}
		return $data;
	}

	private static function b64(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}
}
