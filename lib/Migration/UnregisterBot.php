<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Migration;

use OCA\TalkBot\AppInfo\Application;
use OCA\TalkBot\Service\ConfigService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Takes the bot out of Talk when the app is removed, so no conversation keeps a
 * "Talk-Bot" that can no longer answer (review T17).
 */
class UnregisterBot implements IRepairStep {
	public function __construct(
		private IEventDispatcher $dispatcher,
		private ConfigService $config,
	) {
	}

	public function getName(): string {
		return 'Remove the Talk-Bot from Nextcloud Talk';
	}

	public function run(IOutput $output): void {
		$eventClass = 'OCA\Talk\Events\BotUninstallEvent';
		if (!class_exists($eventClass)) {
			return;
		}
		try {
			$this->dispatcher->dispatchTyped(new $eventClass($this->config->getBotSecret(), Application::BOT_URL));
			$output->info('Talk-Bot removed from Nextcloud Talk.');
		} catch (\Throwable $e) {
			$output->warning('Could not remove the Talk-Bot from Nextcloud Talk: ' . $e->getMessage());
		}
	}
}
