<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Engine;

/** Anthropic Claude through the Messages API (chat only, no tools). */
class ClaudeApiEngine extends AbstractHttpEngine {

	private const BASE = 'https://api.anthropic.com/v1';
	private const VERSION = '2023-06-01';

	/** Shown when no key is set yet, so the model dropdown is never empty. */
	private const KNOWN_MODELS = [
		'claude-opus-4-8',
		'claude-opus-4-7',
		'claude-sonnet-5',
		'claude-sonnet-4-6',
		'claude-haiku-4-5',
	];

	public function getName(): string {
		return 'claude-api';
	}

	public function run(array $history, string $message, string $systemPrompt): TurnResult {
		$key = $this->config->getApiKey('claude');
		if ($key === '') {
			return TurnResult::authError('No Claude API key is configured.');
		}

		$messages = [];
		foreach ($history as $turn) {
			$messages[] = [
				'role' => $turn['role'] === 'assistant' ? 'assistant' : 'user',
				'content' => $turn['text'],
			];
		}
		$messages[] = ['role' => 'user', 'content' => $message];

		$result = $this->request('POST', self::BASE . '/messages', $this->headers($key), [
			'model' => $this->config->getModel('claude'),
			'max_tokens' => 4096,
			'system' => $systemPrompt,
			'messages' => $messages,
		]);

		if ($this->isAuthFailure($result['status'])) {
			return TurnResult::authError($this->errorMessage($result));
		}
		if ($result['status'] < 200 || $result['status'] > 299) {
			return TurnResult::error($this->errorMessage($result));
		}

		$text = '';
		foreach ($result['body']['content'] ?? [] as $block) {
			if (($block['type'] ?? '') === 'text') {
				$text .= $block['text'] ?? '';
			}
		}
		$text = trim($text);
		return $text === '' ? TurnResult::error('The model returned an empty response.') : TurnResult::ok($text);
	}

	public function listModels(): array {
		$key = $this->config->getApiKey('claude');
		if ($key === '') {
			return self::KNOWN_MODELS;
		}
		$result = $this->request('GET', self::BASE . '/models?limit=100', $this->headers($key));
		if ($result['status'] < 200 || $result['status'] > 299) {
			return self::KNOWN_MODELS;
		}
		$models = [];
		foreach ($result['body']['data'] ?? [] as $model) {
			if (isset($model['id']) && is_string($model['id'])) {
				$models[] = $model['id'];
			}
		}
		return $models === [] ? self::KNOWN_MODELS : $models;
	}

	/** @return array<string, string> */
	private function headers(string $key): array {
		return [
			'x-api-key' => $key,
			'anthropic-version' => self::VERSION,
			'content-type' => 'application/json',
		];
	}
}
