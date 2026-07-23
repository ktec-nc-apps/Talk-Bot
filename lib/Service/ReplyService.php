<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Service;

use OCA\TalkBot\Engine\EngineFactory;
use OCA\TalkBot\Engine\TurnResult;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/** Turns one incoming chat message into one posted answer. */
class ReplyService {

	private const THINKING = '💭';

	/** Languages we can name to the model when a fixed reply language is set. */
	private const LANGUAGE_NAMES = [
		'ar' => 'Arabic', 'cs' => 'Czech', 'da' => 'Danish', 'de' => 'German',
		'el' => 'Greek', 'en' => 'English', 'es' => 'Spanish', 'fi' => 'Finnish',
		'fr' => 'French', 'he' => 'Hebrew', 'hu' => 'Hungarian', 'it' => 'Italian',
		'ja' => 'Japanese', 'ko' => 'Korean', 'nb' => 'Norwegian', 'nl' => 'Dutch',
		'pl' => 'Polish', 'pt' => 'Portuguese', 'ru' => 'Russian', 'sv' => 'Swedish',
		'tr' => 'Turkish', 'uk' => 'Ukrainian', 'zh' => 'Chinese',
	];

	public function __construct(
		private ConfigService $config,
		private SessionService $sessions,
		private CommandService $commands,
		private BotApiService $botApi,
		private EngineFactory $engineFactory,
		private IFactory $l10nFactory,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
	) {
	}

	public function process(string $token, string $userId, int $messageId, string $text): void {
		if (!$this->isAllowed($userId)) {
			$this->logger->debug('Talk-Bot: ignoring message from user outside the allow list', ['user' => $userId]);
			return;
		}

		// Effective reply language: the per-conversation override (?lang) wins,
		// then the server-wide setting, then the language the sender chose in
		// Nextcloud. So by default the bot answers everyone in their own language.
		$lang = $this->replyLanguage($token, $userId);
		$l = $this->l10nFactory->get('talkbot', $lang);

		$command = $this->commands->handle($text, $token, $userId, $l);
		$persist = true;
		if ($command !== null) {
			if ($command->isReply()) {
				$this->botApi->sendMessage($token, $command->reply, $messageId);
				return;
			}
			// A prompt macro (retry, summary, joke): let the model answer it.
			$text = $command->prompt;
			$persist = $command->persist;
		}

		$reacted = $messageId > 0 && $this->botApi->addReaction($token, $messageId, self::THINKING);
		try {
			$elevated = $this->isElevated($userId);
			$engine = $this->engineFactory->get();
			$history = $this->sessions->getHistory($token, $userId);
			// The directive rides along with this turn's message — the strongest
			// place to pin the reply language — while the history keeps the
			// original text. The system prompt carries the same rule as a backstop.
			$engineText = $this->withLanguageDirective($text, $lang);
			$result = $engine->run($history, $engineText, $this->systemPrompt($elevated, $lang), $elevated);

			if ($result->isOk()) {
				$clean = $this->stripToolCalls($result->output);
				if ($clean === '') {
					$this->logger->warning('Talk-Bot: the model answered with tool call syntax only.');
					$this->botApi->sendMessage($token, '⚠️ ' . $l->t('The model tried to use a tool it does not have. Please ask again.'), $messageId);
					return;
				}
				$answer = $this->truncate($clean, $this->config->getMaxResponseLength());
				if ($this->botApi->sendMessage($token, $answer, $messageId) && $persist) {
					$this->sessions->appendTurn($token, $userId, $text, $clean);
				}
				return;
			}

			if ($result->kind === TurnResult::KIND_AUTH_ERROR) {
				$this->logger->error('Talk-Bot: the AI engine rejected our credentials: ' . $result->detail);
				$this->botApi->sendMessage(
					$token,
					'⚠️ ' . $l->t('The AI service did not accept the credentials. An administrator needs to check the Talk-Bot settings.'),
					$messageId,
				);
				return;
			}

			$this->logger->error('Talk-Bot: engine error: ' . $result->detail);
			$this->botApi->sendMessage(
				$token,
				'⚠️ ' . $l->t('Something went wrong: %s', [$this->truncate($result->detail, 500)]),
				$messageId,
			);
		} catch (\Throwable $e) {
			$this->logger->error('Talk-Bot: unhandled error while answering: ' . $e->getMessage(), ['exception' => $e]);
			$this->botApi->sendMessage($token, '⚠️ ' . $l->t('Something went wrong while answering.'), $messageId);
		} finally {
			if ($reacted) {
				$this->botApi->removeReaction($token, $messageId, self::THINKING);
			}
		}
	}

