<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Settings;

use OCA\TalkBot\Service\ConfigService;
use OCA\TalkBot\Service\HubClient;
use OCP\IL10N;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * Talk-Bot's own settings.
 *
 * The AI service, key and model are AI-Hub's now, so this is only what is specific
 * to the Talk side: how the bot replies, who may use it, how much it remembers and
 * how fast it may be asked. A notice at the top says so, and warns when AI-Hub is
 * absent or not set up, because without it the bot cannot answer.
 */
class AdminFormAccess implements IDeclarativeSettingsFormWithHandlers {

	public function __construct(
		private IL10N $l,
		private ConfigService $config,
		private HubClient $hub,
	) {
	}

	public function getValue(string $fieldId, IUser $user): mixed {
		return $this->config->getFormValue($fieldId);
	}

	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		$this->config->setFormValue($fieldId, $value);
	}

	/**
	 * The section description, with a line about AI-Hub first: Talk-Bot answers
	 * through it, and cannot answer without it.
	 */
	private function description(): string {
		$intro = $this->l->t('Talk-Bot answers through AI-Hub. The AI service, key and model are set in AI-Hub, under Administration settings → AI-Hub, and the same AI-Hub serves the other apps too. Below are only the settings specific to Talk.');
		if (!$this->hub->present()) {
			return '⚠ ' . $this->l->t('AI-Hub is not installed, so the bot cannot answer yet. Install it from the App Store (search for "AI-Hub"), then set the AI service, key and model in its settings.') . "\n\n" . $intro;
		}
		if (!$this->hub->ready()) {
			return '⚠ ' . $this->l->t('AI-Hub is installed but not set up yet, so the bot cannot answer. Open Administration settings → AI-Hub and choose the AI service, key and model.') . "\n\n" . $intro;
		}
		return $intro;
	}

	public function getSchema(): array {
		return [
			'id' => 'ktec_talkbot-access',
			'priority' => 10,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'ktec_talkbot',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => $this->l->t('Talk-Bot'),
			'description' => $this->description(),

			'fields' => [
				[
					'id' => 'reply_language',
					'title' => $this->l->t('Reply language'),
					'description' => $this->l->t('A language code such as en, ja or de to force one language for everyone. Leave empty to answer each user in the language they chose in Nextcloud.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => $this->l->t('empty = each user\'s own language'),
					'default' => '',
				],
				[
					'id' => 'system_prompt',
					'title' => $this->l->t('Extra instructions for the assistant'),
					'description' => $this->l->t('Added to every conversation, for example a tone of voice or facts about your organization.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'default' => '',
				],
				[
					'id' => 'allowlist_enabled',
					'title' => $this->l->t('Restrict the bot to selected users'),
					'description' => $this->l->t('When off, anyone in a conversation where the bot is enabled can use it.'),
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'default' => false,
				],
				[
					'id' => 'allowed_users',
					'title' => $this->l->t('Allowed users'),
					'description' => $this->l->t('Comma-separated user IDs, used only while the restriction above is on.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'alice, bob',
					'default' => '',
				],
				[
					'id' => 'history_days',
					'title' => $this->l->t('Days to keep a conversation\'s history'),
					'description' => $this->l->t('What the bot remembers of a conversation is forgotten this many days after its last message. 0 = kept until the conversation is reset with ?reset.'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => 0,
				],
				[
					'id' => 'rate_per_minute',
					'title' => $this->l->t('Questions per user per minute'),
					'description' => $this->l->t('0 = no limit.'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => 10,
				],
				[
					'id' => 'max_parallel_user',
					'title' => $this->l->t('Answers in progress at once, per user'),
					'description' => $this->l->t('0 = no limit.'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => 2,
				],
				[
					'id' => 'max_parallel_total',
					'title' => $this->l->t('Answers in progress at once, for everyone together'),
					'description' => $this->l->t('Each answer keeps one PHP worker busy until it is done. 0 = no limit.'),
					'type' => DeclarativeSettingsTypes::NUMBER,
					'default' => 4,
				],
			],
		];
	}
}
