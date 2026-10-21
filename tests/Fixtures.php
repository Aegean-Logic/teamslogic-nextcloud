<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TeamsLogic\Tests;

final class Fixtures {
	/** @return array<string, mixed> */
	public static function json(string $name): array {
		$data = json_decode((string)file_get_contents(__DIR__ . '/fixtures/' . $name), true, 512, JSON_THROW_ON_ERROR);
		return (array)$data;
	}

	public static function text(string $name): string {
		return (string)file_get_contents(__DIR__ . '/fixtures/' . $name);
	}
}
