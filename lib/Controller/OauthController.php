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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Where Google and Microsoft send the user back after signing in. It's public: the desktop app
 * opens the sign-in in the system browser, which may not be signed in to Nextcloud; the state
 * says whose sign-in it is.
 */
class OauthController extends Controller {
	public function __construct(
		IRequest $request,
		private ConnectionService $connections,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	#[BruteForceProtection(action: 'teamslogic_oauth')]
	#[FrontpageRoute(verb: 'GET', url: '/oauth/{provider}', requirements: ['provider' => 'google|microsoft'])]
	public function callback(string $provider, ?string $code = null, ?string $state = null, ?string $error = null, ?string $error_description = null): TemplateResponse {
		$name = $provider === Connection::GOOGLE ? 'Google' : 'Microsoft';
		if ($error !== null || $code === null || $state === null) {
			$message = $error === 'access_denied' ? 'You cancelled the sign-in.' : ($error_description ?? $error ?? 'The sign-in did not finish.');
			return $this->page(false, $name, '', $message, Http::STATUS_BAD_REQUEST);
		}
		try {
			$connection = $this->connections->finishConnect($provider, $code, $state);
		} catch (OAuthException $e) {
			$response = $this->page(false, $name, '', $e->getMessage(), Http::STATUS_BAD_REQUEST);
			if ($e->getMessage() === 'Invalid state') {
				$response->throttle(['provider' => $provider]);
			}
			return $response;
		} catch (Throwable $e) {
			$this->logger->error('TeamsLogic: connecting ' . $name . ' failed: ' . $e->getMessage(), ['exception' => $e]);
			return $this->page(false, $name, '', 'Something went wrong. Please try again.', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return $this->page(true, $name, $connection->getAccountEmail(), '', Http::STATUS_OK, (bool)$connection->getMailEnabled());
	}

	private function page(bool $ok, string $provider, string $email, string $message, int $status, bool $mail = false): TemplateResponse {
		$response = new TemplateResponse(Application::APP_ID, 'oauth-done', [
			'ok' => $ok,
			'provider' => $provider,
			'email' => $email,
			'message' => $message,
			'mail' => $mail,
		], TemplateResponse::RENDER_AS_GUEST);
		$response->setStatus($status);
		return $response;
	}
}
