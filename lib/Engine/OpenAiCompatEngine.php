<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Engine;

/**
 * Any endpoint that speaks the OpenAI chat completions API.
 *
 * Tested against OpenRouter, DeepSeek, Qwen/DashScope, Mistral, Groq, OpenAI and
 * local servers such as Ollama, vLLM and LM Studio.
 */
class OpenAiCompatEngine extends AbstractHttpEngine {

	public function getName(): string {
		return 'openai-compatible';
	}

	public function run(array $history, string $message, string $systemPrompt, bool $elevated = false): TurnResult {
		$messages = [['role' => 'system', 'content' => $systemPrompt]];
		foreach ($history as $turn) {
			$messages[] = [
				'role' => $turn['role'] === 'assistant' ? 'assistant' : 'user',
				'content' => $turn['text'],
			];
		}
		$messages[] = ['role' => 'user', 'content' => $message];

		$model = $this->config->getModel('openai');
		if ($model === '') {
			return TurnResult::error('No model is selected. Pick one in the Talk-Bot admin settings.');
		}

		$result = $this->request(
			'POST',
			$this->config->getOpenAiBaseUrl() . '/chat/completions',
			$this->headers(),
			['model' => $model, 'messages' => $messages],
		);

		if ($this->isAuthFailure($result['status'])) {
			return TurnResult::authError($this->errorMessage($result));
		}
		if ($result['status'] < 200 || $result['status'] > 299) {
			return TurnResult::error($this->errorMessage($result));
		}

		$text = trim((string)($result['body']['choices'][0]['message']['content'] ?? ''));
		return $text === '' ? TurnResult::error('The model returned an empty response.') : TurnResult::ok($text);
	}

	public function listModels(): array {
		$result = $this->request('GET', $this->config->getOpenAiBaseUrl() . '/models', $this->headers());
		if ($result['status'] < 200 || $result['status'] > 299) {
			return [];
		}
		$models = [];
		foreach ($result['body']['data'] ?? [] as $model) {
			if (isset($model['id']) && is_string($model['id'])) {
				$models[] = $model['id'];
			}
		}
		sort($models);
		return $models;
	}

	/** @return array<string, string> */
	private function headers(): array {
		$headers = ['content-type' => 'application/json'];
		$key = $this->config->getApiKey('openai');
		if ($key !== '') {
			$headers['authorization'] = 'Bearer ' . $key;
		}
		return $headers;
	}
}
