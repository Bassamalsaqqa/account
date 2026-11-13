<?php

declare(strict_types=1);

namespace Tests\Unit\ReleaseAudit;

use PHPUnit\Framework\TestCase;
use ReleaseAudit\Fingerprint;
use ReleaseAudit\Guard;
use RuntimeException;
use Symfony\Component\Process\Process;

final class RefusalTest extends TestCase
{
    public function test_unsafe_cli_invocations_never_autoload_or_connect(): void
    {
        $root = dirname(__DIR__, 3);
        $cases = [
            [],
            ['--production'],
            ['--disposable-test', '/missing', str_repeat('a', 64), str_repeat('b', 40), '/tmp/audit-'.str_repeat('a', 24).'.json'],
            ['--disposable-test', '/tmp/../missing', 'invalid', 'invalid', '/tmp/output'],
            ['--disposable-test', '/tmp/control.json', 'invalid-pin', str_repeat('b', 40), '/tmp/audit-'.str_repeat('a', 24).'.json'],
            ['--disposable-test', '/tmp/control.json', str_repeat('a', 64), 'invalid-code', '/tmp/audit-'.str_repeat('a', 24).'.json'],
            ['--disposable-test', '/tmp/control.json', str_repeat('a', 64), str_repeat('b', 40), '/tmp/not-canonical.json'],
        ];

        foreach ($cases as $args) {
            $process = new Process([PHP_BINARY, $root.'/tools/release-audit/audit.php', ...$args], $root, [
                'DB_HOST' => 'invalid.invalid',
                'DB_PASSWORD' => 'SECRET_SENTINEL',
            ]);
            $process->run();
            self::assertSame(1, $process->getExitCode());
            self::assertSame('', $process->getOutput());
            self::assertSame("AUDIT_REFUSED\n", $process->getErrorOutput());
        }
    }

    public function test_protected_paths_pins_and_source_refuse_before_vendor_loading(): void
    {
        require_once dirname(__DIR__, 3).'/tools/release-audit/Guard.php';
        $dir = sys_get_temp_dir().'/release-audit-'.bin2hex(random_bytes(12));
        mkdir($dir, 0700);
        $control = $dir.'/control.json';
        file_put_contents($control, '{}');
        chmod($control, 0600);
        $out = $dir.'/audit-'.str_repeat('a', 24).'.json';
        $pin = hash_file('sha256', $control);
        $base = ['audit.php', '--disposable-test', $control, $pin, str_repeat('0', 40), $out];

        try {
            $cases = [];
            $cases[] = array_replace($base, [2 => $dir.'/../control.json']);
            $cases[] = array_replace($base, [3 => str_repeat('0', 64)]); // pin mismatch
            $cases[] = $base; // wrong source pin
            $cases[] = array_replace($base, [5 => $dir.'/public/output.json']);
            $cases[] = array_replace($base, [5 => $control]); // existing file output
            symlink($control, $dir.'/link');
            $cases[] = array_replace($base, [2 => $dir.'/link']);

            foreach ($cases as $args) {
                try {
                    Guard::preflight($args, dirname(__DIR__, 3));
                    self::fail('Unsafe invocation accepted');
                } catch (RuntimeException $e) {
                    self::assertSame('AUDIT_REFUSED', $e->getMessage());
                    self::assertFileDoesNotExist($out);
                }
            }

            chmod($control, 0644);
            try {
                Guard::path($control, true);
                self::fail('Public control file accepted');
            } catch (RuntimeException $e) {
                self::assertSame('AUDIT_REFUSED', $e->getMessage());
            }

            chmod($dir, 0755);
            try {
                Guard::path($dir, false);
                self::fail('Public control directory accepted');
            } catch (RuntimeException $e) {
                self::assertSame('AUDIT_REFUSED', $e->getMessage());
            }
        } finally {
            if (is_link($dir.'/link')) {
                unlink($dir.'/link');
            }
            if (is_file($control)) {
                unlink($control);
            }
            if (is_dir($dir)) {
                chmod($dir, 0700);
                rmdir($dir);
            }
        }
    }

