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
		$command = $this->parseCommand($text);
		if ($command === null) {
			return null;
		}

		switch ($command) {
			case 'help':
				return $this->help($l);

			case 'reset':
			case 'clear':
				$this->sessions->reset($token, $userId);
				return $l->t('Conversation reset. I have forgotten what we talked about.');

			case 'status':
				return $this->status($token, $userId, $l);

			default:
				return null;
		}
	}

	/**
	 * Recognise a command word in a message.
	 *
	 * Talk's message box pops up a command menu the moment you type "/", which
	 * makes a bare "/help" awkward to send (it can end up linkified or swallowed).
	 * So a command is accepted in three shapes, as long as it is the whole
	 * message: "/help", the bare word "help", or a linkified "[/help](/help)".
	 * Anything with extra words ("help me with…") is left for the model.
	 */
	private function parseCommand(string $text): ?string {
		$candidate = trim($text);
		if ($candidate === '') {
			return null;
		}
		// Unwrap a markdown link such as [/help](/help): keep the visible label.
		if (preg_match('/^\[([^\]]+)]\([^)]*\)$/', $candidate, $m) === 1) {
			$candidate = trim($m[1]);
		}
		// Drop surrounding code backticks and stray whitespace.
		$candidate = trim($candidate, "` \t");

		// A command is a single word, optionally led by slashes.
		if (preg_match('#^/*([a-zA-Z]+)$#', $candidate, $m) !== 1) {
			return null;
		}
		$word = strtolower($m[1]);
		if (!in_array($word, ['help', 'reset', 'clear', 'status'], true)) {
			return null;
		}
		// Accept it whether or not the slash survived — that is the whole point.
		return $word;
	}

	private function help(IL10N $l): string {
		return implode("\n", [
			'**' . $l->t('Talk-Bot commands') . '**',
			'- `help` — ' . $l->t('show this help'),
			'- `reset` — ' . $l->t('forget the conversation and start over'),
			'- `status` — ' . $l->t('show the model in use and the size of this conversation'),
			'',
			$l->t('The leading slash is optional: you can just type help, reset or status.'),
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
