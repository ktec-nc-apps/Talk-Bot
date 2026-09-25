<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\BackgroundJob;

use OCA\TalkBot\Service\ConfigService;
use OCA\TalkBot\Service\SessionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Drops conversation histories older than the administrator's limit, once a day (review T15). */
class HistoryExpiry extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ConfigService $config,
		private SessionService $sessions,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$days = $this->config->getHistoryDays();
		if ($days > 0) {
			$this->sessions->deleteOlderThan($this->time->getTime() - $days * 86400);
		}
	}
}
