<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Service;

use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\Security\ISecureRandom;

/**
 * The commands the bot answers itself.
 *
 * A command is a single "?"-prefixed word (see parseCommand). Some answer
 * instantly with text; a few build a prompt and hand it back so the model
 * answers it. Anything that is not a known command is left for the model.
 */
class CommandService {

	private const KNOWN = [
		'help', 'reset', 'clear', 'status', 'whoami', 'version', 'ping',
		'undo', 'retry', 'again', 'summary', 'tldr', 'joke', 'roll', 'dice',
		'flip', '8ball', 'lang', 'model', 'update',
	];

	/** Commands that expect no argument: extra words send the message to the model instead. */
	private const NO_ARG = [
		'help', 'reset', 'clear', 'status', 'whoami', 'version', 'ping',
		'undo', 'retry', 'again', 'summary', 'tldr', 'joke', 'flip', 'model', 'update',
	];

	private const EIGHT_BALL = [
		'It is certain.', 'Without a doubt.', 'Yes — definitely.', 'You may rely on it.',
		'Most likely.', 'Outlook good.', 'Signs point to yes.', 'Reply hazy, try again.',
		'Ask again later.', 'Cannot predict now.', 'Do not count on it.', 'My reply is no.',
		'Very doubtful.',
	];

	public function __construct(
		private SessionService $sessions,
		private ConfigService $config,
		private IAppManager $appManager,
		private ISecureRandom $random,
		private TalkService $talk,
		private HubClient $hub,
	) {
	}

	/**
	 * @param IL10N $l In the language of the user who wrote the message.
	 */
	public function handle(string $text, string $token, string $userId, IL10N $l): ?CommandResult {
		$parsed = $this->parseCommand($text);
		if ($parsed === null) {
			return null;
		}
		[$command, $args] = $parsed;

		// No-arg commands with trailing text are treated as normal messages, so
		// "?status of the project" reaches the model instead of the command.
		if ($args !== '' && in_array($command, self::NO_ARG, true)) {
			return null;
		}

		switch ($command) {
			case 'help':
				return CommandResult::reply($this->help($l));
			case 'reset':
			case 'clear':
				$this->sessions->reset($token, $userId);
				// Also drop AI-Hub's memory of this conversation, where it keeps one.
				$this->hub->forget($userId, $this->hub->conversationId($token, $userId));
				return CommandResult::reply($l->t('Conversation reset. I have forgotten what we talked about.'));
			case 'status':
				return CommandResult::reply($this->status($token, $userId, $l));
			case 'model':
				return CommandResult::reply($this->model($l));
			case 'update':
				return CommandResult::reply($l->t('Talk-Bot now answers through AI-Hub. The AI service, model and any command line tool are managed in AI-Hub — open Administration settings → AI-Hub to change or update them.'));
			case 'whoami':
				return CommandResult::reply($l->t('You are %s. Everyone here talks to the same AI-Hub assistant.', [$userId]));
			case 'version':
				return CommandResult::reply($l->t('Talk-Bot version %s', [$this->appManager->getAppVersion('ktec_talkbot')]));
			case 'ping':
				return CommandResult::reply($l->t('pong — I am here and listening.'));
			case 'flip':
				return CommandResult::reply($this->random->generate(1, '01') === '1' ? $l->t('🪙 Heads') : $l->t('🪙 Tails'));
			case 'roll':
			case 'dice':
				return CommandResult::reply($this->roll($args, $l));
			case '8ball':
				return CommandResult::reply('🎱 ' . $l->t($this->pick(self::EIGHT_BALL)));
			case 'lang':
				// Changing it affects everybody in the conversation, so only moderators may (review T14).
				if (trim($args) !== '' && !$this->talk->isModerator($token, $userId)) {
					return CommandResult::reply($l->t('Only a moderator of this conversation can change its reply language.'));
				}
				return CommandResult::reply($this->lang($token, $args, $l));
			case 'undo':
				$dropped = $this->sessions->dropLastExchange($token, $userId);
				if ($dropped !== null) {
					// Keep AI-Hub's memory in step with the edited history.
					$this->hub->forget($userId, $this->hub->conversationId($token, $userId));
				}
				return CommandResult::reply($dropped === null
					? $l->t('There is nothing to undo yet.')
					: $l->t('Removed the last exchange.'));
			case 'retry':
			case 'again':
				$question = $this->sessions->dropLastExchange($token, $userId);
				if ($question === null) {
					return CommandResult::reply($l->t('There is nothing to answer again yet.'));
				}
				// Keep AI-Hub's memory in step with the edited history before re-asking.
				$this->hub->forget($userId, $this->hub->conversationId($token, $userId));
				return CommandResult::prompt($question, true);
			case 'summary':
			case 'tldr':
				if ($this->sessions->countTurns($token, $userId) === 0) {
					return CommandResult::reply($l->t('There is no conversation to summarize yet.'));
				}
				return CommandResult::prompt(
					'Summarise our conversation so far in a few short bullet points.',
					false,
				);
			case 'joke':
				return CommandResult::prompt('Tell me a short, clean, genuinely funny joke.', false);
			default:
				return null;
		}
	}

