<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Controller;

use OCA\TalkBot\Engine\CliEngine;
use OCA\TalkBot\Engine\EngineFactory;
use OCA\TalkBot\Service\ConfigService;
use OCA\TalkBot\Settings\AdminTools;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/** Backs the model picker and the connection test in the admin settings. */
class ToolController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $config,
		private EngineFactory $engineFactory,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	/** Which models the current settings can use, plus what each engine looks like. */
	#[AuthorizedAdminSetting(settings: AdminTools::class)]
	public function models(): JSONResponse {
		$provider = $this->config->getProvider();
		$mode = $this->config->getMode();

		$models = [];
		$note = '';
		try {
			$models = $this->engineFactory->get()->listModels();
		} catch (\Throwable $e) {
			$note = $e->getMessage();
		}

		if ($models === []) {
			$note = $note !== '' ? $note : $this->l->t('No model list could be retrieved. Check the API key and the base URL.');
		} elseif ($provider !== 'openai' && $this->config->getApiKey() === '' && $mode === 'api') {
			$note = $this->l->t('No API key is set yet, so this is the list of well known models rather than yours.');
		}

		return new JSONResponse([
			'current' => [
				'provider' => $provider,
				'mode' => $mode,
				'model' => $this->config->getModel(),
			],
			'models' => array_values($models),
			'engines' => $this->probeEngines(),
			'note' => $note,
		]);
	}

	/** Really call the configured engine once. */
	#[AuthorizedAdminSetting(settings: AdminTools::class)]
	public function test(): JSONResponse {
		$provider = $this->config->getProvider();
		$mode = $this->config->getMode();
		$model = $this->config->getModel();

		if ($model === '') {
			return new JSONResponse([
				'ok' => false,
				'detail' => $this->l->t('Pick a model first.'),
			]);
		}

		try {
			$result = $this->engineFactory->get()->run(
				[],
				'Reply with the single word: ok',
				'You are a connection test. Answer with one short word.',
			);
		} catch (\Throwable $e) {
			return new JSONResponse(['ok' => false, 'detail' => $e->getMessage()]);
		}

		return new JSONResponse([
			'ok' => $result->isOk(),
			'engine' => $provider . ' / ' . $mode,
			'model' => $model,
			'reply' => mb_substr($result->output, 0, 200),
			'detail' => mb_substr($result->detail, 0, 500),
		]);
	}

	/** Store the model chosen in the dropdown. */
	#[AuthorizedAdminSetting(settings: AdminTools::class)]
	public function setModel(string $model): JSONResponse {
		$model = trim($model);
		if ($model === '' || mb_strlen($model) > 200) {
			return new JSONResponse(['ok' => false, 'detail' => $this->l->t('That is not a valid model name.')]);
		}
		$this->config->setModel($model);
		return new JSONResponse(['ok' => true, 'model' => $model]);
	}

	/**
	 * A quick, cheap look at every engine, so the admin can see what is ready to
	 * use without switching the live setting back and forth.
	 *
	 * @return list<array{id: string, label: string, ready: bool, detail: string}>
	 */
	private function probeEngines(): array {
		$engines = [];

		foreach (['claude' => 'Claude', 'gemini' => 'Gemini'] as $provider => $label) {
			$hasKey = $this->config->getApiKey($provider) !== '';
			$engines[] = [
				'id' => $provider . '/api',
				'label' => $label . ' — ' . $this->l->t('API key'),
				'ready' => $hasKey,
				'detail' => $hasKey ? $this->l->t('API key is set.') : $this->l->t('No API key.'),
			];
		}

		$baseUrl = $this->config->getOpenAiBaseUrl();
		$engines[] = [
			'id' => 'openai/api',
			'label' => $this->l->t('OpenAI-compatible'),
			'ready' => $baseUrl !== '',
			'detail' => $baseUrl,
		];

		if ($this->config->isCliEnabled()) {
			foreach (['claude' => 'Claude', 'gemini' => 'Gemini'] as $provider => $label) {
				$engine = $this->engineFactory->build($provider, 'cli');
				$check = $engine instanceof CliEngine
					? $engine->checkBinary()
					: ['ok' => false, 'detail' => ''];
				$engines[] = [
					'id' => $provider . '/cli',
					'label' => $label . ' — ' . $this->l->t('command line tool'),
					'ready' => (bool)$check['ok'],
					'detail' => (string)$check['detail'],
				];
			}
		}

		return $engines;
	}
}
