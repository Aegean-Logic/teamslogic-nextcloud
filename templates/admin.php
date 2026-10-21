<?php
/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
$s = $_['settings'];
?>
<div id="teamslogic-admin" class="section" data-save-url="<?php p($_['saveUrl']); ?>">
	<h2><?php p($l->t('TeamsLogic: Google and Microsoft 365')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Let people connect their Google or Microsoft 365 account from TeamsLogic. Their calendars and contacts sync both ways with Nextcloud, and the same sign-in can set up the account in Nextcloud Mail. Register an OAuth app with each provider and enter its client below; the README lists the exact scopes.')); ?>
	</p>

	<form id="teamslogic-admin-form" autocomplete="off">
		<h3><?php p($l->t('Google')); ?></h3>
		<p class="settings-hint"><?php p($l->t('Google Cloud console › APIs & Services › Credentials › OAuth client ID (Web application). Enable the Google Calendar API and the People API (and the Gmail API for mail).')); ?></p>
		<div class="teamslogic-field">
			<label for="teamslogic-google-id"><?php p($l->t('Client ID')); ?></label>
			<input id="teamslogic-google-id" name="google_client_id" type="text" value="<?php p($s['googleClientId']); ?>" placeholder="123456789-abc.apps.googleusercontent.com">
		</div>
		<div class="teamslogic-field">
			<label for="teamslogic-google-secret"><?php p($l->t('Client secret')); ?></label>
			<input id="teamslogic-google-secret" name="google_client_secret" type="password"
				placeholder="<?php p($s['googleSecretSet'] ? $l->t('Saved (leave empty to keep)') : ''); ?>">
		</div>
		<div class="teamslogic-field">
			<label for="teamslogic-google-redirect"><?php p($l->t('Authorised redirect URI')); ?></label>
			<input id="teamslogic-google-redirect" type="text" readonly value="<?php p($s['googleRedirectUri']); ?>" class="teamslogic-copy">
		</div>

		<h3><?php p($l->t('Microsoft 365')); ?></h3>
		<p class="settings-hint"><?php p($l->t('Microsoft Entra admin center › App registrations › New registration (Web platform). Add a client secret and the delegated permissions listed in the README.')); ?></p>
		<div class="teamslogic-field">
			<label for="teamslogic-ms-id"><?php p($l->t('Application (client) ID')); ?></label>
			<input id="teamslogic-ms-id" name="microsoft_client_id" type="text" value="<?php p($s['microsoftClientId']); ?>" placeholder="00000000-0000-0000-0000-000000000000">
		</div>
		<div class="teamslogic-field">
			<label for="teamslogic-ms-secret"><?php p($l->t('Client secret')); ?></label>
			<input id="teamslogic-ms-secret" name="microsoft_client_secret" type="password"
				placeholder="<?php p($s['microsoftSecretSet'] ? $l->t('Saved (leave empty to keep)') : ''); ?>">
		</div>
		<div class="teamslogic-field">
			<label for="teamslogic-ms-tenant"><?php p($l->t('Tenant')); ?></label>
			<input id="teamslogic-ms-tenant" name="microsoft_tenant" type="text" value="<?php p($s['microsoftTenant']); ?>" placeholder="common">
			<span class="settings-hint"><?php p($l->t('"common" for any work, school or personal account; "organizations" for work and school only; or your tenant ID or domain.')); ?></span>
		</div>
		<div class="teamslogic-field">
			<label for="teamslogic-ms-redirect"><?php p($l->t('Redirect URI')); ?></label>
			<input id="teamslogic-ms-redirect" type="text" readonly value="<?php p($s['microsoftRedirectUri']); ?>" class="teamslogic-copy">
		</div>

		<h3><?php p($l->t('Sync')); ?></h3>
		<div class="teamslogic-field">
			<label for="teamslogic-interval"><?php p($l->t('Sync every (minutes)')); ?></label>
			<input id="teamslogic-interval" name="sync_interval" type="number" min="1" max="1440" value="<?php p((string)$s['syncInterval']); ?>">
			<span class="settings-hint"><?php p($l->t('Background sync runs with Nextcloud\'s background jobs: use cron for an interval this short.')); ?></span>
		</div>
		<div class="teamslogic-field teamslogic-field--check">
			<input id="teamslogic-mail" name="connect_mail" type="checkbox" class="checkbox" <?php if ($s['connectMail']) { print_unescaped('checked'); } ?>>
			<label for="teamslogic-mail"><?php p($l->t('Also connect mail: set up the account in Nextcloud Mail, and use these clients for Mail\'s Google and Microsoft sign-in')); ?></label>
		</div>
		<?php if (!$_['mailAvailable']): ?>
			<p class="settings-hint"><?php p($l->t('Nextcloud Mail (5.x) is not installed or enabled, so mail is skipped.')); ?></p>
		<?php endif; ?>

		<button type="submit" class="button primary"><?php p($l->t('Save')); ?></button>
		<span id="teamslogic-admin-status" class="teamslogic-status" role="status"></span>
	</form>
</div>
