<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Command;

use OC\Core\Command\Base;
use OCA\TalkBot\Service\ConfigService;
use OCA\TalkBot\Service\TalkService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** `occ talkbot:status` — everything you need to answer "why is it not replying?". */
class Status extends Base {

	public function __construct(
		private ConfigService $config,
		private TalkService $talk,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		parent::configure();
		$this
			->setName('talkbot:status')
			->setDescription('Show the configured engine and how Talk sees the bot');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$provider = $this->config->getProvider();
		$mode = $this->config->getMode();
		$bot = $this->talk->getBot();

		$data = [
			'provider' => $provider,
			'mode' => $mode,
			'model' => $this->config->getModel(),
			'api_key' => $this->config->getApiKey() === '' ? 'not set' : 'set',
			'reply_language' => $this->config->getReplyLanguage() === '' ? 'follow the user' : $this->config->getReplyLanguage(),
			'allow_list' => $this->config->isAllowlistEnabled()
				? implode(', ', $this->config->getAllowedUsers()) ?: '(enabled but empty: nobody may use the bot)'
				: 'off (everyone in the conversation)',
			'cli_allowed' => $this->config->isCliEnabled() ? 'yes' : 'no',
		];

		if ($mode === 'cli') {
			$data['cli_path'] = $this->config->getCliPath();
			$data['cli_home'] = $this->config->getCliHome() === '' ? '(default)' : $this->config->getCliHome();
		}

		if (!$this->talk->isTalkAvailable()) {
			$data['talk'] = 'Nextcloud Talk is not installed or not enabled';
		} elseif ($bot === null) {
			$data['talk'] = 'not registered yet — run occ app:update talkbot or re-enable the app';
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
