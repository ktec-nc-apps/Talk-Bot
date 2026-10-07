<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Command;

use OC\Core\Command\Base;
use OCA\TalkBot\Service\ConfigService;
use OCA\TalkBot\Service\HubClient;
use OCA\TalkBot\Service\TalkService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** `occ ktec_talkbot:status` — everything you need to answer "why is it not replying?". */
class Status extends Base {

	public function __construct(
		private ConfigService $config,
		private TalkService $talk,
		private HubClient $hub,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		parent::configure();
		$this
			->setName('ktec_talkbot:status')
			->setDescription('Show how the bot reaches AI-Hub and how Talk sees it');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$bot = $this->talk->getBot();
		$hub = $this->hub->status();

		if ($hub === null) {
			$aiHub = 'required, but not installed';
		} elseif (empty($hub['ready'])) {
			$aiHub = 'installed, but not set up (reason: ' . ($hub['reason'] ?: 'unknown') . ')';
		} else {
			$aiHub = 'ready — ' . $hub['provider'] . ' / ' . $hub['mode'] . ', model ' . ($hub['model'] === '' ? '(none)' : $hub['model']);
		}

		$data = [
			'ai_hub' => $aiHub,
			'reply_language' => $this->config->getReplyLanguage() === '' ? 'follow the user' : $this->config->getReplyLanguage(),
			'allow_list' => $this->config->isAllowlistEnabled()
				? implode(', ', $this->config->getAllowedUsers()) ?: '(enabled but empty: nobody may use the bot)'
				: 'off (everyone in the conversation)',
		];

		if (!$this->talk->isTalkAvailable()) {
			$data['talk'] = 'Nextcloud Talk is not installed or not enabled';
		} elseif ($bot === null) {
			$data['talk'] = 'not registered yet — run occ app:update ktec_talkbot or re-enable the app';
		} else {
			$data['bot_id'] = $bot['id'];
			$data['bot_state'] = $bot['state'];
			$data['bot_features'] = implode(', ', $bot['features']);
			$data['conversations'] = count($this->talk->getRooms($bot['id']));
			if ($bot['errorCount'] > 0) {
				$data['bot_errors'] = $bot['errorCount'] . ' (' . $bot['lastError'] . ')';
			}
		}

		$this->writeArrayInOutputFormat($input, $output, $data);
		return 0;
	}
}
