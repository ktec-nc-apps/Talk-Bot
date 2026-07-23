<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Service;

use OCA\TalkBot\AppInfo\Application;
use OCP\IDBConnection;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * What Talk knows about our bot.
 *
 * Everything here degrades to "unknown" rather than throwing: the app has to
 * stay usable, and its settings page has to render, on a server where Talk is
 * missing, disabled or a version whose internals moved.
 */
class TalkService {

	private const STATES = [
		0 => 'disabled',
		1 => 'enabled',
		2 => 'not set up',
		3 => 'unavailable',
	];

	private const FEATURES = [
		1 => 'webhook',
		2 => 'response',
		4 => 'event',
		8 => 'reaction',
	];

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	public function isTalkAvailable(): bool {
		return class_exists('OCA\Talk\Model\BotServerMapper');
	}

	/**
	 * Our own registration, as Talk stores it.
	 *
	 * @return array{id: int, name: string, state: string, features: list<string>, errorCount: int, lastError: string}|null
	 */
	public function getBot(): ?array {
		if (!$this->isTalkAvailable()) {
			return null;
		}

		try {
			/** @var \OCA\Talk\Model\BotServerMapper $mapper */
			$mapper = Server::get('OCA\Talk\Model\BotServerMapper');
			$bot = $mapper->findByUrl(Application::BOT_URL);
		} catch (\Throwable $e) {
			$this->logger->debug('Talk Bot: Talk does not know this bot yet: ' . $e->getMessage());
			return null;
		}

		$features = [];
		foreach (self::FEATURES as $bit => $label) {
			if (($bot->getFeatures() & $bit) === $bit) {
				$features[] = $label;
			}
		}

		return [
			'id' => $bot->getId(),
			'name' => $bot->getName(),
			'state' => self::STATES[$bot->getState()] ?? ('unknown (' . $bot->getState() . ')'),
			'features' => $features,
			'errorCount' => $bot->getErrorCount(),
			'lastError' => (string)$bot->getLastErrorMessage(),
		];
	}

	/**
	 * The conversations a moderator switched this bot on in.
	 *
	 * @return list<array{token: string, name: string, state: string}>
	 */
	public function getRooms(int $botId): array {
		if (!$this->isTalkAvailable()) {
			return [];
		}

		try {
			$query = $this->db->getQueryBuilder();
			$query->select('c.token', 'c.state', 'r.name')
				->from('talk_bots_conversation', 'c')
				->leftJoin('c', 'talk_rooms', 'r', $query->expr()->eq('c.token', 'r.token'))
				->where($query->expr()->eq('c.bot_id', $query->createNamedParameter($botId)))
				->orderBy('c.token');

			$result = $query->executeQuery();
			$rooms = [];
			while ($row = $result->fetch()) {
				$rooms[] = [
					'token' => (string)$row['token'],
					'name' => (string)($row['name'] ?? ''),
					'state' => self::STATES[(int)$row['state']] ?? 'unknown',
				];
			}
			$result->closeCursor();
			return $rooms;
		} catch (\Throwable $e) {
			$this->logger->warning('Talk Bot: could not read the conversation list from Talk: ' . $e->getMessage());
			return [];
		}
	}
}
