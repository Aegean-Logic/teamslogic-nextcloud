<?php
/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** @var array $_ */
/** @var \OCP\IL10N $l */
\OCP\Util::addStyle('teamslogic', 'oauth');
?>
<div class="guest-box teamslogic-oauth">
	<?php if ($_['ok']): ?>
		<h2><?php p($l->t('Connected')); ?></h2>
		<p><?php p($l->t('%1$s is connected to Nextcloud as %2$s.', [$_['provider'], $_['email']])); ?></p>
		<p><?php p($_['mail']
			? $l->t('Your calendars, contacts and mail will appear in a few minutes.')
			: $l->t('Your calendars and contacts will appear in a few minutes.')); ?></p>
		<p class="teamslogic-oauth__close"><?php p($l->t('You can close this window.')); ?></p>
	<?php else: ?>
		<h2><?php p($l->t('Not connected')); ?></h2>
		<p><?php p($l->t('%s could not be connected.', [$_['provider']])); ?></p>
		<p class="teamslogic-oauth__error"><?php p($_['message']); ?></p>
		<p><?php p($l->t('Close this window and try again from TeamsLogic.')); ?></p>
	<?php endif; ?>
</div>