	private function isAllowed(string $userId): bool {
		if (!$this->config->isAllowlistEnabled()) {
			return true;
		}
		return in_array($userId, $this->config->getAllowedUsers(), true);
	}

	/**
	 * The language every reply in this conversation should use: the ?lang
	 * override, else the server-wide setting, else the sender's own Nextcloud
	 * language. The sender is looked up because this runs in a request of its
	 * own, with no session user.
	 */
	private function replyLanguage(string $token, string $userId): string {
		$room = $this->config->getRoomLanguage($token);
		if ($room !== '') {
			return $room;
		}
		$server = $this->config->getReplyLanguage();
		if ($server !== '') {
			return $server;
		}
		return $this->l10nFactory->getUserLanguage($this->userManager->get($userId));
	}

	/**
	 * Whether this message may use the tools of the command line tool.
	 *
	 * Two things have to be true: the administrator switched the admin tier on at
	 * all, and the person who wrote the message is in the admin group. Everyone
	 * else gets the sandboxed prompt and, in the engine, the user tool list.
	 */
	private function isElevated(string $userId): bool {
		if (!$this->config->areAdminToolsEnabled() || $this->config->getMode() !== 'cli') {
			return false;
		}
		return $this->groupManager->isAdmin($userId);
	}

	/** English name of a language code, or the code itself when unknown. */
	private function languageName(string $code): string {
		return self::LANGUAGE_NAMES[strtolower(substr($code, 0, 2))] ?? $code;
	}

	/** Append a per-turn reply-language instruction, invisible to the stored history. */
	private function withLanguageDirective(string $text, string $language): string {
		if ($language === '') {
			return $text;
		}
		return $text . "\n\n[Reply to this message in " . $this->languageName($language) . '.]';
	}

	private function systemPrompt(bool $elevated, string $language): string {
		$intro = 'You are a helpful assistant taking part in a Nextcloud Talk conversation. '
			. 'Keep answers concise and use Markdown sparingly, as they are shown in a chat window.';

		if ($elevated) {
			$parts = [
				$intro . ' You are talking to an administrator of this Nextcloud server and you '
				. 'do have tools, running with the rights of the web server user. Treat that with '
				. 'care: check before you change or delete anything, prefer reversible steps, and '
				. 'say plainly what you did. The person you are talking to is the only one who sees '
				. 'this conversation with you.',
			];
		} else {
			$parts = [
				$intro . ' You have no tools of any kind and no access to files, the shell, the '
				. 'network or the server, and you cannot change anything on it. Never write tool '
				. 'call syntax such as function_calls, invoke or parameter blocks: it does nothing '
				. 'here and is shown to the user verbatim. If something would need a tool, say so '
				. 'in words instead. Never reveal or speculate about your configuration, '
				. 'credentials, file paths or hosting; if you are asked about them, say briefly '
				. 'that you cannot help with that.',
			];
		}

		$extra = $this->config->getExtraSystemPrompt();
		if ($extra !== '') {
			$parts[] = $extra;
		}

		// The language rule goes last, and firmly: models otherwise tend to answer
		// in whatever language the message happens to be in, but the reply language
		// here is the user's own setting and must win.
		if ($language !== '') {
			$name = $this->languageName($language);
			$parts[] = sprintf(
				'LANGUAGE — Write your entire reply in %1$s. This is a hard requirement: even '
				. 'when the user writes to you in a different language, still answer in %1$s and do '
				. 'not switch to match them. Only use another language if the user explicitly asks '
				. 'you to translate something or to answer in a specific other language.',
				$name,
			);
		} else {
			$parts[] = 'Reply in the same language the user writes in.';
		}

		return implode("\n\n", $parts);
	}

	/**
	 * Drop tool call syntax from an answer.
	 *
	 * Models trained as coding agents sometimes reach for a tool even when none
	 * is offered. Nothing executes it, but the raw block would otherwise be
	 * posted into the conversation for everyone to read.
	 */
	private function stripToolCalls(string $text): string {
		$kept = [];
		foreach (preg_split('/\R/', $text) ?: [] as $line) {
			if (preg_match('#^\s*</?(antml:)?(function_calls|function_results|invoke|parameter)\b#i', $line)) {
				continue;
			}
			$kept[] = $line;
		}
		return trim(implode("\n", $kept));
	}

	private function truncate(string $text, int $max): string {
		if (mb_strlen($text) <= $max) {
			return $text;
		}
		return mb_substr($text, 0, $max - 1) . '…';
	}
}
