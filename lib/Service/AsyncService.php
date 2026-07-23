<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Service;

use OCA\TalkBot\BackgroundJob\ReplyJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Gets the slow part of answering out of the request that triggered it.
 *
 * Talk invokes in-app bots synchronously while the sender's message is still
 * being posted, so calling a model there would make everyone wait for the whole
 * generation. Instead we hand the work to a second, self-addressed request and
 * stop waiting for it after a moment; that request keeps running and posts the
 * answer when it is ready. If the server cannot reach itself at all, the work
 * falls back to a background job.
 */
class AsyncService {

	/** How long the triggering request waits before walking away. */
	private const HANDOFF_TIMEOUT = 2;

	/** A signed hand-off older than this is refused. */
	public const MAX_AGE = 300;

	public function __construct(
		private IClientService $clientService,
		private IURLGenerator $urlGenerator,
		private ISecureRandom $random,
		private ITimeFactory $timeFactory,
		private ConfigService $config,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	public function dispatch(string $token, string $userId, int $messageId, string $text): void {
		$payload = [
			'token' => $token,
			'userId' => $userId,
			'messageId' => $messageId,
			'text' => $text,
			'time' => $this->timeFactory->getTime(),
			'nonce' => $this->random->generate(32, ISecureRandom::CHAR_HUMAN_READABLE),
		];
		$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($body === false) {
			return;
		}

		try {
			$this->clientService->newClient()->post(
				$this->urlGenerator->getAbsoluteURL('/index.php/apps/talkbot/process'),
				[
					'headers' => [
						'Content-Type' => 'application/json',
						'X-TalkBot-Signature' => $this->sign($body),
					],
					'body' => $body,
					'timeout' => self::HANDOFF_TIMEOUT,
					'connect_timeout' => 10,
					'nextcloud' => ['allow_local_address' => true],
				],
			);
		} catch (\Throwable $e) {
			if ($this->isExpectedTimeout($e)) {
				// The handler is still working; this is the normal path.
				return;
			}
			$this->logger->warning(
				'Talk-Bot: could not hand the answer off over HTTP, queuing a background job instead: ' . $e->getMessage(),
				['exception' => $e],
			);
			$this->jobList->add(ReplyJob::class, [
				'token' => $token,
				'userId' => $userId,
				'messageId' => $messageId,
				'text' => $text,
			]);
		}
	}

	public function sign(string $body): string {
		return hash_hmac('sha256', $body, $this->config->getBotSecret());
	}

	public function verify(string $body, string $signature): bool {
		return $signature !== '' && hash_equals($this->sign($body), strtolower($signature));
	}

	/** A read timeout means the hand-off worked and the handler is busy. */
	private function isExpectedTimeout(\Throwable $e): bool {
		$message = strtolower($e->getMessage());
		return str_contains($message, 'timed out') || str_contains($message, 'timeout');
	}
}
