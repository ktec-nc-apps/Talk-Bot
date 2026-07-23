<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Service;

use OCP\IL10N;

/**
 * The handful of slash commands the bot answers itself.
 *
 * Anything that is not a known command is passed on to the model, so a message
 * that merely starts with a slash still gets a real answer.
 */
class CommandService {

	public function __construct(
		private SessionService $sessions,
		private ConfigService $config,
	) {
	}

	/**
	 * @param IL10N $l In the language of the user who wrote the message.
	 * @return string|null The reply to post, or null when this is not a command.
	 */
	public function handle(string $text, string $token, string $userId, IL10N $l): ?string {
		$trimmed = trim($text);
		if (!str_starts_with($trimmed, '/')) {
			return null;
		}
		$command = strtolower(strtok($trimmed, " \t\n") ?: '');

		switch ($command) {
			case '/help':
				return $this->help($l);

			case '/reset':
			case '/clear':
				$this->sessions->reset($token, $userId);
				return $l->t('Conversation reset. I have forgotten what we talked about.');

			case '/status':
				return $this->status($token, $userId, $l);

			default:
				return null;
		}
	}

	private function help(IL10N $l): string {
		return implode("\n", [
			'**' . $l->t('Talk-Bot commands') . '**',
			'- `/help` — ' . $l->t('show this help'),
			'- `/reset` — ' . $l->t('forget the conversation and start over'),
			'- `/status` — ' . $l->t('show the model in use and the size of this conversation'),
			'',
			$l->t('Anything else you write is answered by the model.'),
		]);
	}

	private function status(string $token, string $userId, IL10N $l): string {
		$provider = $this->config->getProvider();
		$mode = $this->config->getMode();
		$model = $this->config->getModel();

		return implode("\n", [
			$l->t('Engine: %s', [$provider . ' (' . $mode . ')']),
			$l->t('Model: %s', [$model === '' ? '—' : $model]),
			$l->n('Remembered exchanges: %n', 'Remembered exchanges: %n', $this->sessions->countTurns($token, $userId)),
		]);
	}
}
