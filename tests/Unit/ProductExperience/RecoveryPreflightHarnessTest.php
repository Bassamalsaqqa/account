<?php

declare(strict_types=1);

namespace Tests\Unit\ProductExperience;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RecoveryPreflightHarnessTest extends TestCase
{
    public function test_offline_recovery_inspector_acceptance_and_refusal_harness(): void
    {
        $root = dirname(__DIR__, 3);
        $process = new Process([PHP_BINARY, $root.'/tests/Deployment/recovery-preflight.php'], $root);
        $process->setTimeout(120)->run();
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertMatchesRegularExpression('/Tests NOT RUN:\s*0\b/', $output);
        $this->assertMatchesRegularExpression('/Failures:\s*0\b/', $output);
    }
}
