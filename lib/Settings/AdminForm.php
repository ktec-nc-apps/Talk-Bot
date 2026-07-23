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
 * The settings Nextcloud renders for us.
 *
 * The model is deliberately absent here: it is picked from the list of models
 * the key can actually use, in the panel below this form, so that there is only
 * ever one place to set it.
 */
class AdminForm implements IDeclarativeSettingsFormWithHandlers {

	public function __construct(
		private IL10N $l,
		private ConfigService $config,
	) {
	}

	public function getValue(string $fieldId, IUser $user): mixed {
		return $this->config->getFormValue($fieldId);
	}

	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		$this->config->setFormValue($fieldId, $value);
	}

	public function getSchema(): array {
		return [
			'id' => 'talkbot-admin',
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'talkbot',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => $this->l->t('AI engine and access'),
			'description' => $this->l->t('Choose which AI service answers, then pick a model and run a connection test in the panel below. Moderators switch the bot on per conversation, under Conversation settings → Bots.'),

			'fields' => [
				[
					'id' => 'provider',
					'title' => $this->l->t('AI service'),
					'description' => $this->l->t('An OpenAI-compatible endpoint covers OpenRouter, DeepSeek, Qwen, Mistral, Groq, OpenAI and local servers such as Ollama.'),
					'type' => DeclarativeSettingsTypes::SELECT,
					'default' => 'claude',
					// NcSelect takes the visible text from `label`; options with only a
					// name render as "undefined".
					'options' => [
						['name' => 'Claude', 'label' => 'Claude', 'value' => 'claude'],
						['name' => 'Gemini', 'label' => 'Gemini', 'value' => 'gemini'],
						['name' => $this->l->t('OpenAI-compatible'), 'label' => $this->l->t('OpenAI-compatible'), 'value' => 'openai'],
					],
				],
				[
					'id' => 'mode',
					'title' => $this->l->t('How to reach it'),
					'description' => $this->l->t('OpenAI-compatible endpoints always use an API key. The command line option needs to be enabled further down.'),
					'type' => DeclarativeSettingsTypes::SELECT,
					'default' => 'api',
					'options' => [
						['name' => $this->l->t('API key'), 'label' => $this->l->t('API key'), 'value' => 'api'],
						['name' => $this->l->t('Command line tool on this server'), 'label' => $this->l->t('Command line tool on this server'), 'value' => 'cli'],
					],
				],
				[
					'id' => 'claude_api_key',
					'title' => $this->l->t('Claude API key'),
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'default' => '',
					'sensitive' => true,
				],
				[
					'id' => 'gemini_api_key',
					'title' => $this->l->t('Gemini API key'),
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'default' => '',
					'sensitive' => true,
				],
				[
					'id' => 'openai_api_key',
					'title' => $this->l->t('API key for the OpenAI-compatible endpoint'),
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'default' => '',
					'sensitive' => true,
				],
				[
					'id' => 'openai_base_url',
					'title' => $this->l->t('Base URL of the OpenAI-compatible endpoint'),
					'description' => $this->l->t('For example https://openrouter.ai/api/v1, https://api.openai.com/v1 or http://localhost:11434/v1'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'https://openrouter.ai/api/v1',
					'default' => 'https://openrouter.ai/api/v1',
				],
				[
					'id' => 'reply_language',
					'title' => $this->l->t('Reply language'),
					'description' => $this->l->t('A language code such as en, ja or de. Leave empty to answer in whatever language the message was written in.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => $this->l->t('empty = follow the user'),
					'default' => '',
				],
				[
					'id' => 'system_prompt',
					'title' => $this->l->t('Extra instructions for the assistant'),
					'description' => $this->l->t('Added to every conversation, for example a tone of voice or facts about your organisation.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'default' => '',
				],
				[
					'id' => 'allowlist_enabled',
					'title' => $this->l->t('Restrict the bot to selected users'),
					'description' => $this->l->t('When off, everyone in a conversation the bot was switched on in may use it.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => false,
				],
				[
					'id' => 'allowed_users',
					'title' => $this->l->t('Allowed users'),
					'description' => $this->l->t('Comma separated user IDs, used only while the restriction above is on.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'alice, bob',
					'default' => '',
				],
				[
					'id' => 'cli_enabled',
					'title' => $this->l->t('Allow the command line option'),
					'description' => $this->l->t('Only turn this on if a Claude or Gemini command line tool is installed on this server and you want to use your subscription instead of an API key. The tool runs as the web server user.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => false,
				],
				[
					'id' => 'claude_cli_path',
					'title' => $this->l->t('Path to the Claude command line tool'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '/usr/local/bin/claude',
					'default' => 'claude',
				],
				[
					'id' => 'gemini_cli_path',
					'title' => $this->l->t('Path to the Gemini command line tool'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '/usr/local/bin/gemini',
					'default' => 'gemini',
				],
				[
					'id' => 'cli_home',
					'title' => $this->l->t('Home directory for the command line tool'),
					'description' => $this->l->t('Where the tool keeps its login. Must be readable and writable by the web server user.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '/var/lib/talkbot',
					'default' => '',
				],
				[
					'id' => 'cli_user_tools',
					'title' => $this->l->t('Tools for ordinary users'),
					'description' => $this->l->t('Empty means no tools at all: the bot can only talk. Otherwise a comma separated list, for example WebSearch. Applies to everyone who is not a Nextcloud administrator.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => $this->l->t('empty = no tools (recommended)'),
					'default' => '',
				],
				[
					'id' => 'cli_admin_tools',
					'title' => $this->l->t('Tools for Nextcloud administrators'),
					'description' => $this->l->t('⚠ Leave empty unless you mean it. Anything you put here — "default" for all tools, or a list such as Bash,Read,Edit — lets every member of the admin group run it on this server from a chat message, with the rights of the web server user. Empty means administrators get the same as everyone else.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => $this->l->t('empty = administrators get no tools either'),
					'default' => '',
				],
			],
		];
	}
}
