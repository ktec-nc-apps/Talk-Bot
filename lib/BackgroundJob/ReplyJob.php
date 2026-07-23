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
		$this->replyService->process(
			(string)($argument['token'] ?? ''),
			(string)($argument['userId'] ?? ''),
			(int)($argument['messageId'] ?? 0),
			(string)($argument['text'] ?? ''),
		);
	}
}
