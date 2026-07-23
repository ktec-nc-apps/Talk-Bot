<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'tool#models', 'url' => '/tools/models', 'verb' => 'GET'],
		['name' => 'tool#test', 'url' => '/tools/test', 'verb' => 'POST'],
		['name' => 'tool#setModel', 'url' => '/tools/model', 'verb' => 'POST'],
		['name' => 'process#process', 'url' => '/process', 'verb' => 'POST'],
	],
];
