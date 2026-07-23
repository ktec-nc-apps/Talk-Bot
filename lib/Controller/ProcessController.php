<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Controller;

use OCA\TalkBot\Service\AsyncService;
use OCA\TalkBot\Service\ReplyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

/**
 * Runs one answer, in a request of its own.
 *
 * Only reachable with a signature made from the bot secret, which never leaves
 * the server, so this is not an entry point for anyone else.
 */
class ProcessController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private AsyncService $async,
		private ReplyService $replyService,
		private ITimeFactory $timeFactory,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'talkbotProcess')]
	public function process(): JSONResponse {
		$body = file_get_contents('php://input');
		if (!is_string($body) || $body === '') {
			return new JSONResponse(['status' => 'empty'], Http::STATUS_BAD_REQUEST);
		}

		if (!$this->async->verify($body, $this->request->getHeader('X-TalkBot-Signature'))) {
			$response = new JSONResponse(['status' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'talkbotProcess']);
			return $response;
		}

		$payload = json_decode($body, true);
		if (!is_array($payload)) {
			return new JSONResponse(['status' => 'malformed'], Http::STATUS_BAD_REQUEST);
		}

		$age = $this->timeFactory->getTime() - (int)($payload['time'] ?? 0);
		if ($age > AsyncService::MAX_AGE || $age < -AsyncService::MAX_AGE) {
			return new JSONResponse(['status' => 'stale'], Http::STATUS_BAD_REQUEST);
		}

		// The caller stops waiting after a couple of seconds; keep going anyway.
		ignore_user_abort(true);
		@set_time_limit(0);

		$this->replyService->process(
			(string)($payload['token'] ?? ''),
			(string)($payload['userId'] ?? ''),
			(int)($payload['messageId'] ?? 0),
			(string)($payload['text'] ?? ''),
		);

		return new JSONResponse(['status' => 'done']);
	}
}
