<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\AppInfo;

use OCA\TalkBot\Listener\BotInvokeListener;
use OCA\TalkBot\Settings\AdminForm;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {

	public const APP_ID = 'talkbot';

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
		$context->registerDeclarativeSettings(AdminForm::class);
	}

	public function boot(IBootContext $context): void {
	}
}