	/**
	 * Split a message into a command word and its argument.
	 *
	 * The prefix is "?" (Talk pops up its own menu on "/", making "/help" awkward
	 * to send); a leading "/" is still accepted, and a linkified "[?help](…)" is
	 * unwrapped.
	 *
	 * @return array{0: string, 1: string}|null [command, args] or null.
	 */
	private function parseCommand(string $text): ?array {
		$candidate = trim($text);
		if ($candidate === '') {
			return null;
		}
		if (preg_match('/^\[([^\]]+)]\([^)]*\)$/', $candidate, $m) === 1) {
			$candidate = trim($m[1]);
		}
		$candidate = trim($candidate, "` \t");

		if (preg_match('#^[?/]+([a-zA-Z0-9]+)(?:\s+(.*\S))?\s*$#', $candidate, $m) !== 1) {
			return null;
		}
		$word = strtolower($m[1]);
		if (!in_array($word, self::KNOWN, true)) {
			return null;
		}
		return [$word, isset($m[2]) ? trim($m[2]) : ''];
	}

	private function help(IL10N $l): string {
		return implode("\n", [
			'**' . $l->t('Talk-Bot commands') . '**',
			'*' . $l->t('Conversation') . '*',
			'- `?reset` — ' . $l->t('forget the conversation and start over'),
			'- `?undo` — ' . $l->t('remove the last exchange'),
			'- `?retry` — ' . $l->t('answer the last question again'),
			'- `?summary` — ' . $l->t('summarize the conversation so far'),
			'- `?lang <code>` — ' . $l->t('set the reply language for this conversation (e.g. ?lang en; ?lang off to follow each user\'s language)'),
			'*' . $l->t('Info') . '*',
			'- `?help` — ' . $l->t('show this help'),
			'- `?status` — ' . $l->t('show the AI-Hub connection and memory'),
			'- `?model` — ' . $l->t('show the model AI-Hub answers with'),
			'- `?update` — ' . $l->t('where to manage the AI service and model'),
			'- `?whoami` — ' . $l->t('show who you are'),
			'- `?version` — ' . $l->t('show the app version'),
			'*' . $l->t('Fun') . '*',
			'- `?roll [NdM]` — ' . $l->t('roll dice, e.g. ?roll or ?roll 2d6'),
			'- `?flip` — ' . $l->t('flip a coin'),
			'- `?8ball <question>` — ' . $l->t('ask the magic 8-ball'),
			'- `?ping` — ' . $l->t('check that I am awake'),
			'- `?joke` — ' . $l->t('hear a short joke'),
			'',
			$l->t('Anything else you write is answered by the model.'),
		]);
	}

