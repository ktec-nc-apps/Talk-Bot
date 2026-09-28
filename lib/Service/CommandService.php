<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Service;

use OCA\TalkBot\Engine\CliEngine;
use OCA\TalkBot\Engine\EngineFactory;

use OCP\App\IAppManager;
use OCP\IGroupManager;
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
		'undo', 'retry', 'again', 'summary', 'tldr', 'joke', 'flip',
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
		private IGroupManager $groupManager,
		private IAppManager $appManager,
		private ISecureRandom $random,
		private TalkService $talk,
		private EngineFactory $engines,
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
				return CommandResult::reply($l->t('Conversation reset. I have forgotten what we talked about.'));
			case 'status':
				return CommandResult::reply($this->status($token, $userId, $l));
			case 'model':
				return CommandResult::reply($this->model($args, $userId, $l));
			case 'update':
				if ($args !== '') {
					return null;
				}
				if (!$this->groupManager->isAdmin($userId)) {
					return CommandResult::reply($l->t('Only an administrator can update the command line tool.'));
				}
				if ($this->config->getMode() !== 'cli' || $this->config->getProvider() !== 'claude') {
					return CommandResult::reply($l->t('?update works only when the bot uses the Claude command line tool.'));
				}
				return CommandResult::task('update', '⏳ ' . $l->t('Checking for an update of the command line tool…'));
			case 'whoami':
				return CommandResult::reply($this->whoami($userId, $l));
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
				return CommandResult::reply($dropped === null
					? $l->t('There is nothing to undo yet.')
					: $l->t('Removed the last exchange.'));
			case 'retry':
			case 'again':
				$question = $this->sessions->dropLastExchange($token, $userId);
				return $question === null
					? CommandResult::reply($l->t('There is nothing to answer again yet.'))
					: CommandResult::prompt($question, true);
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
			'- `?status` — ' . $l->t('show the engine, model and memory'),
			'- `?model` — ' . $l->t('show the model in use and the ones it can be switched to; ?model <name> switches (administrators)'),
			'- `?update` — ' . $l->t('update the command line tool the bot uses (administrators)'),
			'- `?whoami` — ' . $l->t('show your access level'),
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
		return implode("\n", [
			$l->t('Engine: %s', [$this->config->getProvider() . ' (' . $this->config->getMode() . ')']),
			$l->t('Model: %s', [$this->config->getModel() === '' ? '—' : $this->config->getModel()]),
			$l->n('Remembered exchanges: %n', 'Remembered exchanges: %n', $this->sessions->countTurns($token, $userId)),
		]);
	}

	/**
	 * ?model — the model in use and the ones it can be switched to; ?model <name>
	 * (or its number in the list) switches, for everybody, so only an administrator
	 * may (owner, 2026-09-29).
	 */
	private function model(string $args, string $userId, IL10N $l): string {
		$current = $this->config->getModel();
		$engine = null;
		$models = [];
		try {
			$engine = $this->engines->get();
			$models = array_values($engine->listModels());
		} catch (\Throwable $e) {
			$models = [];
		}
		$cli = $engine instanceof CliEngine && $this->config->getProvider() === 'claude';
		$want = trim($args);
		if ($want === '') {
			$lines = [$l->t('Model in use: %s', [$current === '' ? '—' : $current])];
			if ($models === []) {
				$lines[] = $l->t('No list of models could be retrieved.');
			} else {
				$lines[] = '';
				$lines[] = $cli
					? $l->t('Models the installed Claude Code knows:')
					: $l->t('Models it can be switched to:');
				foreach ($models as $i => $m) {
					$lines[] = ($i + 1) . '. `' . $m . '`' . ($m === $current ? ' ← ' . $l->t('in use') : '');
				}
				if ($cli) {
					$lines[] = '';
					$lines[] = $l->t('The short names %s always mean the newest model of that kind.', ['`' . implode('`, `', CliEngine::CLAUDE_ALIASES) . '`']);
				}
			}
			$lines[] = '';
			$lines[] = $l->t('To switch, write ?model and a name or its number (administrators only).');
			return implode("\n", $lines);
		}
		if (!$this->groupManager->isAdmin($userId)) {
			return $l->t('Only an administrator can change the model.');
		}
		if (preg_match('/^\d{1,3}$/', $want) === 1) {
			if (!isset($models[(int)$want - 1])) {
				return $l->t('There is no model number %s. Write ?model to see the list.', [$want]);
			}
			$want = $models[(int)$want - 1];
		} else {
			foreach ($models as $m) {
				if (strcasecmp($m, $want) === 0) {
					$want = $m;
				}
			}
			if ($cli) {
				$want = strtolower($want);
			} elseif (!in_array($want, $models, true)) {
				return $l->t('%s is not one of the models it can be switched to. Write ?model to see the list.', [$want]);
			}
		}
		if (mb_strlen($want) > 200 || preg_match('/^[A-Za-z0-9._:\/-]+$/', $want) !== 1) {
			return $l->t('That is not a valid model name.');
		}
		if ($want === $current) {
			return $l->t('%s is already in use.', [$want]);
		}
		$resolved = '';
		if ($cli) {
			// Tried before it is kept: a name the tool does not accept would leave
			// every later message without an answer.
			$probe = $engine->probeModel($want);
			if (!$probe['ok']) {
				return '⚠️ ' . $l->t('%s could not be used, so the model was not changed.', [$want])
					. ($probe['detail'] !== '' ? "\n```\n" . $probe['detail'] . "\n```" : '');
			}
			$resolved = $probe['resolved'];
		}
		$this->config->setModel($want);
		$text = $l->t('Model changed: %1$s → %2$s', [$current === '' ? '—' : $current, $want]);
		if ($resolved !== '' && $resolved !== $want) {
			$text .= "\n" . $l->t('(It answers as %s.)', [$resolved]);
		}
		return $text;
	}

	/** Run the command line tool's update; one at a time. Called by ReplyService after the notice is posted. */
	public function runUpdate(IL10N $l): string {
		$cache = \OCP\Server::get(\OCP\ICacheFactory::class)->createDistributed('ktec_talkbot');
		if (!$cache->add('cli_update_running', 1, 900)) {
			return $l->t('An update is already running. Please wait for it to finish.');
		}
		try {
			$engine = $this->engines->get();
			if (!$engine instanceof CliEngine) {
				return $l->t('?update works only when the bot uses the Claude command line tool.');
			}
			$before = $engine->listModels();
			$r = $engine->update();
			if (!$r['ok']) {
				return '⚠️ ' . $l->t('The update failed.') . ($r['detail'] !== '' ? "\n```\n" . $r['detail'] . "\n```" : '');
			}
			if ($r['before'] !== '' && $r['before'] === $r['after']) {
				return '✅ ' . $l->t('Already up to date (%s).', [$r['after']]);
			}
			$text = '✅ ' . $l->t('Updated: %1$s → %2$s', [$r['before'] === '' ? '—' : $r['before'], $r['after'] === '' ? '—' : $r['after']]);
			$added = array_values(array_diff($engine->listModels(), $before));
			if ($added !== []) {
				$text .= "\n" . $l->t('Models new in this version: %s', ['`' . implode('`, `', $added) . '`']) . "\n" . $l->t('Write ?model to switch.');
			}
			return $text;
		} finally {
			$cache->remove('cli_update_running');
		}
	}

	private function whoami(string $userId, IL10N $l): string {
		$elevated = $this->config->areAdminToolsEnabled()
			&& $this->config->getMode() === 'cli'
			&& $this->groupManager->isAdmin($userId);
		return $l->t('You are %1$s. You have %2$s.', [
			$userId,
			$elevated ? $l->t('administrator tools') : $l->t('no tools (sandboxed)'),
		]);
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
