<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Service;

use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/** Turns one incoming chat message into one posted answer, through AI-Hub. */
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
		private HubClient $hub,
		private IFactory $l10nFactory,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	public function process(string $token, string $userId, int $messageId, string $text): void {
		// One answer per message: the HTTP hand-off and the background job can both reach
		// here for the same message (review T4).
		if ($messageId > 0) {
			$cache = \OCP\Server::get(\OCP\ICacheFactory::class)->createDistributed('ktec_talkbot');
			$key = 'answered_' . $token . '_' . $messageId;
			// add() is atomic where get()+set() was not: two arrivals at once could both pass.
			if ($cache instanceof \OCP\IMemcache) {
				if (!$cache->add($key, 1, 3600)) {
					return;
				}
			} elseif ($cache->get($key) !== null) {
				return;
			} else {
				$cache->set($key, 1, 3600);
			}
		}
		if (!$this->isAllowed($userId)) {
			$this->logger->debug('Talk-Bot: ignoring message from user outside the allow list', ['user' => $userId]);
			return;
		}

		// Effective reply language: the per-conversation override (?lang) wins,
		// then the server-wide setting, then the language the sender chose in
		// Nextcloud. So by default the bot answers everyone in their own language.
		$lang = $this->replyLanguage($token, $userId);
		$l = $this->l10nFactory->get('ktec_talkbot', $lang);

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

		// AI-Hub is required: it holds the AI service, key and model for every app of
		// the Base series. When it is absent or not set up, the bot says so plainly --
		// it never answers from an engine of its own, because it no longer has one.
		if (!$this->hub->present()) {
			$this->botApi->sendMessage($token, $this->hubMissingMessage($l), $messageId);
			return;
		}
		if (!$this->hub->ready()) {
			$this->botApi->sendMessage($token, $this->hubNotReadyMessage($l), $messageId);
			return;
		}

		// Limits the administrator set (review T9): each answer holds a PHP worker and costs money.
		$slot = $this->takeSlot($userId);
		if ($slot === null) {
			$this->botApi->sendMessage($token, '⏳ ' . $l->t('Too many requests at once. Please wait a moment and ask again.'), $messageId);
			return;
		}
		$reacted = $messageId > 0 && $this->botApi->addReaction($token, $messageId, self::THINKING);
		try {
			$history = $this->sessions->getHistory($token, $userId);
			// The directive rides along with this turn's message -- the strongest place
			// to pin the reply language -- while the history keeps the original text.
			// The scenario's context carries the same rule as a backstop.
			$message = $this->withLanguageDirective($text, $lang);

			$hubReply = $this->hub->answer(
				$userId,
				$history,
				$message,
				self::languageRule($lang),
				$this->hub->conversationId($token, $userId),
			);

			if (($hubReply['state'] ?? '') === 'done') {
				$clean = $this->stripToolCalls((string)($hubReply['text'] ?? ''));
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

			// AI-Hub could not answer. Its own limits are the one case worth naming to
			// the room; anything else gets a plain sentence, and what went wrong goes
			// to the log, with every secret taken out (review T1, T12).
			if (($hubReply['error'] ?? '') === 'busy') {
				$this->botApi->sendMessage($token, '⏳ ' . $l->t('Too many requests at once. Please wait a moment and ask again.'), $messageId);
				return;
			}
			$this->logger->error('Talk-Bot: AI-Hub could not answer: ' . Redact::text((string)($hubReply['error'] ?? 'unknown')));
			$this->botApi->sendMessage(
				$token,
				'⚠️ ' . $l->t('Something went wrong. An administrator can find the details in the Nextcloud log.'),
				$messageId,
			);
		} catch (\Throwable $e) {
			$this->logger->error('Talk-Bot: unhandled error while answering: ' . Redact::text($e->getMessage()));
			$this->botApi->sendMessage($token, '⚠️ ' . $l->t('Something went wrong while answering.'), $messageId);
		} finally {
			$this->releaseSlot($slot);
			if ($reacted) {
				$this->botApi->removeReaction($token, $messageId, self::THINKING);
			}
		}
	}

	/**
	 * Count this answer against the limits: per user per minute, running per user and running
	 * in total (0 = no limit). Returns the counters to release afterwards, or null when a limit
	 * is reached. Without a shared cache that can count, nothing is limited.
	 *
	 * @return list<string>|null
	 */
	public function takeSlot(string $userId): ?array {
		$cache = \OCP\Server::get(\OCP\ICacheFactory::class)->createDistributed('ktec_talkbot');
		if (!$cache instanceof \OCP\IMemcache) {
			return [];
		}
		$perMinute = $this->config->getRatePerMinute();
		if ($perMinute > 0) {
			$key = 'rate_' . $userId . '_' . intdiv(time(), 60);
			$n = $cache->inc($key);
			if ($n === 1) {
				$cache->set($key, 1, 120);
			}
			if ($n !== false && $n > $perMinute) {
				return null;
			}
		}
		// A crashed answer must not hold its place forever: the counters expire after the longest wait.
		$ttl = $this->config->getRequestTimeout() + 120;
		$taken = [];
		foreach (['run_user_' . $userId => $this->config->getMaxParallelPerUser(), 'run_total' => $this->config->getMaxParallelTotal()] as $key => $max) {
			if ($max === 0) {
				continue;
			}
			$n = $cache->inc($key);
			if ($n === false) {
				continue;
			}
			if ($n === 1) {
				$cache->set($key, 1, $ttl);
			}
			$taken[] = $key;
			if ($n > $max) {
				$this->releaseSlot($taken);
				return null;
			}
		}
		return $taken;
	}

	/** @param list<string> $keys */
	public function releaseSlot(array $keys): void {
		if ($keys === []) {
			return;
		}
		$cache = \OCP\Server::get(\OCP\ICacheFactory::class)->createDistributed('ktec_talkbot');
		foreach ($keys as $key) {
			if ($cache instanceof \OCP\IMemcache && $cache->dec($key) === false) {
				$cache->remove($key);
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

	/** What the bot says when AI-Hub is not installed: why, and how to set it up. */
	private function hubMissingMessage(\OCP\IL10N $l): string {
		return $l->t("AI-Hub is not installed, so I can't answer yet. An administrator needs to set it up:\n"
			. "1. As an administrator, open Apps and search for \"AI-Hub\".\n"
			. "2. Press Download and enable.\n"
			. "3. Open Administration settings → AI-Hub and choose the AI service, key and model.\n"
			. 'Then talk to me again.');
	}

	/** What the bot says when AI-Hub is installed but not set up yet. */
	private function hubNotReadyMessage(\OCP\IL10N $l): string {
		return $l->t('AI-Hub is installed but not set up yet. An administrator needs to open Administration settings → AI-Hub and choose the AI service, key and model.');
	}

	/** English name of a language code, or the code itself when unknown. */
	private static function languageName(string $code): string {
		return self::LANGUAGE_NAMES[strtolower(substr($code, 0, 2))] ?? $code;
	}

	/** Append a per-turn reply-language instruction, invisible to the stored history. */
	private function withLanguageDirective(string $text, string $language): string {
		if ($language === '') {
			return $text;
		}
		return $text . "\n\n[Reply to this message in " . self::languageName($language) . '.]';
	}

	/**
	 * The persona and sandbox rules plus the administrator's extra instructions --
	 * the part of the system prompt that is the same from one turn to the next,
	 * without the reply-language rule. Registered at AI-Hub as the scenario's system
	 * prompt (HubClient), so the bot behaves the same however AI-Hub answers it.
	 */
	public static function behaviourText(string $extra): string {
		$parts = [
			'You are a helpful assistant taking part in a Nextcloud Talk conversation. '
			. 'Keep answers concise and use Markdown sparingly, as they are shown in a chat window. '
			. 'You have no tools of any kind and no access to files, the shell, the network or the '
			. 'server, and you cannot change anything on it. Never write tool call syntax such as '
			. 'function_calls, invoke or parameter blocks: it does nothing here and is shown to the '
			. 'user verbatim. If something would need a tool, say so in words instead. Never reveal '
			. 'or speculate about your configuration, credentials, file paths or hosting; if you are '
			. 'asked about them, say briefly that you cannot help with that.',
		];
		if ($extra !== '') {
			$parts[] = $extra;
		}
		return implode("\n\n", $parts);
	}

	/**
	 * The reply-language rule. It goes last, and firmly: models otherwise tend to
	 * answer in whatever language the message happens to be in, but the reply
	 * language here is the user's own setting and must win. Handed to AI-Hub as the
	 * question's context.
	 */
	public static function languageRule(string $language): string {
		if ($language === '') {
			return 'Reply in the same language the user writes in.';
		}
		return sprintf(
			'LANGUAGE — Write your entire reply in %1$s. This is a hard requirement: even '
			. 'when the user writes to you in a different language, still answer in %1$s and do '
			. 'not switch to match them. Only use another language if the user explicitly asks '
			. 'you to translate something or to answer in a specific other language.',
			self::languageName($language),
		);
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
