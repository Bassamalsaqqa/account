<?php

declare(strict_types=1);

namespace Tests\Unit\ProductExperience;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DisposableQaTimeoutTest extends TestCase
{
    /** @return iterable<string, array{string|false, bool}> */
    public static function timeoutCases(): iterable
    {
        foreach (['', '0', '-1', '1.5', '1e3', ' 900', '01', '14401', '99999999999999999999'] as $value) {
            yield 'invalid:'.var_export($value, true) => [$value, false];
        }
        foreach ([false, '1', '900', '14400'] as $value) {
            yield 'valid:'.var_export($value, true) => [$value, true];
        }
    }

    #[DataProvider('timeoutCases')]
    public function test_timeout_is_checked_before_any_database_setup(string|false $value, bool $valid): void
    {
        $root = dirname(__DIR__, 3);
        // APP_ENV deliberately refuses all DB work after a valid timeout parses.
        $process = new Process([PHP_BINARY, 'tests/Support/run-phase8-disposable.php', '--', 'tests'], $root, [
            'PHASE8_TEST_TIMEOUT' => $value,
            'APP_ENV' => 'production',
            'PHASE8_ALLOW_DISPOSABLE_DB' => '0',
        ]);
        $process->setTimeout(15)->run();

        self::assertSame(1, $process->getExitCode());
        self::assertSame('', $process->getOutput());
        self::assertStringContainsString($valid
            ? 'APP_ENV is not testing.'
            : 'PHASE8_TEST_TIMEOUT must be a strictly positive integer up to 14400 seconds.', $process->getErrorOutput());
        self::assertStringNotContainsString('Owned primary test schema:', $process->getOutput());
    }
}
