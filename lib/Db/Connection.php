<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A user's connected Google or Microsoft account.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getProvider()
 * @method void setProvider(string $provider)
 * @method string getAccountEmail()
 * @method void setAccountEmail(string $accountEmail)
 * @method string|null getAccessToken()
 * @method void setAccessToken(?string $accessToken)
 * @method string|null getRefreshToken()
 * @method void setRefreshToken(?string $refreshToken)
 * @method int getTokenExpires()
 * @method void setTokenExpires(int $tokenExpires)
 * @method string|null getScopes()
 * @method void setScopes(?string $scopes)
 * @method bool|null getMailEnabled()
 * @method void setMailEnabled(bool $mailEnabled)
 * @method bool|null getCalendarEnabled()
 * @method void setCalendarEnabled(bool $calendarEnabled)
 * @method bool|null getContactsEnabled()
 * @method void setContactsEnabled(bool $contactsEnabled)
 * @method int|null getMailAccountId()
 * @method void setMailAccountId(?int $mailAccountId)
 * @method string|null getSyncState()
 * @method void setSyncState(?string $syncState)
 * @method int getLastSync()
 * @method void setLastSync(int $lastSync)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Connection extends Entity implements JsonSerializable {
	public const GOOGLE = 'google';
	public const MICROSOFT = 'microsoft';
	public const PROVIDERS = [self::GOOGLE, self::MICROSOFT];

	protected $userId = '';
	protected $provider = '';
	protected $accountEmail = '';
	protected $accessToken;
	protected $refreshToken;
	protected $tokenExpires = 0;
	protected $scopes;
	protected $mailEnabled = false;
	protected $calendarEnabled = true;
	protected $contactsEnabled = true;
	protected $mailAccountId;
	protected $syncState;
	protected $lastSync = 0;
	protected $lastError;
	protected $createdAt = 0;

	public function __construct() {
		$this->addType('tokenExpires', 'integer');
		$this->addType('mailEnabled', 'boolean');
		$this->addType('calendarEnabled', 'boolean');
		$this->addType('contactsEnabled', 'boolean');
		$this->addType('mailAccountId', 'integer');
		$this->addType('lastSync', 'integer');
		$this->addType('createdAt', 'integer');
	}

	/** @return array<string, array<string, array<string, mixed>>> kind => remote collection id => state */
	public function getState(): array {
		$state = json_decode((string)$this->getSyncState(), true);
		return is_array($state) ? $state : [];
	}

	public function setState(array $state): void {
		$this->setSyncState(json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}

	/** @return list<string> */
	public function getScopeList(): array {
		return array_values(array_filter(explode(' ', (string)$this->getScopes())));
	}

	/** What the desktop app sees: never the tokens. */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'provider' => $this->getProvider(),
			'email' => $this->getAccountEmail(),
			'mail' => (bool)$this->getMailEnabled(),
			'calendar' => (bool)$this->getCalendarEnabled(),
			'contacts' => (bool)$this->getContactsEnabled(),
			'mailAccountId' => $this->getMailAccountId(),
			'lastSync' => $this->getLastSync() ?: null,
			'lastError' => $this->getLastError() ?: null,
		];
	}
}
