<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Engine;

use OCA\TalkBot\Service\ConfigService;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Shared JSON plumbing for the HTTP based engines.
 *
 * Local addresses are allowed on purpose: pointing the bot at Ollama, vLLM or
 * LM Studio on the same machine is one of its supported setups.
 */
abstract class AbstractHttpEngine implements IEngine {

	public function __construct(
		protected ConfigService $config,
		protected IClientService $clientService,
		protected LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string, string> $headers
	 * @return array{status: int, body: array<mixed>, error: string}
	 */
	protected function request(string $method, string $url, array $headers, ?array $json = null): array {
		$options = [
			'headers' => $headers,
			'timeout' => $this->config->getRequestTimeout(),
			'connect_timeout' => 15,
			'nextcloud' => ['allow_local_address' => true],
		];
		if ($json !== null) {
			$options['json'] = $json;
		}

		try {
			$client = $this->clientService->newClient();
			$response = $method === 'GET'
				? $client->get($url, $options)
				: $client->post($url, $options);
			$status = $response->getStatusCode();
			$body = (string)$response->getBody();
		} catch (\Throwable $e) {
			// Guzzle throws on 4xx/5xx; dig the real response out so the admin sees
			// the provider's own error message instead of a generic exception.
			$status = 0;
			$body = '';
			$previous = $e;
			while ($previous !== null) {
				if (method_exists($previous, 'getResponse')) {
					$response = $previous->getResponse();
					if ($response !== null) {
						$status = $response->getStatusCode();
						$body = (string)$response->getBody();
						break;
					}
				}
				$previous = $previous->getPrevious();
			}
			if ($status === 0) {
				return ['status' => 0, 'body' => [], 'error' => $e->getMessage()];
			}
		}

		$decoded = json_decode($body, true);
		return [
			'status' => $status,
			'body' => is_array($decoded) ? $decoded : [],
			'error' => '',
		];
	}

	/** Pull a human readable message out of a provider error body. */
	protected function errorMessage(array $result): string {
		if ($result['error'] !== '') {
			return $result['error'];
		}
		$error = $result['body']['error'] ?? null;
		if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
			return $error['message'];
		}
		if (is_string($error) && $error !== '') {
			return $error;
		}
		return 'HTTP ' . $result['status'];
	}

	protected function isAuthFailure(int $status): bool {
		return $status === 401 || $status === 403;
	}
}
