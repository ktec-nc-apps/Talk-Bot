<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\AppInfo;

use OCA\TalkBot\Listener\BotInvokeListener;
use OCA\TalkBot\Listener\CleanupListener;
use OCA\TalkBot\Service\HubClient;
use OCA\TalkBot\Settings\AdminFormAccess;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {

	public const APP_ID = 'ktec_talkbot';

	/** How Talk addresses an in-app bot (see \OCA\Talk\Model\Bot::URL_APP_PREFIX). */
	public const BOT_URL = 'nextcloudapp://' . self::APP_ID;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Referencing the class name only produces a string, so this stays safe on
		// installations where Talk is not present.
		$context->registerEventListener(
			'OCA\Talk\Events\BotInvokeEvent',
			BotInvokeListener::class,
		);
		$context->registerEventListener(UserDeletedEvent::class, CleanupListener::class);
		$context->registerEventListener('OCA\Talk\Events\RoomDeletedEvent', CleanupListener::class);
		$context->registerDeclarativeSettings(AdminFormAccess::class);
	}

	public function boot(IBootContext $context): void {
		// AI-Hub, when it is installed, is told what Talk-Bot's chat is -- in every
		// request, since the hub keeps scenarios in memory only and the request that
		// works out an answer is not the one that asked. Referencing the class name
		// only produces a string, so this stays safe where AI-Hub is not present.
		if (class_exists('\\OCA\\AIHub\\Service\\HubService')) {
			$context->injectFn(static function (HubClient $hub): void {
				$hub->registerScenario();
			});
		}
	}
}
