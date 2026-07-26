<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Settings;

use OCA\TalkBot\Service\ConfigService;
use OCP\IL10N;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * The engine choice: which AI service answers and how we reach it.
 *
 * This is the first thing in the section (priority 10). The model picker and
 * connection test (AdminTools, priority 20) sit directly beneath it, and the
 * keys, paths and access rules (AdminFormAccess, priority 30) come after — so
 * the model is chosen right under the engine, not buried at the bottom.
 */
class AdminForm implements IDeclarativeSettingsFormWithHandlers {

	public function __construct(
		private IL10N $l,
		private ConfigService $config,
	) {
	}

	public function getValue(string $fieldId, IUser $user): mixed {
		$value = $this->config->getFormValue($fieldId);
		// NcSelect shows a nice label only when it is handed the whole option
		// object; a bare stored code ("claude", "cli") is otherwise rendered
		// verbatim as the selected value. Saving still unwraps it back to the code.
		if ($fieldId === 'provider') {
			return $this->optionFor($this->providerOptions(), $value);
		}
		if ($fieldId === 'mode') {
			return $this->optionFor($this->modeOptions(), $value);
		}
		return $value;
	}

	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		$this->config->setFormValue($fieldId, $value);
	}

	/** @param list<array{name: string, label: string, value: string}> $options */
	private function optionFor(array $options, mixed $value): mixed {
		foreach ($options as $option) {
			if ($option['value'] === $value) {
				return $option;
			}
		}
		return $value;
	}

	/** @return list<array{name: string, label: string, value: string}> */
	private function providerOptions(): array {
		return [
			['name' => 'Claude', 'label' => 'Claude', 'value' => 'claude'],
			['name' => 'Gemini', 'label' => 'Gemini', 'value' => 'gemini'],
			['name' => $this->l->t('OpenAI-compatible'), 'label' => $this->l->t('OpenAI-compatible'), 'value' => 'openai'],
		];
	}

	/** @return list<array{name: string, label: string, value: string}> */
	private function modeOptions(): array {
		return [
			['name' => $this->l->t('API key'), 'label' => $this->l->t('API key'), 'value' => 'api'],
			['name' => $this->l->t('Command line tool on this server'), 'label' => $this->l->t('Command line tool on this server'), 'value' => 'cli'],
		];
	}

	public function getSchema(): array {
		return [
			'id' => 'ktec_talkbot-admin',
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'ktec_talkbot',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => $this->l->t('AI engine'),
			'description' => $this->l->t('Choose which AI service answers and how to reach it, then pick a model and run a connection test in the panel just below. Moderators switch the bot on per conversation, under Conversation settings → Bots.'),

			'fields' => [
				[
					'id' => 'provider',
					'title' => $this->l->t('AI service'),
					'description' => $this->l->t('An OpenAI-compatible endpoint covers OpenRouter, DeepSeek, Qwen, Mistral, Groq, OpenAI and local servers such as Ollama.'),
					'type' => DeclarativeSettingsTypes::SELECT,
					'default' => 'claude',
					// NcSelect takes the visible text from `label`; options with only a
					// name render as "undefined".
					'options' => $this->providerOptions(),
				],
				[
					'id' => 'mode',
					'title' => $this->l->t('How to reach it'),
					'description' => $this->l->t('OpenAI-compatible endpoints always use an API key. The command line option needs to be enabled under Keys and access below.'),
					'type' => DeclarativeSettingsTypes::SELECT,
					'default' => 'api',
					'options' => $this->modeOptions(),
				],
			],
		];
	}
}
