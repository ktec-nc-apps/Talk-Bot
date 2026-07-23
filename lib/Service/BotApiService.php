<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Service;

use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Posts messages and reactions back into Talk through its public bot API.
 *
 * The reply is produced after the message that triggered it was already
 * answered, so it cannot be handed back through the invoke event; going through
 * the documented HTTP API keeps us off Talk's internal classes.
 */
class BotApiService {

	private const API = '/ocs/v2.php/apps/spreed/api/v1/bot/';

	public function __construct(
		private IClientService $clientService,
		private IURLGenerator $urlGenerator,
		private ISecureRandom $random,
		private ConfigService $config,
		private LoggerInterface $logger,
	) {
	}

	public function sendMessage(string $token, string $message, int $replyTo = 0, bool $silent = false): bool {
		$body = ['message' => $message, 'referenceId' => sha1($this->random->generate(32))];
		if ($replyTo > 0) {
			$body['replyTo'] = $replyTo;
		}
		if ($silent) {
			$body['silent'] = true;
		}
		return $this->call('POST', $token . '/message', $message, $body);
	}

	public function addReaction(string $token, int $messageId, string $reaction): bool {
		return $this->call('POST', $token . '/reaction/' . $messageId, $reaction, ['reaction' => $reaction]);
	}

	public function removeReaction(string $token, int $messageId, string $reaction): bool {
		return $this->call('DELETE', $token . '/reaction/' . $messageId, $reaction, ['reaction' => $reaction]);
	}

	/**
	 * @param string $signedData The exact value Talk signs for this endpoint.
	 * @param array<string, mixed> $body
	 */
	private function call(string $method, string $path, string $signedData, array $body): bool {
		$secret = $this->config->getBotSecret();
		$random = $this->random->generate(64, ISecureRandom::CHAR_HUMAN_READABLE);

		$url = rtrim($this->urlGenerator->getAbsoluteURL(self::API), '/') . '/' . $path;
		$options = [
			'headers' => [
				'OCS-APIRequest' => 'true',
				'Accept' => 'application/json',
				'X-Nextcloud-Talk-Bot-Random' => $random,
				'X-Nextcloud-Talk-Bot-Signature' => hash_hmac('sha256', $random . $signedData, $secret),
			],
			'json' => $body,
			'timeout' => 30,
			'connect_timeout' => 10,
			'nextcloud' => ['allow_local_address' => true],
		];

		try {
			$client = $this->clientService->newClient();
			$response = $method === 'DELETE'
				? $client->delete($url, $options)
				: $client->post($url, $options);
			$status = $response->getStatusCode();
			if ($status >= 200 && $status <= 299) {
				return true;
			}
			$this->logger->warning('Talk Bot: Talk refused a bot request', ['status' => $status, 'path' => $path]);
		} catch (\Throwable $e) {
			$this->logger->warning('Talk Bot: could not reach the Talk bot API: ' . $e->getMessage(), ['exception' => $e]);
		}
		return false;
	}
}
