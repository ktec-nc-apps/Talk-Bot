<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Command;

use OC\Core\Command\Base;
use OCA\TalkBot\Service\SessionService;
use OCA\TalkBot\Service\TalkService;
use OCP\IDBConnection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** `occ talkbot:rooms` — the conversations the bot was switched on in. */
class Rooms extends Base {

	public function __construct(
		private TalkService $talk,
		private IDBConnection $db,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		parent::configure();
		$this
			->setName('talkbot:rooms')
			->setDescription('List the conversations this bot is enabled in');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->talk->isTalkAvailable()) {
			$output->writeln('<error>Nextcloud Talk is not installed or not enabled.</error>');
			return 1;
		}

		$bot = $this->talk->getBot();
		if ($bot === null) {
			$output->writeln('<error>Talk does not know this bot yet. Re-enable the app to register it.</error>');
			return 1;
		}

		$rooms = $this->talk->getRooms($bot['id']);
		if ($rooms === []) {
			if ($input->getOption('output') === self::OUTPUT_FORMAT_PLAIN) {
				$output->writeln('The bot is not switched on in any conversation yet.');
				$output->writeln('A moderator enables it under Conversation settings → Bots.');
				return 0;
			}
			$this->writeArrayInOutputFormat($input, $output, []);
			return 0;
		}

		$rows = [];
		foreach ($rooms as $room) {
			$rows[] = [
				'token' => $room['token'],
				'conversation' => $room['name'],
				'state' => $room['state'],
				'remembered users' => $this->countUsers($room['token']),
			];
		}

		$this->writeTableInOutputFormat($input, $output, $rows);
		return 0;
	}

	/** How many people have a conversation history with the bot in that room. */
	private function countUsers(string $token): int {
		try {
			$query = $this->db->getQueryBuilder();
			$query->select($query->func()->count('*', 'total'))
				->from(SessionService::TABLE)
				->where($query->expr()->eq('token', $query->createNamedParameter($token)));
			$result = $query->executeQuery();
			$total = (int)$result->fetchOne();
			$result->closeCursor();
			return $total;
		} catch (\Throwable) {
			return 0;
		}
	}
}
