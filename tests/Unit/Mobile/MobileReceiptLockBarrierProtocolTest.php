<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Unit tests verifying barrier protocol enforcement, isolation safeguards,
 * and input validation of the mobile identity race worker.
 *
 * Runs without a live database.
 */
final class MobileReceiptLockBarrierProtocolTest extends TestCase
{
    public function test_worker_rejects_missing_arguments(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/tests/Support/mobile-identity-worker.php']);
        $process->run();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('Unowned mobile receipt race directory', $process->getErrorOutput());
    }

    public function test_worker_rejects_invalid_slot_name(): void
    {
        $dir = sys_get_temp_dir().'/mobile_receipt_race_test_'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);

        try {
            $process = new Process([
                PHP_BINARY,
                dirname(__DIR__, 3).'/tests/Support/mobile-identity-worker.php',
                $dir,
                'worker-c', // Only worker-a and worker-b are allowed
            ]);
            $process->run();

            $this->assertNotSame(0, $process->getExitCode());
            $this->assertStringContainsString('Unowned mobile receipt race directory', $process->getErrorOutput());
        } finally {
            rmdir($dir);
        }
    }

    public function test_worker_rejects_non_race_directory_prefix(): void
    {
        $dir = sys_get_temp_dir().'/untrusted_test_dir_'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);

        try {
            $process = new Process([
                PHP_BINARY,
                dirname(__DIR__, 3).'/tests/Support/mobile-identity-worker.php',
                $dir,
                'worker-a',
            ]);
            $process->run();

            $this->assertNotSame(0, $process->getExitCode());
            $this->assertStringContainsString('Unowned mobile receipt race directory', $process->getErrorOutput());
        } finally {
            rmdir($dir);
        }
    }

    public function test_worker_rejects_directory_outside_sys_temp_dir(): void
    {
        $dir = '/tmp/../tmp/mobile_receipt_race_'.bin2hex(random_bytes(4));
        mkdir($dir, 0700);

        try {
            // Using a path that points outside canonical sys_get_temp_dir
            $process = new Process([
                PHP_BINARY,
                dirname(__DIR__, 3).'/tests/Support/mobile-identity-worker.php',
                dirname(__DIR__, 3).'/storage/mobile_receipt_race_fake',
                'worker-a',
            ]);
            $process->run();

            $this->assertNotSame(0, $process->getExitCode());
            $this->assertStringContainsString('Unowned mobile receipt race directory', $process->getErrorOutput());
        } finally {
            rmdir($dir);
        }
    }
}
