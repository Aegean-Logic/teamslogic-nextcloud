<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\OAuth;

use OCA\TeamsLogic\Db\Connection;

/**
 * Endpoints and scopes for Google and the Microsoft identity platform.
 *
 * Microsoft issues an access token for one resource at a time: the code is redeemed for Graph
 * (calendars, contacts) and the same refresh token is then redeemed again for Outlook
 * (IMAP/SMTP), which is the pair Nextcloud Mail gets.
 */
final class Providers {
	public const GOOGLE_AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';
	public const GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';
	public const GOOGLE_REVOKE = 'https://oauth2.googleapis.com/revoke';

	public const GOOGLE_SCOPES = [
		// The account's address, from the id token.
		'openid',
		'email',
		'https://www.googleapis.com/auth/calendar',
		'https://www.googleapis.com/auth/contacts',
		self::GOOGLE_TASKS_SCOPE,
	];
	public const GOOGLE_MAIL_SCOPES = ['https://mail.google.com/'];
	public const GOOGLE_TASKS_SCOPE = 'https://www.googleapis.com/auth/tasks';

	public const GRAPH_SCOPES = [
		'https://graph.microsoft.com/Calendars.ReadWrite',
		'https://graph.microsoft.com/Contacts.ReadWrite',
		self::GRAPH_TASKS_SCOPE,
		'https://graph.microsoft.com/User.Read',
		'offline_access',
	];
	public const GRAPH_TASKS_SCOPE = 'https://graph.microsoft.com/Tasks.ReadWrite';
	public const OUTLOOK_MAIL_SCOPES = [
		'https://outlook.office.com/IMAP.AccessAsUser.All',
		'https://outlook.office.com/SMTP.Send',
	];

	public static function microsoftAuthorize(string $tenant): string {
		return 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/authorize';
	}

	public static function microsoftToken(string $tenant): string {
		return 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
	}

	/** @return list<string> what the user is asked to grant */
	public static function scopes(string $provider, bool $mail): array {
		if ($provider === Connection::GOOGLE) {
			return $mail ? array_merge(self::GOOGLE_SCOPES, self::GOOGLE_MAIL_SCOPES) : self::GOOGLE_SCOPES;
		}
		return $mail ? array_merge(self::GRAPH_SCOPES, self::OUTLOOK_MAIL_SCOPES) : self::GRAPH_SCOPES;
	}

	/** Whether the granted scopes include tasks (accounts connected before tasks were added don't). */
	public static function grantsTasks(string $provider, array $granted): bool {
		$scope = strtolower($provider === Connection::GOOGLE ? self::GOOGLE_TASKS_SCOPE : self::GRAPH_TASKS_SCOPE);
		return in_array($scope, array_map('strtolower', $granted), true);
	}

	/**
	 * The Graph scopes to refresh a token with: those the user granted (Microsoft refuses a refresh
	 * that asks for one they didn't), or the defaults for a connection that doesn't record them.
	 *
	 * @return list<string>
	 */
	public static function graphRefreshScopes(array $granted): array {
		$graph = array_values(array_filter($granted, static fn ($scope) => stripos($scope, 'https://graph.microsoft.com/') === 0));
		if ($graph === []) {
			return array_values(array_diff(self::GRAPH_SCOPES, [self::GRAPH_TASKS_SCOPE]));
		}
		return array_merge($graph, ['offline_access']);
	}

	/** Whether the granted scopes include mail. */
	public static function grantsMail(string $provider, array $granted): bool {
		$needed = $provider === Connection::GOOGLE ? self::GOOGLE_MAIL_SCOPES : self::OUTLOOK_MAIL_SCOPES;
		$granted = array_map('strtolower', $granted);
		foreach ($needed as $scope) {
			if (!in_array(strtolower($scope), $granted, true)) {
				return false;
			}
		}
		return true;
	}
}
