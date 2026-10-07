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
 * The AI service, key and model no longer live here: Talk-Bot answers through
 * AI-Hub (ai_hub), which holds them for every app of the Base series. What is
 * left is Talk-specific: how the bot replies, who may use it, how much it
 * remembers and how fast it may be asked, plus the secret that signs its own
 * off-request hand-off. The bot secret is written with the "sensitive" flag so
 * it is encrypted at rest and never leaves the server in a config dump.
 */
class ConfigService {

	/** Fields the declarative admin form knows about: id => [key, kind, default]. */
	private const FIELDS = [
		'reply_language' => ['reply_language', 'string', ''],
		'system_prompt' => ['system_prompt', 'string', ''],
		'allowlist_enabled' => ['allowlist_enabled', 'bool', false],
		'allowed_users' => ['allowed_users', 'string', ''],
		'history_days' => ['history_days', 'int', 0],
		'rate_per_minute' => ['rate_per_minute', 'int', 10],
		'max_parallel_user' => ['max_parallel_user', 'int', 2],
		'max_parallel_total' => ['max_parallel_total', 'int', 4],
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
		if ($kind === 'int') {
			return $this->getInt($key, (int)$default);
		}
		return $this->getString($key, (string)$default);
	}

	public function setFormValue(string $field, mixed $value): void {
		if (!isset(self::FIELDS[$field])) {
			return;
		}
		[$key, $kind] = self::FIELDS[$field];

		if ($kind === 'bool') {
			$this->appConfig->setValueBool(Application::APP_ID, $key, (bool)$value);
			return;
		}
		if ($kind === 'int') {
			// 0 means no limit; anything that is not a whole number leaves the value alone
			if (is_numeric($value) && (int)$value >= 0) {
				$this->appConfig->setValueInt(Application::APP_ID, $key, min(100000, (int)$value));
			}
			return;
		}
		$string = is_scalar($value) ? trim((string)$value) : '';
		$this->setString($key, $string);
	}

	// -- limits (0 = no limit) ----------------------------------------------

	/** Days a conversation's history is kept after its last message; 0 = kept until reset. */
	public function getHistoryDays(): int {
		return max(0, $this->getInt('history_days', 0));
	}

	public function getRatePerMinute(): int {
		return max(0, $this->getInt('rate_per_minute', 10));
	}

	public function getMaxParallelPerUser(): int {
		return max(0, $this->getInt('max_parallel_user', 2));
	}

	public function getMaxParallelTotal(): int {
		return max(0, $this->getInt('max_parallel_total', 4));
	}

	// -- behaviour -----------------------------------------------------------

	public function getReplyLanguage(): string {
		return $this->getString('reply_language');
	}

	/** Per-conversation reply-language override, set with the ?lang command. */
	public function getRoomLanguage(string $token): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'roomlang.' . $token, '', true);
	}

	public function setRoomLanguage(string $token, string $code): void {
		if ($code === '') {
			$this->appConfig->deleteKey(Application::APP_ID, 'roomlang.' . $token);
			return;
		}
		// lazy: read only when a message arrives, not on every Nextcloud request (review T14)
		$this->appConfig->setValueString(Application::APP_ID, 'roomlang.' . $token, $code, true);
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
