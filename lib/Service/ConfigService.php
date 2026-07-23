<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Service;

use OCA\TalkBot\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * Every setting of the app, stored in the Nextcloud app configuration.
 *
 * API keys and the bot secret are written with the "sensitive" flag so they are
 * encrypted at rest and never leave the server in a config dump.
 */
class ConfigService {

	public const PROVIDERS = ['claude', 'gemini', 'openai'];
	public const MODES = ['api', 'cli'];

	/** Stands in for a stored API key so the real one never reaches the browser. */
	private const SECRET_PLACEHOLDER = '********';

	/** Fields the declarative admin form knows about: id => [key, kind, default]. */
	private const FIELDS = [
		'provider' => ['provider', 'string', 'claude'],
		'mode' => ['mode', 'string', 'api'],
		'claude_api_key' => ['claude_api_key', 'secret', ''],
		'gemini_api_key' => ['gemini_api_key', 'secret', ''],
		'openai_api_key' => ['openai_api_key', 'secret', ''],
		'openai_base_url' => ['openai_base_url', 'string', 'https://openrouter.ai/api/v1'],
		'reply_language' => ['reply_language', 'string', ''],
		'system_prompt' => ['system_prompt', 'string', ''],
		'allowlist_enabled' => ['allowlist_enabled', 'bool', false],
		'allowed_users' => ['allowed_users', 'string', ''],
		'cli_enabled' => ['cli_enabled', 'bool', false],
		'claude_cli_path' => ['claude_cli_path', 'string', 'claude'],
		'gemini_cli_path' => ['gemini_cli_path', 'string', 'gemini'],
		'cli_home' => ['cli_home', 'string', ''],
	];

	/** Model ids are chosen in the tools panel, never typed into the form. */
	private const MODEL_KEYS = [
		'claude' => 'claude_model',
		'gemini' => 'gemini_model',
		'openai' => 'openai_model',
	];

	private const MODEL_DEFAULTS = [
		'claude' => 'claude-opus-4-8',
		'gemini' => 'gemini-2.5-pro',
		'openai' => '',
	];

	public function __construct(
		private IAppConfig $appConfig,
		private ISecureRandom $random,
	) {
	}

	// -- generic helpers -----------------------------------------------------

	public function getString(string $key, string $default = ''): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
	}

	public function setString(string $key, string $value, bool $sensitive = false): void {
		$this->appConfig->setValueString(Application::APP_ID, $key, $value, false, $sensitive);
	}

	public function getBool(string $key, bool $default = false): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, $key, $default);
	}

	public function getInt(string $key, int $default): int {
		return $this->appConfig->getValueInt(Application::APP_ID, $key, $default);
	}

	// -- declarative form access --------------------------------------------

	/**
	 * @return mixed The value for a form field id, or '' for unknown fields.
	 */
	public function getFormValue(string $field): mixed {
		if (!isset(self::FIELDS[$field])) {
			return '';
		}
		[$key, $kind, $default] = self::FIELDS[$field];
		if ($kind === 'bool') {
			return $this->getBool($key, (bool)$default);
		}
		if ($kind === 'secret') {
			// Never hand a stored key back to the browser; report only whether one is set.
			return $this->getString($key) === '' ? '' : self::SECRET_PLACEHOLDER;
		}
		return $this->getString($key, (string)$default);
	}

	public function setFormValue(string $field, mixed $value): void {
		if (!isset(self::FIELDS[$field])) {
			return;
		}
		// NcSelect emits the whole option object for SELECT fields; unwrap it so we
		// never store the literal string "Array".
		if (is_array($value) && array_key_exists('value', $value)) {
			$value = $value['value'];
		}
		[$key, $kind] = self::FIELDS[$field];

		if ($kind === 'bool') {
			$this->appConfig->setValueBool(Application::APP_ID, $key, (bool)$value);
			return;
		}
		$string = is_scalar($value) ? trim((string)$value) : '';
		if ($kind === 'secret') {
			// Nextcloud replaces a stored secret with "dummySecret" before the form
			// reaches the browser; getting it back means the field was not touched.
			if ($string === self::SECRET_PLACEHOLDER || $string === 'dummySecret') {
				return;
			}
			$this->setString($key, $string, true);
			return;
		}
		if ($field === 'provider' && !in_array($string, self::PROVIDERS, true)) {
			return;
		}
		if ($field === 'mode' && !in_array($string, self::MODES, true)) {
			return;
		}
		$this->setString($key, $string);
	}

	// -- engine settings -----------------------------------------------------

	public function getProvider(): string {
		$provider = $this->getString('provider', 'claude');
		return in_array($provider, self::PROVIDERS, true) ? $provider : 'claude';
	}

	/** OpenAI-compatible endpoints are always used through their HTTP API. */
	public function getMode(): string {
		if ($this->getProvider() === 'openai') {
			return 'api';
		}
		$mode = $this->getString('mode', 'api');
		if ($mode === 'cli' && !$this->getBool('cli_enabled')) {
			return 'api';
		}
		return in_array($mode, self::MODES, true) ? $mode : 'api';
	}

	public function getApiKey(?string $provider = null): string {
		return $this->getString(($provider ?? $this->getProvider()) . '_api_key');
	}

	public function getModel(?string $provider = null): string {
		$provider = $provider ?? $this->getProvider();
		$key = self::MODEL_KEYS[$provider] ?? null;
		return $key === null ? '' : $this->getString($key, self::MODEL_DEFAULTS[$provider]);
	}

	public function setModel(string $model, ?string $provider = null): void {
		$provider = $provider ?? $this->getProvider();
		if (isset(self::MODEL_KEYS[$provider])) {
			$this->setString(self::MODEL_KEYS[$provider], $model);
		}
	}

	public function getOpenAiBaseUrl(): string {
		return rtrim($this->getString('openai_base_url', 'https://openrouter.ai/api/v1'), '/');
	}

	public function getCliPath(?string $provider = null): string {
		$provider = $provider ?? $this->getProvider();
		return $provider === 'gemini'
			? $this->getString('gemini_cli_path', 'gemini')
			: $this->getString('claude_cli_path', 'claude');
	}

	public function isCliEnabled(): bool {
		return $this->getBool('cli_enabled');
	}

	public function getCliHome(): string {
		return $this->getString('cli_home');
	}

	// -- behaviour -----------------------------------------------------------

	public function getReplyLanguage(): string {
		return $this->getString('reply_language');
	}

	public function getExtraSystemPrompt(): string {
		return $this->getString('system_prompt');
	}

	public function isAllowlistEnabled(): bool {
		return $this->getBool('allowlist_enabled');
	}

	/** @return list<string> */
	public function getAllowedUsers(): array {
		$raw = $this->getString('allowed_users');
		$users = array_filter(array_map('trim', explode(',', $raw)), static fn (string $u): bool => $u !== '');
		return array_values($users);
	}

	public function getMaxHistoryTurns(): int {
		return max(0, min(100, $this->getInt('max_history_turns', 10)));
	}

	public function getMaxResponseLength(): int {
		return max(500, min(30000, $this->getInt('max_response_length', 3500)));
	}

	public function getRequestTimeout(): int {
		return max(10, min(600, $this->getInt('request_timeout', 180)));
	}

	// -- bot identity --------------------------------------------------------

	/** The shared secret Talk uses to authenticate our replies. Created on demand. */
	public function getBotSecret(): string {
		$secret = $this->getString('bot_secret');
		if (strlen($secret) < 40) {
			$secret = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->setString('bot_secret', $secret, true);
		}
		return $secret;
	}
}
