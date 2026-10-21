<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Controller;

use OCA\TeamsLogic\AppInfo\Application;
use OCA\TeamsLogic\Db\Connection;
use OCA\TeamsLogic\Service\MailSetup;
use OCA\TeamsLogic\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Saves the admin settings (admins only: no NoAdminRequired).
 */
class AdminController extends Controller {
	public function __construct(
		IRequest $request,
		private SettingsService $settings,
		private MailSetup $mail,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[FrontpageRoute(verb: 'POST', url: '/admin/settings')]
	public function save(): DataResponse {
		$values = [];
		foreach ([
			SettingsService::GOOGLE_CLIENT_ID, SettingsService::GOOGLE_CLIENT_SECRET,
			SettingsService::MICROSOFT_CLIENT_ID, SettingsService::MICROSOFT_CLIENT_SECRET, SettingsService::MICROSOFT_TENANT,
			SettingsService::SYNC_INTERVAL, SettingsService::CONNECT_MAIL,
		] as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$values[$key] = $value;
			}
		}
		foreach ([Connection::GOOGLE, Connection::MICROSOFT] as $provider) {
			if ($this->request->getParam('clear_' . $provider . '_secret')) {
				$this->settings->clearSecret($provider);
			}
		}
		$this->settings->save($values);

		$mailConfigured = false;
		if ($this->settings->connectMail()) {
			$mailConfigured = $this->mail->configureClients($this->settings);
		}
		return new DataResponse($this->settings->forForm() + ['mailConfigured' => $mailConfigured]);
	}
}
