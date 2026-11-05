<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreSign contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BehatFailureContractTest extends TestCase {
	public static function invalidScenarios(): iterable {
		yield 'wrong HTTP status fails the step' => ['Incorrect HTTP response status is rejected', 'Expected HTTP status 418, got 200'];
		yield 'different JSON list order fails the step' => ['Incorrect JSON array order is rejected', 'Expected JSON: [2,1]'];
		yield 'missing step argument is undefined' => ['A missing required step argument is rejected', 'undefined'];
	}

	#[DataProvider('invalidScenarios')]
	public function testInvalidBehatScenarioFails(string $scenario, string $diagnostic): void {
		$root = dirname(__DIR__);
		$process = new Process([
			PHP_BINARY,
			'vendor/bin/behat',
			'--config=tests/fixtures/behat.php',
			'--name=' . $scenario,
			'--format=progress',
			'--no-colors',
			'--no-snippets',
			'--strict',
			'--no-interaction',
		], $root);
		$process->run();
		$output = $process->getOutput() . $process->getErrorOutput();

		self::assertSame(1, $process->getExitCode(), $output);
		self::assertStringContainsString($diagnostic, $output);
	}
}
