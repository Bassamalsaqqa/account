<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DisposableMariaDbSchemaTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('APP_ENV=testing');
        putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['PHASE8_ALLOW_DISPOSABLE_DB'] = '1';
        parent::tearDown();
    }

    private function getPdo(): PDO
    {
        return new PDO('mysql:host='.getenv('PHASE8_TEST_DB_HOST').';port='.getenv('PHASE8_TEST_DB_PORT').';charset=utf8mb4', getenv('PHASE8_TEST_DB_USERNAME'), getenv('PHASE8_TEST_DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    public function test_generates_random_independent_safe_names(): void
    {
        $name1 = DisposableMariaDbSchema::generateSafeName();
        $name2 = DisposableMariaDbSchema::generateSafeName();

        $this->assertNotSame($name1, $name2);
        $this->assertMatchesRegularExpression('/^accounting_p8_tmp_[a-f0-9]{12}$/', $name1);
        $this->assertMatchesRegularExpression('/^accounting_p8_tmp_[a-f0-9]{12}$/', $name2);
        $this->assertLessThanOrEqual(64, strlen($name1));
    }

    public function test_creates_and_cleans_up_disposable_schema(): void
    {
        putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
        $_ENV['PHASE8_ALLOW_DISPOSABLE_DB'] = '1';

        $schema = DisposableMariaDbSchema::create();
        $name = $schema->schemaName();

        $this->assertTrue($schema->isOwned());

        $pdo = $this->getPdo();
        $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$name]);
        $this->assertSame($name, $stmt->fetchColumn(), 'Created database must exist in MariaDB.');

        $schema->drop();

        $this->assertFalse($schema->isOwned());
        $stmt->execute([$name]);
        $this->assertFalse($stmt->fetchColumn(), 'Dropped database must no longer exist in MariaDB.');
    }

    public function test_cleanup_on_failure_path(): void
    {
        putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
        $_ENV['PHASE8_ALLOW_DISPOSABLE_DB'] = '1';

        $pdo = $this->getPdo();
        $createdName = null;

        try {
            $schema = DisposableMariaDbSchema::create();
            $createdName = $schema->schemaName();
            throw new \Exception('Simulated test failure');
        } catch (\Exception $e) {
            $this->assertSame('Simulated test failure', $e->getMessage());
            // In finally / destruct, it drops
            $schema->drop();
        }

        $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$createdName]);
        $this->assertFalse($stmt->fetchColumn(), 'Database must be cleaned up on failure path.');
    }

    public function test_refuses_when_app_env_not_testing(): void
    {
        $oldEnv = getenv('APP_ENV');
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV is not testing');

        try {
            DisposableMariaDbSchema::create();
        } finally {
            putenv("APP_ENV={$oldEnv}");
            $_ENV['APP_ENV'] = $oldEnv;
        }
    }

    public function test_refuses_when_opt_in_flag_missing(): void
    {
        putenv('PHASE8_ALLOW_DISPOSABLE_DB=0');
        $_ENV['PHASE8_ALLOW_DISPOSABLE_DB'] = '0';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PHASE8_ALLOW_DISPOSABLE_DB opt-in flag is missing or not 1');

        DisposableMariaDbSchema::create();
    }

    public function test_only_exact_runner_owned_primary_database_has_a_valid_proof(): void
    {
        $schema = DisposableMariaDbSchema::create();
        $oldProof = getenv('PHASE8_DISPOSABLE_DB_PROOF');
        $oldNonce = getenv('PHASE8_DISPOSABLE_DB_NONCE');
        $path = null;
        try {
            $environment = $schema->environment();
            $path = $environment['PHASE8_DISPOSABLE_DB_PROOF'];
            putenv('PHASE8_DISPOSABLE_DB_PROOF='.$path);
            putenv('PHASE8_DISPOSABLE_DB_NONCE='.$environment['PHASE8_DISPOSABLE_DB_NONCE']);
            DisposableMariaDbSchema::assertPrimarySchema($schema->schemaName());
            $this->assertFileExists($path);
            foreach ([DisposableMariaDbSchema::generateSafeName(), 'production'] as $unknown) {
                try {
                    DisposableMariaDbSchema::assertPrimarySchema($unknown);
                    $this->fail('Unowned primary accepted');
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('does not match', $e->getMessage());
                }
            }
            putenv('PHASE8_DISPOSABLE_DB_NONCE=wrong');
            try {
                DisposableMariaDbSchema::assertPrimarySchema($schema->schemaName());
                $this->fail('Wrong ownership token accepted');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('does not match', $e->getMessage());
            }
        } finally {
            putenv($oldProof === false ? 'PHASE8_DISPOSABLE_DB_PROOF' : 'PHASE8_DISPOSABLE_DB_PROOF='.$oldProof);
            putenv($oldNonce === false ? 'PHASE8_DISPOSABLE_DB_NONCE' : 'PHASE8_DISPOSABLE_DB_NONCE='.$oldNonce);
            $schema->drop();
        }
        $this->assertFileDoesNotExist($path);
    }

    public function test_refuses_non_local_admin_connection(): void
    {
        $old = getenv('PHASE8_TEST_DB_HOST');
        putenv('PHASE8_TEST_DB_HOST=production.example.invalid');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('non-local');
            DisposableMariaDbSchema::create();
        } finally {
            putenv('PHASE8_TEST_DB_HOST='.$old);
        }
    }

    public function test_refuses_missing_explicit_admin_configuration(): void
    {
        $old = getenv('PHASE8_TEST_DB_USERNAME');
        putenv('PHASE8_TEST_DB_USERNAME');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Explicit disposable');
            DisposableMariaDbSchema::create();
        } finally {
            putenv('PHASE8_TEST_DB_USERNAME='.$old);
        }
    }

    public function test_refuses_unknown_or_pre_existing_schema_identity(): void
    {
        $schema = new DisposableMariaDbSchema;
        $this->assertFalse($schema->isOwned());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot get schema name of unowned disposable instance.');
        $schema->schemaName();
    }

    public function test_preserves_sentinel_in_independently_owned_test_schema(): void
    {
        putenv('PHASE8_ALLOW_DISPOSABLE_DB=1');
        $_ENV['PHASE8_ALLOW_DISPOSABLE_DB'] = '1';

        $pdo = $this->getPdo();
        $sentinelName = 'accounting_p8_sentinel_'.bin2hex(random_bytes(4));
        $pdo->exec("CREATE DATABASE `{$sentinelName}`");
        $pdo->exec("CREATE TABLE `{$sentinelName}`.sentinel_test (id INT PRIMARY KEY, marker VARCHAR(50))");
        $pdo->exec("INSERT INTO `{$sentinelName}`.sentinel_test VALUES (1, 'MUST_PRESERVE')");

        try {
            // Create a disposable schema, use it and drop it
            $disposable = DisposableMariaDbSchema::create();
            $disposableName = $disposable->schemaName();

            $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
            $stmt->execute([$disposableName]);
            $this->assertSame($disposableName, $stmt->fetchColumn());

            $disposable->drop();

            // Assert sentinel remains intact
            $stmt = $pdo->query("SELECT marker FROM `{$sentinelName}`.sentinel_test WHERE id = 1");
            $this->assertSame('MUST_PRESERVE', $stmt->fetchColumn(), 'Sentinel database and records must remain completely intact.');
        } finally {
            $pdo->exec("DROP DATABASE IF EXISTS `{$sentinelName}`");
        }
    }
}
