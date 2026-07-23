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
 * Announces the bot to Talk on install and update.
 *
 * Talk keeps in-app bots in its own table, so this is what makes "Talk-Bot"
 * appear in the bot list of every conversation. Moderators still have to switch
 * it on per conversation.
 */
class RegisterBot implements IRepairStep {

	private const NAME = 'Talk-Bot';

	public function __construct(
		private IEventDispatcher $dispatcher,
		private ConfigService $config,
	) {
	}

	public function getName(): string {
		return 'Register the Talk-Bot with Nextcloud Talk';
	}

	public function run(IOutput $output): void {
		$eventClass = 'OCA\Talk\Events\BotInstallEvent';
		if (!class_exists($eventClass)) {
			$output->info('Nextcloud Talk is not installed; the bot will be registered once it is.');
			return;
		}

		// Talk needs both: EVENT to call us, RESPONSE to accept the answer we post
		// back once the model has replied.
		$features = 4 | 2;

		try {
			$this->dispatcher->dispatchTyped(new $eventClass(
				self::NAME,
				$this->config->getBotSecret(),
				Application::BOT_URL,
				'Chat with Claude, Gemini or an OpenAI-compatible model.',
				$features,
			));
			$output->info('Talk-Bot registered with Nextcloud Talk.');
		} catch (\Throwable $e) {
			$output->warning('Could not register the Talk-Bot with Nextcloud Talk: ' . $e->getMessage());
		}
	}
}
