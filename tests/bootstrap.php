<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aegean Logic
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The converters and the planner need only sabre/vobject, so the unit tests run without a
// Nextcloud server: `composer install && vendor/bin/phpunit`.
require_once __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('UTC');
