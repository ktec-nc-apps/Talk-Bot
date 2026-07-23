<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Engine;

/**
 * A pluggable AI backend.
 *
 * Implementations talk to one provider and know nothing about Talk: they get the
 * history, the new message and a system prompt, and return the answer.
 */
interface IEngine {

	/** Short identifier used in logs and in the connection test, e.g. "claude-api". */
	public function getName(): string;

	/**
	 * @param list<array{role: string, text: string}> $history Oldest first, excluding $message.
	 */
	public function run(array $history, string $message, string $systemPrompt): TurnResult;

	/** List the model ids this engine can currently use. Empty when unavailable. */
	public function listModels(): array;
}
