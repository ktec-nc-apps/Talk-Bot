<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Command;

use OC\Core\Command\Base;
use OCA\TalkBot\Engine\EngineFactory;
use OCA\TalkBot\Service\ConfigService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** `occ talkbot:test` — the connection test from the settings page, on the CLI. */
class Test extends Base {

	public function __construct(
		private ConfigService $config,
		private EngineFactory $engineFactory,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		parent::configure();
		$this
			->setName('talkbot:test')
			->setDescription('Ask the configured AI service one question and print what comes back')
			->addOption(
				'prompt',
				'p',
				InputOption::VALUE_REQUIRED,
				'What to ask',
				'Reply with the single word: ok',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$model = $this->config->getModel();
		if ($model === '') {
			$output->writeln('<error>No model is selected. Pick one in Administration settings → Talk-Bot.</error>');
			return 1;
		}

		$engine = $this->engineFactory->get();
		$started = microtime(true);
		$result = $engine->run([], (string)$input->getOption('prompt'), 'You are a connection test. Answer briefly.');
		$seconds = round(microtime(true) - $started, 1);

		$data = [
			'engine' => $engine->getName(),
			'model' => $model,
			'result' => $result->kind,
			'seconds' => $seconds,
			'reply' => $result->output,
			'detail' => $result->detail,
		];

		if ($input->getOption('output') !== self::OUTPUT_FORMAT_PLAIN) {
			$this->writeArrayInOutputFormat($input, $output, $data);
			return $result->isOk() ? 0 : 1;
		}

		$output->writeln(sprintf('%s / %s answered in %ss', $data['engine'], $model, $seconds));
		if ($result->isOk()) {
			$output->writeln('<info>' . $result->output . '</info>');
			return 0;
		}
		$output->writeln('<error>' . $result->kind . ': ' . $result->detail . '</error>');
		return 1;
	}
}
