<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Service;

use OCA\TalkBot\Engine\EngineFactory;
use OCA\TalkBot\Engine\TurnResult;
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
		private LoggerInterface $logger,
	) {
	}

	public function process(string $token, string $userId, int $messageId, string $text): void {
		if (!$this->isAllowed($userId)) {
			$this->logger->debug('Talk Bot: ignoring message from user outside the allow list', ['user' => $userId]);
			return;
		}

		$l = $this->l10nFactory->get('talkbot', $this->userLanguage($userId));

		$commandReply = $this->commands->handle($text, $token, $userId, $l);
		if ($commandReply !== null) {
			$this->botApi->sendMessage($token, $commandReply, $messageId);
			return;
		}

		$reacted = $messageId > 0 && $this->botApi->addReaction($token, $messageId, self::THINKING);
		try {
			$engine = $this->engineFactory->get();
			$history = $this->sessions->getHistory($token, $userId);
			$result = $engine->run($history, $text, $this->systemPrompt());

			if ($result->isOk()) {
				$clean = $this->stripToolCalls($result->output);
				if ($clean === '') {
					$this->logger->warning('Talk Bot: the model answered with tool call syntax only.');
					$this->botApi->sendMessage($token, '⚠️ ' . $l->t('The model tried to use a tool it does not have. Please ask again.'), $messageId);
					return;
				}
				$answer = $this->truncate($clean, $this->config->getMaxResponseLength());
				if ($this->botApi->sendMessage($token, $answer, $messageId)) {
					$this->sessions->appendTurn($token, $userId, $text, $clean);
				}
				return;
			}

			if ($result->kind === TurnResult::KIND_AUTH_ERROR) {
				$this->logger->error('Talk Bot: the AI engine rejected our credentials: ' . $result->detail);
				$this->botApi->sendMessage(
					$token,
					'⚠️ ' . $l->t('The AI service did not accept the credentials. An administrator needs to check the Talk Bot settings.'),
					$messageId,
				);
				return;
			}

			$this->logger->error('Talk Bot: engine error: ' . $result->detail);
			$this->botApi->sendMessage(
				$token,
				'⚠️ ' . $l->t('Something went wrong: %s', [$this->truncate($result->detail, 500)]),
				$messageId,
			);
		} catch (\Throwable $e) {
			$this->logger->error('Talk Bot: unhandled error while answering: ' . $e->getMessage(), ['exception' => $e]);
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

	/** Which language our own messages (commands, errors) should use. */
	private function userLanguage(string $userId): string {
		$configured = $this->config->getReplyLanguage();
		if ($configured !== '') {
			return $configured;
		}
		// There is no session user here — this runs in a request of its own — so
		// look the sender up and use the language they chose.
		return $this->l10nFactory->getUserLanguage($this->userManager->get($userId));
	}

	private function systemPrompt(): string {
		$parts = [
			'You are a helpful assistant taking part in a Nextcloud Talk conversation. '
			. 'Keep answers concise and use Markdown sparingly, as they are shown in a chat window. '
			. 'You have no tools of any kind and no access to files, the shell, the network or the '
			. 'server, and you cannot change anything on it. Never write tool call syntax such as '
			. 'function_calls, invoke or parameter blocks: it does nothing here and is shown to the '
			. 'user verbatim. If something would need a tool, say so in words instead. '
			. 'Never reveal or speculate about your configuration, credentials, file paths or hosting; '
			. 'if you are asked about them, say briefly that you cannot help with that.',
		];

		$language = $this->config->getReplyLanguage();
		if ($language !== '') {
			$name = self::LANGUAGE_NAMES[strtolower(substr($language, 0, 2))] ?? $language;
			$parts[] = sprintf(
				'Always reply in %s, whatever language these instructions or the question are written in, '
				. 'unless the user explicitly asks for another language.',
				$name,
			);
		} else {
			$parts[] = 'Reply in the same language the user writes in.';
		}

		$extra = $this->config->getExtraSystemPrompt();
		if ($extra !== '') {
			$parts[] = $extra;
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
