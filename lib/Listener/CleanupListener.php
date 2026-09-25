<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Listener;

use OCA\TalkBot\Service\ConfigService;
use OCA\TalkBot\Service\SessionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * What the bot remembers goes when the account or the conversation goes (review T15):
 * the conversation history, and a conversation's reply-language setting.
 *
 * @template-implements IEventListener<Event>
 */
class CleanupListener implements IEventListener {
	public function __construct(
		private SessionService $sessions,
		private ConfigService $config,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserDeletedEvent) {
			$this->sessions->deleteForUser($event->getUser()->getUID());
			return;
		}
		// Talk's RoomDeletedEvent; named by string so this works without Talk installed
		if (is_a($event, 'OCA\Talk\Events\RoomDeletedEvent') && method_exists($event, 'getRoom')) {
			$token = (string)$event->getRoom()->getToken();
			$this->sessions->deleteForRoom($token);
			$this->config->setRoomLanguage($token, '');
		}
	}
}
