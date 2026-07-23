<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TalkBot\Engine;

use OCA\TalkBot\Service\ConfigService;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Drives a Claude or Gemini command line tool installed on the server, for
 * admins who pay for a subscription instead of API tokens.
 *
 * Off by default and never reachable by end users directly: the prompt is passed
 * as a single argv element (no shell is involved) and the tools of the CLI are
 * switched off, so a chat message cannot turn into a command on the server.
 */
class CliEngine implements IEngine {

	private const CLAUDE_MODELS = [
		'claude-opus-4-8',
		'claude-opus-4-7',
		'claude-sonnet-5',
		'claude-sonnet-4-6',
		'claude-haiku-4-5',
	];

	private const GEMINI_MODELS = [
		'gemini-2.5-pro',
		'gemini-2.5-flash',
		'gemini-2.0-flash',
	];

	public function __construct(
		private ConfigService $config,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
		private string $provider,
	) {
	}

	public function getName(): string {
		return $this->provider . '-cli';
	}

	public function run(array $history, string $message, string $systemPrompt, bool $elevated = false): TurnResult {
		$binary = $this->config->getCliPath($this->provider);
		if ($binary === '') {
			return TurnResult::error('No command line tool is configured.');
		}

		$prompt = $this->buildPrompt($history, $message);
		$model = $this->config->getModel($this->provider);
		$tools = $elevated ? $this->config->getAdminTools() : $this->config->getUserTools();

		if ($this->provider === 'gemini') {
			$argv = [$binary, '-m', $model, '-p', $systemPrompt . "\n\n" . $prompt];
			if ($elevated && $tools !== '') {
				// Gemini's tools cannot be listed one by one; it is all or nothing.
				$argv[] = '--yolo';
			}
		} else {
			$argv = [
				$binary,
				'-p', $prompt,
				'--model', $model,
				'--output-format', 'text',
				// An empty list really does disable every tool: the model then has
				// no way to touch the server, whatever the message asks for.
				'--tools', $tools,
				'--append-system-prompt', $systemPrompt,
			];
			if ($elevated && $tools !== '') {
				// Tools cannot be approved interactively from a chat message, so
				// running them at all requires this.
				$argv[] = '--dangerously-skip-permissions';
			}
		}

		if ($elevated && $tools !== '') {
			$this->logger->warning('Talk Bot: running the command line tool with tools enabled for an administrator', [
				'provider' => $this->provider,
				'tools' => $tools,
			]);
		}

		$run = $this->exec($argv, $this->config->getRequestTimeout());
		$combined = $run['stdout'] . "\n" . $run['stderr'];

		if ($run['timedOut']) {
			return TurnResult::error('The command line tool did not answer in time.');
		}
		if ($this->looksLikeAuthFailure($combined) && $run['code'] !== 0) {
			return TurnResult::authError(trim(mb_substr($combined, 0, 300)));
		}

		$output = trim($run['stdout']);
		if ($output !== '') {
			return TurnResult::ok($output);
		}
		$stderr = trim($run['stderr']);
		return TurnResult::error($stderr !== '' ? mb_substr($stderr, 0, 500) : 'The command line tool returned nothing.');
	}

	public function listModels(): array {
		return $this->provider === 'gemini' ? self::GEMINI_MODELS : self::CLAUDE_MODELS;
	}

	/** Check that the configured binary exists and runs. */
	public function checkBinary(): array {
		$binary = $this->config->getCliPath($this->provider);
		if ($binary === '') {
			return ['ok' => false, 'detail' => 'No path configured.'];
		}
		$run = $this->exec([$binary, '--version'], 30);
		$output = trim($run['stdout'] . ' ' . $run['stderr']);
		return [
			'ok' => $run['code'] === 0,
			'detail' => $output === '' ? ('exit code ' . $run['code']) : mb_substr($output, 0, 200),
		];
	}

	/** @param list<array{role: string, text: string}> $history */
	private function buildPrompt(array $history, string $message): string {
		if ($history === []) {
			return $message;
		}
		$lines = ['Conversation so far:'];
		foreach ($history as $turn) {
			$who = $turn['role'] === 'assistant' ? 'Assistant' : 'User';
			$lines[] = $who . ': ' . $turn['text'];
		}
		$lines[] = '';
		$lines[] = 'User: ' . $message;
		return implode("\n", $lines);
	}

	private function looksLikeAuthFailure(string $text): bool {
		return (bool)preg_match(
			'/invalid authentication|authentication_error|please run\s*\/?login|oauth token|api key|not authenticated|unauthorized/i',
			$text,
		);
	}

	/**
	 * Run a command without a shell and with a hard timeout.
	 *
	 * @param list<string> $argv
	 * @return array{code: int, stdout: string, stderr: string, timedOut: bool}
	 */
	private function exec(array $argv, int $timeout): array {
		$env = ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'];
		$home = $this->config->getCliHome();
		if ($home !== '') {
			$env['HOME'] = $home;
		}
		// Run in the tool's own home when there is one, so its project settings and
		// any notes it keeps stay in the same place from one message to the next.
		$cwd = is_dir($home) ? $home : ($this->tempManager->getTemporaryFolder() ?: sys_get_temp_dir());

		$descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = @proc_open($argv, $descriptors, $pipes, $cwd, $env);
		if (!is_resource($process)) {
			$this->logger->error('Talk Bot: could not start ' . $argv[0]);
			return ['code' => -1, 'stdout' => '', 'stderr' => 'Could not start ' . $argv[0], 'timedOut' => false];
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$deadline = time() + $timeout;
		$timedOut = false;

		while (true) {
			$stdout .= (string)stream_get_contents($pipes[1]);
			$stderr .= (string)stream_get_contents($pipes[2]);

			$status = proc_get_status($process);
			if (!$status['running']) {
				break;
			}
			if (time() >= $deadline) {
				$timedOut = true;
				proc_terminate($process, 9);
				break;
			}
			usleep(100000);
		}

		$stdout .= (string)stream_get_contents($pipes[1]);
		$stderr .= (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($process);

		return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => $timedOut];
	}
}
