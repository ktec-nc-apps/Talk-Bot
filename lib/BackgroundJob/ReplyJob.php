<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\BackgroundJob;

use OCA\TalkBot\Service\ReplyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Fallback for servers that cannot address themselves over HTTP: the answer is
 * produced by the job runner instead, which means it arrives on the next cron
 * run rather than immediately.
 */
class ReplyJob extends QueuedJob {

	public function __construct(
		ITimeFactory $time,
		private ReplyService $replyService,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		if (!is_array($argument)) {
			return;
		}
		$text = (string)($argument['text'] ?? '');
		$ref = (string)($argument['ref'] ?? '');
		if ($ref !== '' && preg_match('/^[A-Za-z0-9]+$/', $ref)) {
			$cfg = \OCP\Server::get(\OCP\IConfig::class);
			$text = $cfg->getAppValue('ktec_talkbot', 'pending_' . $ref, '');
			$cfg->deleteAppValue('ktec_talkbot', 'pending_' . $ref);
		}
		if ($text === '') {
			return;
		}
		$this->replyService->process(
			(string)($argument['token'] ?? ''),
			(string)($argument['userId'] ?? ''),
			(int)($argument['messageId'] ?? 0),
			$text,
		);
	}
}
