<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Service;

use OCA\TalkBot\AppInfo\Application;
use OCP\App\IAppManager;

/**
 * Talk-Bot as a client of AI-Hub (ai_hub), the shared AI gateway.
 *
 * AI-Hub holds the AI service, key, model and limits for every app of the Base
 * series, and Talk-Bot answers every chat turn through it. Talk-Bot has no engine
 * of its own: when AI-Hub is absent or not set up, ReplyService says so plainly
 * instead of answering, and nothing here is reached.
 *
 * Every turn is the ordinary, tool-free chat; Talk-Bot gives the model no tools.
 *
 * This is a thin client; AI-Hub's own HubService does the work. It is referenced
 * by name so Talk-Bot carries no hard dependency on AI-Hub's code and loads
 * unchanged where AI-Hub is not installed.
 */
class HubClient {

	/** The name Talk-Bot's chat is registered under at AI-Hub. */
	public const SCENARIO = 'talk';

	private const HUB = 'ai_hub';
	private const HUB_SERVICE = '\\OCA\\AIHub\\Service\\HubService';

	public function __construct(
		private ConfigService $config,
		private IAppManager $apps,
	) {
	}

	/** AI-Hub is installed and switched on. */
	public function present(): bool {
		return class_exists(self::HUB_SERVICE) && $this->apps->isEnabledForUser(self::HUB);
	}

	/** @return object|null AI-Hub's HubService, or null when it is not present. */
	private function hub(): ?object {
		return $this->present() ? \OCP\Server::get(ltrim(self::HUB_SERVICE, '\\')) : null;
	}

	/** AI-Hub is present and can answer a question right now. */
	public function ready(): bool {
		$hub = $this->hub();
		return $hub !== null && !empty($hub->status(Application::APP_ID)['ready']);
	}

	/**
	 * What AI-Hub can offer Talk-Bot now, or null when AI-Hub is not present.
	 *
	 * @return array{ready: bool, reason: string, provider: string, mode: string, model: string, search: bool}|null
	 */
	public function status(): ?array {
		$hub = $this->hub();
		return $hub === null ? null : $hub->status(Application::APP_ID);
	}

	/**
	 * Tell AI-Hub what Talk-Bot's chat is. Called when the app boots, in every
	 * request: the hub keeps scenarios in memory only, and the request that works
	 * out an answer is not the one that asked.
	 *
	 * The scenario carries Talk-Bot's persona and sandbox rules (the behaviour
	 * text, without the language line). The reply language is decided per turn --
	 * it can be a per-conversation or per-user choice -- and handed over as the
	 * question's context, so the scenario itself leaves the language to Talk-Bot.
	 */
	public function registerScenario(): void {
		$hub = $this->hub();
		if ($hub === null || $hub->hasScenario(Application::APP_ID, self::SCENARIO)) {
			return;
		}
		$hub->registerScenario(Application::APP_ID, self::SCENARIO, [
			'system' => ReplyService::behaviourText($this->config->getExtraSystemPrompt()),
			// Talk chat uses no web search today, so the scenario never searches.
			'search' => false,
			// Talk-Bot says the language itself, per turn, through the context.
			'language' => false,
			// The administrator's allow list, enforced by the hub as well as here.
			'allow' => fn (string $uid): bool => !$this->config->isAllowlistEnabled()
				|| in_array($uid, $this->config->getAllowedUsers(), true),
		]);
	}

	/**
	 * Answer one ordinary chat turn through AI-Hub, waiting for the answer in this
	 * request. Talk-Bot already answers off the request path (AsyncService), so a
	 * synchronous call here holds only the worker that was going to answer anyway.
	 *
	 * The history is handed over as the conversation, and a stable conversation
	 * token lets AI-Hub keep its own memory and, for the command line, resume its
	 * session. Talk-Bot still keeps its own memory as well, so its commands work
	 * the same on either path.
	 *
	 * @param list<array{role: string, text: string}> $history Oldest first, excluding $message.
	 * @return array{state: string, text?: string, answer?: mixed, tools?: list<string>, error?: string}
	 *         state 'error' with error 'absent' when AI-Hub went away between the check and the call.
	 */
	public function answer(string $userId, array $history, string $message, string $context, string $conversation): array {
		$hub = $this->hub();
		if ($hub === null) {
			return ['state' => 'error', 'error' => 'absent'];
		}
		// Boot-time registration can be missed by the worker request; make sure it is there.
		$this->registerScenario();
		$messages = $history;
		$messages[] = ['role' => 'user', 'text' => $message];
		return $hub->askNow($userId, Application::APP_ID, self::SCENARIO, $messages, [
			'context' => $context,
			'search' => false,
			'conversation' => $conversation,
		]);
	}

	/**
	 * Drop AI-Hub's memory of a conversation, so Talk-Bot's own history stays the
	 * source of truth after ?reset, ?undo or ?retry edited it. The next turn then
	 * re-seeds AI-Hub from the history Talk-Bot hands over.
	 */
	public function forget(string $userId, string $conversation): void {
		$hub = $this->hub();
		if ($hub !== null && method_exists($hub, 'forgetConversation')) {
			$hub->forgetConversation($userId, Application::APP_ID, $conversation);
		}
	}

	/** A stable conversation token per (Talk conversation, user). */
	public function conversationId(string $token, string $userId): string {
		return hash('sha256', $token . "\0" . $userId);
	}
}