    public function test_control_file_schema_and_types_enforce_disposable_test_mode_only(): void
    {
        require_once dirname(__DIR__, 3).'/tools/release-audit/Guard.php';
        $root = dirname(__DIR__, 3);
        $head = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
        if (! preg_match('/^[a-f0-9]{40}$/D', $head)) {
            self::markTestSkipped('Cannot determine git HEAD');
        }

        $dir = sys_get_temp_dir().'/release-audit-'.bin2hex(random_bytes(12));
        mkdir($dir, 0700);

        $validControl = [
            'mode' => 'disposable-test',
            'host' => '127.0.0.1',
            'port' => 3342,
            'user' => 'test_runner',
            'password' => '',
            'schema' => 'accounting_p8_tmp_1234567890ab',
            'server_hostname' => 'test-host',
            'server_id' => 1,
        ];

        $invalidPayloads = [
            'wrong mode' => array_replace($validControl, ['mode' => 'production']),
            'wrong host' => array_replace($validControl, ['host' => '192.168.1.1']),
            'wrong port' => array_replace($validControl, ['port' => 3306]),
            'wrong user' => array_replace($validControl, ['user' => 'root']),
            'non-empty password' => array_replace($validControl, ['password' => 'secret']),
            'non-disposable schema' => array_replace($validControl, ['schema' => 'production_db']),
            'schema sql injection' => array_replace($validControl, ['schema' => 'accounting_p8_tmp_1234567890ab; DROP DATABASE accounting;']),
            'empty server hostname' => array_replace($validControl, ['server_hostname' => '']),
            'negative server id' => array_replace($validControl, ['server_id' => -1]),
            'string server id' => array_replace($validControl, ['server_id' => '1']),
        ];

        try {
            foreach ($invalidPayloads as $name => $payload) {
                $file = $dir.'/control_'.$name.'.json';
                file_put_contents($file, json_encode($payload, JSON_THROW_ON_ERROR));
                chmod($file, 0600);
                $pin = hash_file('sha256', $file);
                $out = $dir.'/audit-'.bin2hex(random_bytes(12)).'.json';
                $args = ['audit.php', '--disposable-test', $file, $pin, $head, $out];

                try {
                    Guard::preflight($args, $root);
                    self::fail("Invalid control case '{$name}' was unexpectedly accepted");
                } catch (RuntimeException $e) {
                    self::assertSame('AUDIT_REFUSED', $e->getMessage());
                } finally {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
        } finally {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    public function test_binary_null_and_decimal_encoding_has_no_ambiguous_boundaries(): void
    {
        require_once dirname(__DIR__, 3).'/tools/release-audit/Guard.php';
        require_once dirname(__DIR__, 3).'/tools/release-audit/Fingerprint.php';
        $values = [null, '', "\0", '0', '1.000000000000001', '1.000000000000002', "\xff"];
        $frames = array_map(Fingerprint::frame(...), $values);
        self::assertCount(count($values), array_unique($frames));
        self::assertNotSame($frames[4], $frames[5]);
        self::assertSame('N;', Fingerprint::frame(null));
        self::assertSame('S0:', Fingerprint::frame(''));
        self::assertSame('S1:0', Fingerprint::frame('0'));
        self::assertSame("S1:\0", Fingerprint::frame("\0"));
    }

    public function test_compare_tool_refuses_invalid_arguments_and_tampered_reports(): void
    {
        $root = dirname(__DIR__, 3);
        $dir = sys_get_temp_dir().'/release-audit-cmp-'.bin2hex(random_bytes(12));
        mkdir($dir, 0700);
        $fileA = $dir.'/report_a.json';
        $fileB = $dir.'/report_b.json';

        try {
            // Wrong number of arguments
            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php'], $root);
            $proc->run();
            self::assertSame(1, $proc->getExitCode());
            self::assertSame("AUDIT_REFUSED\n", $proc->getErrorOutput());

            // Invalid permissions
            file_put_contents($fileA, json_encode(['version' => 1], JSON_THROW_ON_ERROR));
            file_put_contents($fileB, json_encode(['version' => 1], JSON_THROW_ON_ERROR));
            chmod($fileA, 0644);
            chmod($fileB, 0600);

            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php', $fileA, $fileB], $root);
            $proc->run();
            self::assertSame(1, $proc->getExitCode());
            self::assertSame("AUDIT_REFUSED\n", $proc->getErrorOutput());

            // Mismatched implementation SHA
            chmod($fileA, 0600);
            file_put_contents($fileA, json_encode([
                'version' => 1,
                'implementation_sha256' => str_repeat('a', 64),
                'tables' => [],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($fileB, json_encode([
                'version' => 1,
                'implementation_sha256' => str_repeat('b', 64),
                'tables' => [],
            ], JSON_THROW_ON_ERROR));

            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php', $fileA, $fileB], $root);
            $proc->run();
            self::assertSame(1, $proc->getExitCode());
            self::assertSame("AUDIT_REFUSED\n", $proc->getErrorOutput());
        } finally {
            if (is_file($fileA)) {
                unlink($fileA);
            }
            if (is_file($fileB)) {
                unlink($fileB);
            }
            rmdir($dir);
        }
    }
}
