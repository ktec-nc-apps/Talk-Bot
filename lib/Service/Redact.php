<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Service;

/**
 * Takes secrets out of a text before it is shown to anybody or written to a log.
 *
 * An error from the HTTP client carries the address it was calling. Gemini's key
 * used to travel in that address (?key=AIza…), so a time-out posted the key to the
 * room, where every participant -- guests too -- could read it (review T1).
 */
class Redact {
	/** @param list<string> $secrets values that must never appear, whatever surrounds them */
	public static function text(string $text, array $secrets = []): string {
		foreach ($secrets as $secret) {
			if (is_string($secret) && strlen($secret) >= 6) {
				$text = str_replace([$secret, rawurlencode($secret)], '•••', $text);
			}
		}
		// key=…, api_key=…, access_token=…, token=… in an address or a form body
		$text = (string)preg_replace('/((?:^|[?&;\s])(?:key|api_key|apikey|access_token|token)=)[^&\s"\'<>]+/i', '$1•••', $text);
		// Authorization: Bearer …  /  x-api-key: …  /  x-goog-api-key: …
		$text = (string)preg_replace('/(Bearer\s+)[A-Za-z0-9._\-~+\/=]+/i', '$1•••', $text);
		$text = (string)preg_replace('/((?:x-api-key|x-goog-api-key)\s*[:=]\s*)\S+/i', '$1•••', $text);
		// Google API keys and the usual "sk-" keys, wherever they appear on their own
		$text = (string)preg_replace('/\bAIza[0-9A-Za-z_\-]{20,}/', '•••', $text);
		$text = (string)preg_replace('/\bsk-[A-Za-z0-9_\-]{16,}/', '•••', $text);
		return $text;
	}

	/** Every API key the settings hold. */
	public static function keysOf(ConfigService $config): array {
		$out = [];
		foreach (['claude', 'gemini', 'openai'] as $provider) {
			$k = $config->getApiKey($provider);
			if ($k !== '') {
				$out[] = $k;
			}
		}
		return $out;
	}
}
