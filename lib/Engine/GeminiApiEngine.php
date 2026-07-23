<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Engine;

/** Google Gemini through the Generative Language API (chat only, no tools). */
class GeminiApiEngine extends AbstractHttpEngine {

	private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

	private const KNOWN_MODELS = [
		'gemini-2.5-pro',
		'gemini-2.5-flash',
		'gemini-2.0-flash',
		'gemini-1.5-pro',
		'gemini-1.5-flash',
	];

	public function getName(): string {
		return 'gemini-api';
	}

	public function run(array $history, string $message, string $systemPrompt): TurnResult {
		$key = $this->config->getApiKey('gemini');
		if ($key === '') {
			return TurnResult::authError('No Gemini API key is configured.');
		}

		$contents = [];
		foreach ($history as $turn) {
			$contents[] = [
				'role' => $turn['role'] === 'assistant' ? 'model' : 'user',
				'parts' => [['text' => $turn['text']]],
			];
		}
		$contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

		$url = self::BASE . '/models/' . rawurlencode($this->config->getModel('gemini'))
			. ':generateContent?key=' . rawurlencode($key);

		$result = $this->request('POST', $url, ['content-type' => 'application/json'], [
			'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
			'contents' => $contents,
		]);

		if ($this->isAuthFailure($result['status'])) {
			return TurnResult::authError($this->errorMessage($result));
		}
		if ($result['status'] < 200 || $result['status'] > 299) {
			return TurnResult::error($this->errorMessage($result));
		}

		$text = '';
		foreach ($result['body']['candidates'][0]['content']['parts'] ?? [] as $part) {
			$text .= $part['text'] ?? '';
		}
		$text = trim($text);
		return $text === '' ? TurnResult::error('The model returned an empty response.') : TurnResult::ok($text);
	}

	public function listModels(): array {
		$key = $this->config->getApiKey('gemini');
		if ($key === '') {
			return self::KNOWN_MODELS;
		}
		$result = $this->request('GET', self::BASE . '/models?pageSize=200&key=' . rawurlencode($key), []);
		if ($result['status'] < 200 || $result['status'] > 299) {
			return self::KNOWN_MODELS;
		}
		$models = [];
		foreach ($result['body']['models'] ?? [] as $model) {
			$methods = $model['supportedGenerationMethods'] ?? [];
			if (!in_array('generateContent', $methods, true)) {
				continue;
			}
			$name = (string)($model['name'] ?? '');
			if (str_starts_with($name, 'models/')) {
				$models[] = substr($name, strlen('models/'));
			}
		}
		return $models === [] ? self::KNOWN_MODELS : $models;
	}
}
