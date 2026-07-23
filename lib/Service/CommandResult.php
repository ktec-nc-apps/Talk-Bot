<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Service;

/**
 * What a command wants to happen.
 *
 * Most commands answer instantly with their own text ("reply"). A few — retry,
 * summary, joke — instead want the model to answer a prompt they built
 * ("prompt"), so they hand that back and let the normal engine flow run it.
 */
final class CommandResult {

	private function __construct(
		public readonly ?string $reply,
		public readonly ?string $prompt,
		public readonly bool $persist,
	) {
	}

	/** Post this text straight away; the model is not involved. */
	public static function reply(string $text): self {
		return new self($text, null, false);
	}

	/**
	 * Send this prompt to the model as if the user had written it.
	 *
	 * @param bool $persist Whether the resulting exchange is kept in the history.
	 *                      A retry keeps it; a one-off like a summary does not.
	 */
	public static function prompt(string $text, bool $persist): self {
		return new self(null, $text, $persist);
	}

	public function isReply(): bool {
		return $this->reply !== null;
	}
}
