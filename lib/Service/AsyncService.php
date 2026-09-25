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
				$this->urlGenerator->getAbsoluteURL('/index.php/apps/ktec_talkbot/process'),
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
			if ($this->handedOff($e)) {
				// The handler has the message and is still working; this is the normal path.
				return;
			}
			$this->logger->warning(
				'Talk-Bot: could not hand the answer off over HTTP, queuing a background job instead: ' . $e->getMessage(),
			);
			// The message is not put in the job's arguments: they are capped at 4000
			// bytes, and a Japanese message of 656 characters or more ran past it and
			// threw (review T5). It is left in the app config and fetched by the job.
			$ref = $this->random->generate(24, ISecureRandom::CHAR_ALPHANUMERIC);
			\OCP\Server::get(\OCP\IConfig::class)->setAppValue('ktec_talkbot', 'pending_' . $ref, $text);
			$this->jobList->add(ReplyJob::class, [
				'token' => $token,
				'userId' => $userId,
				'messageId' => $messageId,
				'ref' => $ref,
			]);
		}
	}

	public function sign(string $body): string {
		return hash_hmac('sha256', $body, $this->config->getBotSecret());
	}

	public function verify(string $body, string $signature): bool {
		return $signature !== '' && hash_equals($this->sign($body), strtolower($signature));
	}

	/**
	 * Whether the handler got the message. A READ time-out (cURL 28 "Operation timed out
	 * after …") and a proxy's 504 mean it did and is busy answering. A CONNECT time-out,
	 * a refused connection or a failed name lookup mean it did not. Telling them apart by
	 * the word "timeout" alone lost the answer in the one case and posted it twice in the
	 * other (review T4).
	 */
	private function handedOff(\Throwable $e): bool {
		for ($x = $e; $x !== null; $x = $x->getPrevious()) {
			if (method_exists($x, 'getResponse') && $x->getResponse() !== null) {
				return $x->getResponse()->getStatusCode() === 504;
			}
		}
		$message = strtolower($e->getMessage());
		return str_contains($message, 'operation timed out after');
	}
}
