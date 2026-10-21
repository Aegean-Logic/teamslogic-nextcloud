<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Settings;

use OCA\TeamsLogic\AppInfo\Application;
use OCA\TeamsLogic\Service\MailSetup;
use OCA\TeamsLogic\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Administration settings › Groupware › TeamsLogic.
 */
class Admin implements ISettings {
	public function __construct(
		private SettingsService $settings,
		private MailSetup $mail,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'teamslogic-admin');
		Util::addStyle(Application::APP_ID, 'admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [
			'settings' => $this->settings->forForm(),
			'mailAvailable' => $this->mail->isAvailable(),
			'saveUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin.save'),
		]);
	}

	public function getSection(): string {
		return 'groupware';
	}

	public function getPriority(): int {
		return 60;
	}
}
