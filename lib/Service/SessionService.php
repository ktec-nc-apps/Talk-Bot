<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Conversation memory: one history per (conversation, user).
 *
 * Kept deliberately small — only the last few turns are replayed to the model,
 * and anything older is dropped on write rather than accumulating forever.
 */
class SessionService {

	public const TABLE = 'talkbot_sessions';

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
		private ConfigService $config,
	) {
	}

	/**
	 * @return list<array{role: string, text: string}> Oldest first.
	 */
	public function getHistory(string $token, string $userId): array {
		$query = $this->db->getQueryBuilder();
		$query->select('history')
			->from(self::TABLE)
			->where($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
			->setMaxResults(1);

		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false || !is_string($row['history'])) {
			return [];
		}
		$decoded = json_decode($row['history'], true);
		if (!is_array($decoded)) {
			return [];
		}

		$history = [];
		foreach ($decoded as $turn) {
			if (is_array($turn) && isset($turn['role'], $turn['text']) && is_string($turn['text'])) {
				$history[] = [
					'role' => $turn['role'] === 'assistant' ? 'assistant' : 'user',
					'text' => $turn['text'],
				];
			}
		}
		return $this->trim($history);
	}

	/** Append one exchange, keeping only the most recent turns. */
	public function appendTurn(string $token, string $userId, string $question, string $answer): void {
		$history = $this->getHistory($token, $userId);
		$history[] = ['role' => 'user', 'text' => $question];
		$history[] = ['role' => 'assistant', 'text' => $answer];
		$this->store($token, $userId, $this->trim($history));
	}

	public function reset(string $token, string $userId): void {
		$query = $this->db->getQueryBuilder();
		$query->delete(self::TABLE)
			->where($query->expr()->eq('token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($userId)));
		$query->executeStatement();
	}

	public function countTurns(string $token, string $userId): int {
		return (int)floor(count($this->getHistory($token, $userId)) / 2);
	}

	/** @param list<array{role: string, text: string}> $history */
	private function store(string $token, string $userId, array $history): void {
		$now = $this->timeFactory->getTime();
		$encoded = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($encoded === false) {
			return;
		}

		$update = $this->db->getQueryBuilder();
		$update->update(self::TABLE)
			->set('history', $update->createNamedParameter($encoded))
			->set('updated_at', $update->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($update->expr()->eq('token', $update->createNamedParameter($token)))
			->andWhere($update->expr()->eq('user_id', $update->createNamedParameter($userId)));

		if ($update->executeStatement() > 0) {
			return;
		}

		$insert = $this->db->getQueryBuilder();
		$insert->insert(self::TABLE)
			->values([
				'token' => $insert->createNamedParameter($token),
				'user_id' => $insert->createNamedParameter($userId),
				'history' => $insert->createNamedParameter($encoded),
				'updated_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			]);
		try {
			$insert->executeStatement();
		} catch (\Throwable) {
			// A concurrent turn inserted the row first; its content is equally valid.
		}
	}

	/**
	 * @param list<array{role: string, text: string}> $history
	 * @return list<array{role: string, text: string}>
	 */
	private function trim(array $history): array {
		$max = $this->config->getMaxHistoryTurns() * 2;
		if ($max <= 0 || count($history) <= $max) {
			return array_values($history);
		}
		return array_values(array_slice($history, -$max));
	}
}
