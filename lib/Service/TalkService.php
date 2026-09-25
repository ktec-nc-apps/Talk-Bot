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

	/** Talk room types (Talk's Room::TYPE_*). */
	private const ROOM_ONE_TO_ONE = 1;
	private const ROOM_NOTE_TO_SELF = 6;

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
			$this->logger->debug('Talk-Bot: Talk does not know this bot yet: ' . $e->getMessage());
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
			$this->logger->warning('Talk-Bot: could not read the conversation list from Talk: ' . $e->getMessage());
			return [];
		}
	}

	/**
	 * Whether nobody but $userId (and the bot's own account, if one is set) can read
	 * this conversation: a one-to-one or note-to-self room, never a group or public one.
	 */
	public function isPrivateTo(string $token, string $userId, string $botAccount): bool {
		if (!$this->isTalkAvailable() || $token === '' || $userId === '') {
			return false;
		}
		try {
			$q = $this->db->getQueryBuilder();
			$q->select('id', 'type')->from('talk_rooms')->where($q->expr()->eq('token', $q->createNamedParameter($token)));
			$room = $q->executeQuery()->fetch();
			if (!$room || !in_array((int)$room['type'], [self::ROOM_ONE_TO_ONE, self::ROOM_NOTE_TO_SELF], true)) {
				return false;
			}
			$q = $this->db->getQueryBuilder();
			$q->select('actor_type', 'actor_id')->from('talk_attendees')->where($q->expr()->eq('room_id', $q->createNamedParameter((int)$room['id'])));
			$result = $q->executeQuery();
			$me = false;
			$others = true;
			while ($row = $result->fetch()) {
				$isUser = $row['actor_type'] === 'users';
				if ($isUser && $row['actor_id'] === $userId) {
					$me = true;
				} elseif (!($isUser && $botAccount !== '' && $row['actor_id'] === $botAccount)) {
					$others = false;
				}
			}
			$result->closeCursor();
			return $me && $others;
		} catch (\Throwable $e) {
			$this->logger->warning('Talk-Bot: could not read the conversation from Talk: ' . $e->getMessage());
			return false;
		}
	}

	/** Whether $userId is an owner or moderator of the conversation. */
	public function isModerator(string $token, string $userId): bool {
		if (!$this->isTalkAvailable() || $token === '' || $userId === '') {
			return false;
		}
		try {
			$q = $this->db->getQueryBuilder();
			$q->select('a.participant_type')->from('talk_attendees', 'a')
				->innerJoin('a', 'talk_rooms', 'r', $q->expr()->eq('a.room_id', 'r.id'))
				->where($q->expr()->eq('r.token', $q->createNamedParameter($token)))
				->andWhere($q->expr()->eq('a.actor_type', $q->createNamedParameter('users')))
				->andWhere($q->expr()->eq('a.actor_id', $q->createNamedParameter($userId)));
			$type = $q->executeQuery()->fetchOne();
			// Talk's Participant::OWNER = 1, MODERATOR = 2
			return in_array((int)$type, [1, 2], true);
		} catch (\Throwable $e) {
			$this->logger->warning('Talk-Bot: could not read the conversation from Talk: ' . $e->getMessage());
			return false;
		}
	}
}
