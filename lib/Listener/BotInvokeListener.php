<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Listener;

use OCA\TalkBot\AppInfo\Application;
use OCA\TalkBot\Service\AsyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Receives chat messages from Talk.
 *
 * Talk calls this while the sender's message is still being posted, so nothing
 * slow may happen here — the message is validated and handed to AsyncService.
 *
 * @template-implements IEventListener<Event>
 */
class BotInvokeListener implements IEventListener {

	public function __construct(
		private AsyncService $async,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getBotUrl') || !method_exists($event, 'getMessage')) {
			return;
		}
		if ($event->getBotUrl() !== Application::BOT_URL) {
			return;
		}

		$activity = $event->getMessage();
		if (!is_array($activity) || ($activity['type'] ?? '') !== 'Create') {
			return;
		}

		$object = $activity['object'] ?? [];
		if (!is_array($object) || ($object['name'] ?? '') !== 'message') {
			return;
		}

		// Only real users: this keeps the bot from answering itself or other bots.
		$actorId = (string)($activity['actor']['id'] ?? '');
		if (!str_starts_with($actorId, 'users/')) {
			return;
		}
		$userId = substr($actorId, strlen('users/'));

		$token = (string)($activity['target']['id'] ?? '');
		if ($token === '' || $userId === '') {
			return;
		}

		$text = $this->readMessage((string)($object['content'] ?? ''));
		if (trim($text) === '') {
			return;
		}

		$this->async->dispatch($token, $userId, (int)($object['id'] ?? 0), $text);
	}

	/** The content field carries a JSON document with the text and its mentions. */
	private function readMessage(string $content): string {
		$decoded = json_decode($content, true);
		if (!is_array($decoded) || !isset($decoded['message']) || !is_string($decoded['message'])) {
			return $content;
		}
		$message = $decoded['message'];
		$parameters = $decoded['parameters'] ?? null;
		if (!is_array($parameters)) {
			return $message;
		}

		// Replace {mention-user1} style placeholders with the names they stand for.
		return preg_replace_callback(
			'/\{([^}]+)\}/',
			static function (array $matches) use ($parameters): string {
				$parameter = $parameters[trim($matches[1])] ?? null;
				if (is_array($parameter) && isset($parameter['name']) && is_string($parameter['name'])) {
					return $parameter['name'];
				}
				return $matches[0];
			},
			$message,
		) ?? $message;
	}
}
