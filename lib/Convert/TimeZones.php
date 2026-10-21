<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Convert;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use ReflectionClass;
use Sabre\VObject\TimeZoneUtil;

/**
 * Time zone names: Microsoft Graph speaks Windows names ("GMT Standard Time"), iCalendar and
 * Google speak IANA ("Europe/London"). The Windows ⇄ IANA table is CLDR's, as shipped with
 * sabre/vobject (Nextcloud's copy in the server, ours in the tests).
 */
final class TimeZones {
	/** @var array<string, string>|null Windows name => IANA name */
	private static ?array $windows = null;
	/** @var array<string, string>|null IANA name => Windows name */
	private static ?array $iana = null;

	/** An IANA zone for a Windows or IANA name; UTC for anything unknown. */
	public static function toIana(?string $name): DateTimeZone {
		$name = trim((string)$name);
		if ($name === '' || in_array($name, ['UTC', 'tzone://Microsoft/Utc', 'Etc/UTC', 'Z'], true)) {
			return new DateTimeZone('UTC');
		}
		$windows = self::windows();
		if (isset($windows[$name])) {
			return new DateTimeZone($windows[$name]);
		}
		try {
			return new DateTimeZone($name);
		} catch (Exception) {
			return new DateTimeZone('UTC');
		}
	}

	/** True for a name that means UTC (or is empty). */
	public static function isUtc(?string $name): bool {
		return self::toIana($name)->getName() === 'UTC';
	}

	/**
	 * The Windows name Graph wants for an IANA zone. Zones CLDR maps to a Windows zone only as a
	 * secondary territory (Europe/Madrid) get the Windows zone whose offsets match in January and
	 * July, so recurring meetings keep their wall-clock time across daylight saving.
	 */
	public static function toWindows(DateTimeZone $zone): string {
		$name = $zone->getName();
		if ($name === 'UTC' || $name === 'Z' || $name === '+00:00') {
			return 'UTC';
		}
		$iana = self::iana();
		if (isset($iana[$name])) {
			return $iana[$name];
		}
		$year = (int)gmdate('Y');
		$jan = new DateTimeImmutable("$year-01-15 12:00:00", new DateTimeZone('UTC'));
		$jul = new DateTimeImmutable("$year-07-15 12:00:00", new DateTimeZone('UTC'));
		$want = [$zone->getOffset($jan), $zone->getOffset($jul)];
		foreach (self::windows() as $windows => $ianaName) {
			try {
				$candidate = new DateTimeZone($ianaName);
			} catch (Exception) {
				continue;
			}
			if ([$candidate->getOffset($jan), $candidate->getOffset($jul)] === $want) {
				return $windows;
			}
		}
		return 'UTC';
	}

	/** @return array<string, string> */
	private static function windows(): array {
		if (self::$windows === null) {
			$file = dirname((string)(new ReflectionClass(TimeZoneUtil::class))->getFileName()) . '/timezonedata/windowszones.php';
			self::$windows = is_file($file) ? (array)(require $file) : [];
		}
		return self::$windows;
	}

	/** @return array<string, string> */
	private static function iana(): array {
		if (self::$iana === null) {
			self::$iana = [];
			foreach (self::windows() as $windows => $ianaName) {
				self::$iana[$ianaName] ??= $windows;
			}
			self::$iana['Etc/UTC'] = 'UTC';
		}
		return self::$iana;
	}
}
