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
            'invalid limits type' => array_replace($validControl, ['limits' => 'unlimited']),
            'unknown limit key' => array_replace($validControl, ['limits' => ['unlimited_work' => 1]]),
            'negative max_total_rows' => array_replace($validControl, ['limits' => ['max_total_rows' => -1]]),
            'zero max_total_rows' => array_replace($validControl, ['limits' => ['max_total_rows' => 0]]),
            'excessive max_total_rows' => array_replace($validControl, ['limits' => ['max_total_rows' => 99999999]]),
            'non-integer max_execution_seconds' => array_replace($validControl, ['limits' => ['max_execution_seconds' => '60']]),
            'unknown root key' => array_replace($validControl, ['unauthorized_override' => true]),
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

            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php', $fileA, $fileB, Guard::implementationDigest()], $root);
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

            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php', $fileA, $fileB, Guard::implementationDigest()], $root);
            $proc->run();
            self::assertSame(1, $proc->getExitCode());
            self::assertSame("AUDIT_REFUSED\n", $proc->getErrorOutput());
            // Matching forged report digests still cannot replace the external operator pin.
            file_put_contents($fileB, file_get_contents($fileA));
            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php', $fileA, $fileB, Guard::implementationDigest()], $root);
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

    public function test_compare_tool_classifies_operational_churn_vs_preservation_and_schema(): void
    {
        $root = dirname(__DIR__, 3);
        $dir = sys_get_temp_dir().'/release-audit-cmp-class-'.bin2hex(random_bytes(12));
        mkdir($dir, 0700);
        $fileA = $dir.'/report_a.json';
        $fileB = $dir.'/report_b.json';

        $baseTables = [
            'companies' => [
                'rows' => 1,
                'schema_sha256' => hash('sha256', 'CREATE TABLE companies'),
                'normalized_schema_sha256' => hash('sha256', 'CREATE TABLE companies'),
                'auto_increment' => 2,
                'rows_sha256' => hash('sha256', 'company_1'),
                'classification' => 'preservation',
            ],
            'posting_lines' => [
                'rows' => 10,
                'schema_sha256' => hash('sha256', 'CREATE TABLE posting_lines'),
                'normalized_schema_sha256' => hash('sha256', 'CREATE TABLE posting_lines'),
                'auto_increment' => 11,
                'rows_sha256' => hash('sha256', 'lines_10'),
                'classification' => 'preservation',
            ],
            'sessions' => [
                'rows' => 5,
                'schema_sha256' => hash('sha256', 'CREATE TABLE sessions'),
                'normalized_schema_sha256' => hash('sha256', 'CREATE TABLE sessions'),
                'auto_increment' => null,
                'rows_sha256' => hash('sha256', 'sessions_5'),
                'classification' => 'operational_mutable',
            ],
            'jobs' => [
                'rows' => 0,
                'schema_sha256' => hash('sha256', 'CREATE TABLE jobs AUTO_INCREMENT=1'),
                'normalized_schema_sha256' => hash('sha256', 'CREATE TABLE jobs'),
                'auto_increment' => 1,
                'rows_sha256' => hash('sha256', 'empty'),
                'classification' => 'operational_mutable',
            ],
        ];

        $digest = Guard::implementationDigest();
        $writeReports = function (array $tablesA, array $tablesB) use ($fileA, $fileB, $digest): void {
            file_put_contents($fileA, json_encode([
                'version' => 1,
                'implementation_sha256' => $digest,
                'tables' => $tablesA,
            ], JSON_THROW_ON_ERROR));
            chmod($fileA, 0600);
            file_put_contents($fileB, json_encode([
                'version' => 1,
                'implementation_sha256' => $digest,
                'tables' => $tablesB,
            ], JSON_THROW_ON_ERROR));
            chmod($fileB, 0600);
        };

        try {
            // Case 1: Identical -> exit 0 FINGERPRINTS_EQUAL
            $writeReports($baseTables, $baseTables);
            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php', $fileA, $fileB, $digest], $root);
            $proc->run();
            self::assertSame(0, $proc->getExitCode());
            self::assertSame("FINGERPRINTS_EQUAL\n", $proc->getOutput());

            // Case 2: Operational churn only (sessions changed rows)
            $churnTables = $baseTables;
            $churnTables['sessions']['rows'] = 6;
            $churnTables['sessions']['rows_sha256'] = hash('sha256', 'sessions_6');
            $writeReports($baseTables, $churnTables);

            // Default: exit 3 OPERATIONAL_VOLATILITY_ONLY
            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php', $fileA, $fileB, $digest], $root);
            $proc->run();
            self::assertSame(3, $proc->getExitCode());
            self::assertSame("OPERATIONAL_VOLATILITY_ONLY\n", $proc->getOutput());
            self::assertStringContainsString('sessions', $proc->getErrorOutput());

            // With explicit operator disposition: exit 0 OPERATIONAL_VOLATILITY_ACCEPTED
            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php', $fileA, $fileB, $digest, '--accept-operational-volatility'], $root);
            $proc->run();
            self::assertSame(0, $proc->getExitCode());
            self::assertSame("OPERATIONAL_VOLATILITY_ACCEPTED\n", $proc->getOutput());
            self::assertStringContainsString('sessions', $proc->getErrorOutput());

            // Case 3: Counter volatility on operational table (jobs AUTO_INCREMENT changed, normalized DDL identical)
            $counterTables = $baseTables;
            $counterTables['jobs']['auto_increment'] = 2;
            $counterTables['jobs']['schema_sha256'] = hash('sha256', 'CREATE TABLE jobs AUTO_INCREMENT=2');
            $writeReports($baseTables, $counterTables);

            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php', $fileA, $fileB, $digest], $root);
            $proc->run();
            self::assertSame(3, $proc->getExitCode());
            self::assertSame("OPERATIONAL_VOLATILITY_ONLY\n", $proc->getOutput());

            // Case 4: Preservation table rows mutated (posting_lines changed)
            $mutatedFinancial = $baseTables;
            $mutatedFinancial['posting_lines']['rows'] = 11;
            $mutatedFinancial['posting_lines']['rows_sha256'] = hash('sha256', 'lines_11_corrupted');
            $writeReports($baseTables, $mutatedFinancial);

            // Without disposition: exit 2 FINGERPRINTS_DIFFER
            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php', $fileA, $fileB, $digest], $root);
            $proc->run();
            self::assertSame(2, $proc->getExitCode());
            self::assertSame("FINGERPRINTS_DIFFER\n", $proc->getOutput());
            self::assertStringContainsString('posting_lines', $proc->getErrorOutput());

            // Financial record mutation can NEVER be bypassed by operational disposition flag!
            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php', $fileA, $fileB, $digest, '--accept-operational-volatility'], $root);
            $proc->run();
            self::assertSame(2, $proc->getExitCode(), 'Financial record mutation must not be accepted by operational disposition');
            self::assertSame("FINGERPRINTS_DIFFER\n", $proc->getOutput());

            // Case 5: Substantive DDL change on operational table is a schema violation, not churn!
            $ddlChanged = $baseTables;
            $ddlChanged['sessions']['normalized_schema_sha256'] = hash('sha256', 'CREATE TABLE sessions (modified_col INT)');
            $writeReports($baseTables, $ddlChanged);

            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php', $fileA, $fileB, $digest, '--accept-operational-volatility'], $root);
            $proc->run();
            self::assertSame(2, $proc->getExitCode(), 'Substantive schema change must not be ignored even on operational table');
            self::assertSame("FINGERPRINTS_DIFFER\n", $proc->getOutput());
            self::assertStringContainsString('sessions', $proc->getErrorOutput());

            // Case 6: Counter volatility on preservation table indicates mutation
            $presCounter = $baseTables;
            $presCounter['companies']['auto_increment'] = 3;
            $presCounter['companies']['schema_sha256'] = hash('sha256', 'CREATE TABLE companies AUTO_INCREMENT=3');
            $writeReports($baseTables, $presCounter);

            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php', $fileA, $fileB, $digest, '--accept-operational-volatility'], $root);
            $proc->run();
            self::assertSame(2, $proc->getExitCode(), 'Preservation table counter volatility must refuse as difference');
            self::assertSame("FINGERPRINTS_DIFFER\n", $proc->getOutput());

            // Case 7: Invalid disposition flag refuses
            $proc = new Process([PHP_BINARY, $root.'/tools/release-audit/compare.php', $fileA, $fileB, $digest, '--ignore-all-differences'], $root);
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
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }
}

