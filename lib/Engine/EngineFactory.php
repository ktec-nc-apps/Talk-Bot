<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Engine;

use OCA\TalkBot\Service\ConfigService;
use OCP\Http\Client\IClientService;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/** Builds the engine selected by the admin: provider × mode. */
class EngineFactory {

	public function __construct(
		private ConfigService $config,
		private IClientService $clientService,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	/** The engine for the current settings. */
	public function get(): IEngine {
		return $this->build($this->config->getProvider(), $this->config->getMode());
	}

	public function build(string $provider, string $mode): IEngine {
		if ($provider !== 'openai' && $mode === 'cli') {
			return new CliEngine($this->config, $this->tempManager, $this->logger, $provider);
		}
		return match ($provider) {
			'gemini' => new GeminiApiEngine($this->config, $this->clientService, $this->logger),
			'openai' => new OpenAiCompatEngine($this->config, $this->clientService, $this->logger),
			default => new ClaudeApiEngine($this->config, $this->clientService, $this->logger),
		};
	}
}
