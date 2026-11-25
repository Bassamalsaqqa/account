<?php

declare(strict_types=1);

namespace Tests\Unit\Operations;

use App\Services\Operations\ReconciliationProcessRunner;
use PHPUnit\Framework\TestCase;

class ReconciliationProcessRunnerTest extends TestCase
{
    private ReconciliationProcessRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new ReconciliationProcessRunner;
    }

    public function test_runner_executes_successful_command_with_zero_exit_code(): void
    {
        $result = $this->runner->executeCommand([
            PHP_BINARY,
            '-r',
            'echo "Domain check OK\n"; exit(0);',
        ], 5.0);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['timedOut']);
        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('Domain check OK', $result['output']);
        $this->assertNull($result['errorMessage']);
    }

    public function test_runner_detects_exit_code_failure(): void
    {
        $result = $this->runner->executeCommand([
            PHP_BINARY,
            '-r',
            'echo "  [x] Balance mismatch detected\n"; exit(1);',
        ], 5.0);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['timedOut']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Balance mismatch detected', $result['output']);
    }

    public function test_runner_times_out_and_terminates_blocking_process_on_unix(): void
    {
        $start = microtime(true);

        // Run a command that would sleep for 5s, with a 0.2s timeout
        $result = $this->runner->executeCommand([
            PHP_BINARY,
            '-r',
            'sleep(5);',
        ], 0.2);

        $elapsed = microtime(true) - $start;

        $this->assertFalse($result['success']);
        $this->assertTrue($result['timedOut']);
        $this->assertNull($result['exitCode']);
        $this->assertStringContainsString('timed out', (string) $result['errorMessage']);
        // Verify it was actually terminated in ~0.2s to 1.5s, not waiting the full 5 seconds
        $this->assertLessThan(2.0, $elapsed);
    }
}
