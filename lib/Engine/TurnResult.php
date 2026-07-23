<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Engine;

/** The engine-agnostic outcome of one conversational turn. */
class TurnResult {

	public const KIND_OK = 'ok';
	public const KIND_AUTH_ERROR = 'auth_error';
	public const KIND_ERROR = 'error';

	private function __construct(
		public readonly string $kind,
		public readonly string $output,
		public readonly string $detail,
	) {
	}

	public static function ok(string $output): self {
		return new self(self::KIND_OK, $output, '');
	}

	public static function authError(string $detail): self {
		return new self(self::KIND_AUTH_ERROR, '', $detail);
	}

	public static function error(string $detail): self {
		return new self(self::KIND_ERROR, '', $detail);
	}

	public function isOk(): bool {
		return $this->kind === self::KIND_OK;
	}
}