	private function status(string $token, string $userId, IL10N $l): string {
		$lines = [];
		$hub = $this->hub->status();
		if ($hub === null) {
			$lines[] = $l->t('AI-Hub is required and is not installed.');
		} elseif (empty($hub['ready'])) {
			$lines[] = $l->t('AI-Hub is installed but not set up yet.');
		} else {
			$lines[] = $l->t('Engine: %s', ['AI-Hub — ' . $hub['provider'] . ' (' . $hub['mode'] . ')']);
			$lines[] = $l->t('Model: %s', [$hub['model'] === '' ? '—' : $hub['model']]);
		}
		$lines[] = $l->n('Remembered exchanges: %n', 'Remembered exchanges: %n', $this->sessions->countTurns($token, $userId));
		return implode("\n", $lines);
	}

	/**
	 * ?model — informational. It shows the model AI-Hub answers Talk-Bot's chat
	 * with; the model is chosen in AI-Hub, not here.
	 */
	private function model(IL10N $l): string {
		$hub = $this->hub->status();
		if ($hub === null) {
			return $l->t('AI-Hub is required and is not installed, so there is no model yet. An administrator needs to install and set it up.');
		}
		if (empty($hub['ready'])) {
			return $l->t('AI-Hub is installed but not set up yet. An administrator chooses the AI service, key and model in Administration settings → AI-Hub.');
		}
		$model = $hub['model'] === '' ? '—' : $hub['model'];
		return $l->t('I answer through AI-Hub, model %s. The AI service and model are set in AI-Hub.', [$model]);
	}

	private function roll(string $args, IL10N $l): string {
		$count = 1;
		$sides = 6;
		$args = trim($args);
		if ($args !== '') {
			if (preg_match('/^(\d{0,2})d(\d{1,3})$/i', $args, $m) === 1) {
				$count = $m[1] === '' ? 1 : (int)$m[1];
				$sides = (int)$m[2];
			} elseif (preg_match('/^\d{1,3}$/', $args) === 1) {
				$sides = (int)$args;
			} else {
				return $l->t('I did not understand that. Try ?roll, ?roll 20 or ?roll 2d6.');
			}
		}
		$count = max(1, min(20, $count));
		$sides = max(2, min(1000, $sides));

		$rolls = [];
		$total = 0;
		for ($i = 0; $i < $count; $i++) {
			$value = random_int(1, $sides);
			$rolls[] = $value;
			$total += $value;
		}
		if ($count === 1) {
			return $l->t('🎲 You rolled %1$s (d%2$s).', [(string)$total, (string)$sides]);
		}
		return $l->t('🎲 You rolled %1$s = %2$s (%3$s×d%4$s).', [
			implode(' + ', array_map('strval', $rolls)),
			(string)$total,
			(string)$count,
			(string)$sides,
		]);
	}

	private function lang(string $token, string $args, IL10N $l): string {
		$args = strtolower(trim($args));
		if ($args === '') {
			$current = $this->config->getRoomLanguage($token);
			if ($current !== '') {
				return $l->t('This conversation replies in "%s". Use ?lang off to follow each user instead.', [$current]);
			}
			$global = $this->config->getReplyLanguage();
			return $global !== ''
				? $l->t('This conversation follows the server default ("%s"). Set another with ?lang <code>.', [$global])
				: $l->t('This conversation replies in each user\'s own language. Set a fixed one with ?lang <code>, e.g. ?lang en.');
		}
		if (in_array($args, ['off', 'auto', 'clear', 'none'], true)) {
			$this->config->setRoomLanguage($token, '');
			return $l->t('Reply language cleared — I will follow each user\'s language.');
		}
		if (preg_match('/^[a-z]{2}(?:[-_][a-z]{2})?$/i', $args) !== 1) {
			return $l->t('That does not look like a language code. Try ?lang en, ?lang ja or ?lang off.');
		}
		$this->config->setRoomLanguage($token, $args);
		return $l->t('This conversation will now reply in "%s".', [$args]);
	}

	private function pick(array $options): string {
		return $options[random_int(0, count($options) - 1)];
	}
}
