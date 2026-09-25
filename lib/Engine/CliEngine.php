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
		'claude-opus-5',
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
			// "--prompt=…" in one piece, so a message starting with "-" is never read as an option (review T11).
			$argv = [$binary, '-m', $model, '--prompt=' . $systemPrompt . "\n\n" . $prompt];
			if ($elevated && $tools !== '') {
				// Gemini's tools cannot be listed one by one; it is all or nothing.
				$argv[] = '--yolo';
			} else {
				// Nothing was switched off before: Gemini's own rules let read_file,
				// glob and grep_search run unasked, so an ordinary user could have it
				// read the credentials in its home (review T2). An admin policy that
				// denies every tool outranks those rules.
				$argv[] = '--admin-policy';
				$argv[] = __DIR__ . '/policy/no-tools.toml';
			}
		} else {
			// The prompt goes in on stdin, not as an argument: the whole conversation in
			// one argument ran past the kernel's 128 KiB limit after ten exchanges or so,
			// and every user of the machine could read it in the process list (review T6).
			$argv = [
				$binary,
				'-p',
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
			$this->logger->warning('Talk-Bot: running the command line tool with tools enabled for an administrator', [
				'provider' => $this->provider,
				'tools' => $tools,
			]);
		}

		$run = $this->exec($argv, $this->config->getRequestTimeout(), $elevated && $tools !== '', $this->provider === 'gemini' ? null : $prompt);
		$combined = $run['stdout'] . "\n" . $run['stderr'];

		if ($run['timedOut']) {
			return TurnResult::error('The command line tool did not answer in time.');
		}
		if ($this->looksLikeAuthFailure($combined) && $run['code'] !== 0) {
			return TurnResult::authError(trim(mb_substr($combined, 0, 300)));
		}

		// A command line tool can emit a stray non-UTF-8 byte; drop it here so the
		// answer both stores cleanly and survives json_encode on its way to Talk.
		$output = trim($run['stdout']);
		if ($output !== '' && preg_match('//u', $output) !== 1) {
			$output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');
		}
		if ($output !== '') {
			return TurnResult::ok($output);
		}
		$stderr = trim($run['stderr']);
		return TurnResult::error($stderr !== '' ? mb_substr($stderr, 0, 500) : 'The command line tool returned nothing.');
	}

	public function listModels(): array {
		return $this->provider === 'gemini' ? self::GEMINI_MODELS : self::CLAUDE_MODELS;
	}

	/**
	 * Check that the configured binary exists and runs. 'reason' tells the admin page
	 * which sentence to show, in the admin's language ('no_path', 'exit_code' with 'code');
	 * 'detail' is otherwise the tool's own --version output.
	 */
	public function checkBinary(): array {
		$binary = $this->config->getCliPath($this->provider);
		if ($binary === '') {
			return ['ok' => false, 'detail' => '', 'reason' => 'no_path'];
		}
		$run = $this->exec([$binary, '--version'], 30);
		$output = trim($run['stdout'] . ' ' . $run['stderr']);
		return $output === ''
			? ['ok' => $run['code'] === 0, 'detail' => '', 'reason' => 'exit_code', 'code' => $run['code']]
			: ['ok' => $run['code'] === 0, 'detail' => mb_substr($output, 0, 200)];
	}

	/** @param list<array{role: string, text: string}> $history */
	private function buildPrompt(array $history, string $message): string {
		if ($history === []) {
			return $message;
		}
		// Gemini still takes the prompt as an argument: the oldest exchanges go first
		// until it fits well inside the kernel's limit (review T6).
		if ($this->provider === 'gemini') {
			while ($history !== [] && strlen(implode("\n", array_column($history, 'text'))) + strlen($message) > 100000) {
				array_shift($history);
			}
			if ($history === []) {
				return $message;
			}
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
	private function exec(array $argv, int $timeout, bool $inHome = false, ?string $stdin = null): array {
		$env = ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'];
		$home = $this->config->getCliHome();
		if ($home !== '') {
			$env['HOME'] = $home;
		}
		// An administrator's run with tools works in the tool's own home, so its project
		// settings and notes stay in one place. Anybody else's run works in an empty
		// folder made for that message: the home holds the tool's login, and the
		// folder a tool works in is the folder it can read (review T2).
		$cwd = ($inHome && is_dir($home)) ? $home : ($this->tempManager->getTemporaryFolder() ?: sys_get_temp_dir());

		// In a session of its own, so a timeout can end the tool and everything it started (review T13).
		$setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid' : (is_executable('/bin/setsid') ? '/bin/setsid' : '');
		if ($setsid !== '' && function_exists('posix_kill')) {
			array_unshift($argv, $setsid);
		} else {
			$setsid = '';
		}

		$descriptors = [0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = @proc_open($argv, $descriptors, $pipes, $cwd, $env);
		if (!is_resource($process)) {
			$this->logger->error('Talk-Bot: could not start ' . $argv[0]);
			return ['code' => -1, 'stdout' => '', 'stderr' => 'Could not start ' . $argv[0], 'timedOut' => false];
		}
		if ($stdin !== null) {
			fwrite($pipes[0], $stdin);
			fclose($pipes[0]);
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$deadline = time() + $timeout;
		$timedOut = false;
		// Before PHP 8.3, proc_close() answers -1 once proc_get_status() has seen the
		// process end: the exit code is the one seen here (review T8).
		$exit = null;

		while (true) {
			$stdout .= (string)stream_get_contents($pipes[1]);
			$stderr .= (string)stream_get_contents($pipes[2]);

			$status = proc_get_status($process);
			if (!$status['running']) {
				$exit = (int)$status['exitcode'];
				break;
			}
			if (time() >= $deadline) {
				$timedOut = true;
				if ($setsid !== '') {
					posix_kill(-(int)$status['pid'], 9);
				}
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
		if ($exit !== null && $exit >= 0) {
			$code = $exit;
		}

		return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => $timedOut];
	}
}
