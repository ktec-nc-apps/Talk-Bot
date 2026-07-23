<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Settings;

use OCA\TalkBot\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * The model picker and connection test.
 *
 * Declarative settings cannot carry buttons, so this small classic panel sits
 * under the form in the same section.
 */
class AdminTools implements ISettings {

	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'tools');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	/** Rendered after the declarative form, which uses priority 10. */
	public function getPriority(): int {
		return 50;
	}
}
