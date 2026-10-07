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
 * Talk-Bot answers through AI-Hub and no longer holds any API key of its own, but
 * an error that bubbles up from AI-Hub or the provider can still carry one, so
 * every error the bot reports or logs is scrubbed first: a key in a URL, a Bearer
 * token, an "sk-" or Google key, wherever it appears (review T1).
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
}
