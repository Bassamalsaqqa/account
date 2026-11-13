<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile;

use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Tests\Feature\Mobile\MobileEventReceiptIdentityTest;
use Tests\Feature\Mobile\MobileReceiptRollbackAtomicityTest;
use Tests\TestCase;

/**
 * Proof of zero-mutation refusal guard for Mobile receipt test lifecycles.
 *
 * Exercises the ACTUAL setUp() and tearDown() lifecycle methods of both affected PR94 test classes:
 * - Tests\Feature\Mobile\MobileEventReceiptIdentityTest
 * - Tests\Feature\Mobile\MobileReceiptRollbackAtomicityTest
 *
 * Asserts refusal occurs BEFORE any database mutation, connection effects, or DML,
 * and verifies that tearDown performs zero database mutations when setup fails closed.
 */
final class MobileReceiptTestOwnershipGuardTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    /** @var array<string, mixed> */
    private array $savedConfig = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedEnv = [
            'APP_ENV' => getenv('APP_ENV'),
            'PHASE8_ALLOW_DISPOSABLE_DB' => getenv('PHASE8_ALLOW_DISPOSABLE_DB'),
            'PHASE8_TEST_DB_HOST' => getenv('PHASE8_TEST_DB_HOST'),
            'PHASE8_TEST_DB_PORT' => getenv('PHASE8_TEST_DB_PORT'),
            'PHASE8_TEST_DB_USERNAME' => getenv('PHASE8_TEST_DB_USERNAME'),
            'PHASE8_TEST_DB_PASSWORD' => getenv('PHASE8_TEST_DB_PASSWORD'),
            'PHASE8_TEST_DB_EMPTY_PASSWORD' => getenv('PHASE8_TEST_DB_EMPTY_PASSWORD'),
            'PHASE8_DISPOSABLE_DB_PROOF' => getenv('PHASE8_DISPOSABLE_DB_PROOF'),
            'PHASE8_DISPOSABLE_DB_NONCE' => getenv('PHASE8_DISPOSABLE_DB_NONCE'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
            'DB_HOST' => getenv('DB_HOST'),
            'DB_PORT' => getenv('DB_PORT'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_URL' => getenv('DB_URL'),
            'DB_SOCKET' => getenv('DB_SOCKET'),
        ];

        $this->savedConfig = [
            'database.default' => config('database.default'),
            'database.connections.mysql' => config('database.connections.mysql'),
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $val) {
            if ($val === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }

        if ($this->app !== null) {
            foreach ($this->savedConfig as $key => $val) {
                config([$key => $val]);
            }
        }

        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function affectedClassesProvider(): array
    {
        return [
            'MobileEventReceiptIdentityTest' => [MobileEventReceiptIdentityTest::class],
            'MobileReceiptRollbackAtomicityTest' => [MobileReceiptRollbackAtomicityTest::class],
        ];
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_when_app_env_is_not_testing(string $testClass): void
    {
        $this->setupConfigAndEnv([
            'env' => ['APP_ENV' => 'production'],
        ]);

        $this->assertRefusalWithZeroMutation($testClass, 'APP_ENV is not testing');
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_when_phase8_opt_in_missing_or_not_one(string $testClass): void
    {
        $this->setupConfigAndEnv([
            'env' => ['PHASE8_ALLOW_DISPOSABLE_DB' => '0'],
        ]);

        $this->assertRefusalWithZeroMutation($testClass, 'PHASE8_ALLOW_DISPOSABLE_DB opt-in flag is missing or not 1');
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_when_port_is_persistent_preview_3353(string $testClass): void
    {
        $schema = 'accounting_p8_tmp_111122223333';
        $proof = $this->createProofFile($schema, '127.0.0.1', '3353', 'valid_nonce_123');

        $this->setupConfigAndEnv([
            'env' => [
                'DB_PORT' => '3353',
                'PHASE8_TEST_DB_PORT' => '3353',
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => 'valid_nonce_123',
            ],
        ]);

        $this->assertRefusalWithZeroMutation($testClass, 'Forbidden: cannot run on persistent 3353 database');
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_when_port_is_default_3306_instead_of_3342(string $testClass): void
    {
        $schema = 'accounting_p8_tmp_111122223333';
        $proof = $this->createProofFile($schema, '127.0.0.1', '3306', 'valid_nonce_123');

        $this->setupConfigAndEnv([
            'env' => [
                'DB_PORT' => '3306',
                'PHASE8_TEST_DB_PORT' => '3306',
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => 'valid_nonce_123',
            ],
        ]);

        $this->assertRefusalWithZeroMutation($testClass, 'port must be 3342, got 3306');
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_when_host_is_not_loopback(string $testClass): void
    {
        $schema = 'accounting_p8_tmp_111122223333';
        $proof = $this->createProofFile($schema, '192.168.1.50', '3342', 'valid_nonce_123');

        $this->setupConfigAndEnv([
            'env' => [
                'DB_HOST' => '192.168.1.50',
                'PHASE8_TEST_DB_HOST' => '192.168.1.50',
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => 'valid_nonce_123',
            ],
        ]);

        $this->assertRefusalWithZeroMutation($testClass, 'connection host must be 127.0.0.1, got 192.168.1.50');
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_when_schema_is_non_owned_persistent_format(string $testClass): void
    {
        $schema = 'accounting_production_real';
        $proof = $this->createProofFile($schema, '127.0.0.1', '3342', 'valid_nonce_123');

        $this->setupConfigAndEnv([
            'env' => [
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => 'valid_nonce_123',
            ],
        ]);

        $this->assertRefusalWithZeroMutation($testClass, 'database name does not match disposable schema pattern');
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_when_proof_is_missing(string $testClass): void
    {
        $schema = 'accounting_p8_tmp_111122223333';

        $this->setupConfigAndEnv([
            'env' => [
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => '/tmp/nonexistent_proof_'.bin2hex(random_bytes(8)).'.json',
                'PHASE8_DISPOSABLE_DB_NONCE' => 'valid_nonce_123',
            ],
        ]);

        $this->assertRefusalWithZeroMutation($testClass, 'Disposable schema ownership proof is required');
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_when_proof_nonce_is_stale_or_mismatched(string $testClass): void
    {
        $schema = 'accounting_p8_tmp_111122223333';
        $proof = $this->createProofFile($schema, '127.0.0.1', '3342', 'fresh_nonce_1111');

        $this->setupConfigAndEnv([
            'env' => [
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => 'stale_nonce_0000',
            ],
        ]);

        $this->assertRefusalWithZeroMutation($testClass, 'Disposable schema ownership proof does not match this database');
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_when_proof_is_foreign_to_active_database(string $testClass): void
    {
        $activeSchema = 'accounting_p8_tmp_111122223333';
        $foreignSchema = 'accounting_p8_tmp_999988887777';
        $proof = $this->createProofFile($foreignSchema, '127.0.0.1', '3342', 'valid_nonce_123');

        $this->setupConfigAndEnv([
            'env' => [
                'DB_DATABASE' => $activeSchema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => 'valid_nonce_123',
            ],
        ]);

        $this->assertRefusalWithZeroMutation($testClass, 'Disposable schema ownership proof does not match this database');
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_when_default_connection_is_not_mysql(string $testClass): void
    {
        $schema = 'accounting_p8_tmp_111122223333';
        $proof = $this->createProofFile($schema, '127.0.0.1', '3342', 'valid_nonce_123');

        $this->setupConfigAndEnv([
            'env' => [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => 'valid_nonce_123',
            ],
        ]);

        $this->assertRefusalWithZeroMutation($testClass, "default database connection must be 'mysql'");
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_when_connection_config_uses_forbidden_unix_socket(string $testClass): void
    {
        $schema = 'accounting_p8_tmp_111122223333';
        $proof = $this->createProofFile($schema, '127.0.0.1', '3342', 'valid_nonce_123');

        $this->setupConfigAndEnv([
            'env' => [
                'DB_SOCKET' => '/var/run/mysqld/mysqld.sock',
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => 'valid_nonce_123',
            ],
        ]);

        $this->assertRefusalWithZeroMutation($testClass, 'unix_socket connection is not allowed');
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_when_connection_config_uses_forbidden_url(string $testClass): void
    {
        $schema = 'accounting_p8_tmp_111122223333';
        $proof = $this->createProofFile($schema, '127.0.0.1', '3342', 'valid_nonce_123');

        $this->setupConfigAndEnv([
            'env' => [
                'DB_URL' => 'mysql://test_runner@127.0.0.1:3342/'.$schema,
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => 'valid_nonce_123',
            ],
        ]);

        $this->assertRefusalWithZeroMutation($testClass, 'database URL configuration is forbidden');
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_when_connection_config_uses_forbidden_read_write_separation(string $testClass): void
    {
        $schema = 'accounting_p8_tmp_111122223333';
        $proof = $this->createProofFile($schema, '127.0.0.1', '3342', 'valid_nonce_123');

        $this->setupConfigAndEnv([
            'env' => [
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => 'valid_nonce_123',
            ],
        ]);

        $this->assertRefusalWithZeroMutation(
            $testClass,
            'read/write separation configuration is forbidden',
            configOverrides: ['database.connections.mysql.read' => ['host' => '127.0.0.1']]
        );
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_when_actual_selected_database_mismatches_expected_schema(string $testClass): void
    {
        $schema = 'accounting_p8_tmp_111122223333';
        $nonce = bin2hex(random_bytes(16));
        $proof = $this->createProofFile($schema, '127.0.0.1', '3342', $nonce);

        $this->setupConfigAndEnv([
            'env' => [
                'DB_HOST' => '127.0.0.1',
                'PHASE8_TEST_DB_HOST' => '127.0.0.1',
                'DB_PORT' => '3342',
                'PHASE8_TEST_DB_PORT' => '3342',
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => $nonce,
            ],
        ]);

        // Fake/spy PDO returning a foreign/wrong database identity on SELECT DATABASE()
        $mockPdo = $this->createMockPdoWithSelectedDatabase('accounting_p8_tmp_wrongdb999');

        $this->assertRefusalWithZeroMutation(
            $testClass,
            "actual selected schema 'accounting_p8_tmp_wrongdb999' does not match expected owned schema '{$schema}'",
            mockPdo: $mockPdo,
            allowReadOnlyQuery: true
        );
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_refuses_teardown_when_config_or_proof_switches_midtest(string $testClass): void
    {
        $schema = 'accounting_p8_tmp_333344445555';
        $nonce = bin2hex(random_bytes(16));
        $proof = $this->createProofFile($schema, '127.0.0.1', '3342', $nonce);

        $this->setupConfigAndEnv([
            'env' => [
                'DB_HOST' => '127.0.0.1',
                'PHASE8_TEST_DB_HOST' => '127.0.0.1',
                'DB_PORT' => '3342',
                'PHASE8_TEST_DB_PORT' => '3342',
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => $nonce,
            ],
        ]);

        $mockPdo = $this->createMockPdoWithSelectedDatabase($schema);

        $instance = new $testClass('test_dummy');
        $instance->afterApplicationCreated(function () use ($mockPdo) {
            DB::connection()->setPdo($mockPdo);
            DB::connection()->setReadPdo($mockPdo);
        });

        $reflector = new ReflectionClass($instance);

        // Boot test environment on instance
        $setupEnv = $reflector->getMethod('setUpTheTestEnvironment');
        $setupEnv->setAccessible(true);
        $setupEnv->invoke($instance);

        // Admit initial setup
        $guardMethod = $reflector->getMethod('assertOwnedDisposableDatabase');
        $guardMethod->setAccessible(true);
        $guardMethod->invoke($instance);

        $isAdmittedMethod = $reflector->getMethod('isDisposableDatabaseOwnershipAdmitted');
        $isAdmittedMethod->setAccessible(true);
        $this->assertTrue($isAdmittedMethod->invoke($instance));

        // Now simulate mid-test tampering/drift: switch config port to 3353
        config(['database.connections.mysql.port' => 3353]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $thrown = null;
        try {
            $tearDownMethod = $reflector->getMethod('tearDown');
            $tearDownMethod->setAccessible(true);
            $tearDownMethod->invoke($instance);
        } catch (\Throwable $e) {
            $thrown = $e instanceof \ReflectionException ? $e->getPrevious() : $e;
        }

        $this->assertNotNull($thrown, 'Expected tearDown to throw when config switched midtest.');
        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertStringContainsString('Forbidden: cannot run on persistent 3353 database', $thrown->getMessage());

        $destructiveQueries = array_filter($queries, function (string $sql): bool {
            return str_contains(strtoupper($sql), 'DELETE')
                || str_contains(strtoupper($sql), 'DROP')
                || str_contains(strtoupper($sql), 'UPDATE')
                || str_contains(strtoupper($sql), 'INSERT')
                || str_contains(strtoupper($sql), 'FOREIGN_KEY_CHECKS');
        });

        $this->assertEmpty($destructiveQueries, 'Teardown after mid-test drift must produce ZERO destructive queries.');

        $this->refreshApplication();
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_schema_switch_diagnostic_preserves_both_names_and_refuses_cleanup(string $testClass): void
    {
        $schema = 'accounting_p8_tmp_333344445555';
        $nonce = bin2hex(random_bytes(16));
        $proof = $this->createProofFile($schema, '127.0.0.1', '3342', $nonce);

        $this->setupConfigAndEnv([
            'env' => [
                'DB_HOST' => '127.0.0.1',
                'PHASE8_TEST_DB_HOST' => '127.0.0.1',
                'DB_PORT' => '3342',
                'PHASE8_TEST_DB_PORT' => '3342',
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => $nonce,
            ],
        ]);

        $mockPdo = $this->createMockPdoWithSelectedDatabase($schema);

        $instance = new $testClass('test_dummy');
        $instance->afterApplicationCreated(function () use ($mockPdo) {
            DB::connection()->setPdo($mockPdo);
            DB::connection()->setReadPdo($mockPdo);
        });

        $reflector = new ReflectionClass($instance);

        // Boot test environment on instance
        $setupEnv = $reflector->getMethod('setUpTheTestEnvironment');
        $setupEnv->setAccessible(true);
        $setupEnv->invoke($instance);

        // Admit initial setup
        $guardMethod = $reflector->getMethod('assertOwnedDisposableDatabase');
        $guardMethod->setAccessible(true);
        $guardMethod->invoke($instance);

        $isAdmittedMethod = $reflector->getMethod('isDisposableDatabaseOwnershipAdmitted');
        $isAdmittedMethod->setAccessible(true);
        $this->assertTrue($isAdmittedMethod->invoke($instance));

        $newSchema = 'accounting_p8_tmp_666677778888';
        $newNonce = bin2hex(random_bytes(16));
        $newProof = $this->createProofFile($newSchema, '127.0.0.1', '3342', $newNonce);
        config(['database.connections.mysql.database' => $newSchema]);
        putenv('PHASE8_DISPOSABLE_DB_PROOF='.$newProof);
        putenv('PHASE8_DISPOSABLE_DB_NONCE='.$newNonce);
        DB::connection()->setDatabaseName($newSchema);
        $newPdo = $this->createMockPdoWithSelectedDatabase($newSchema);
        DB::connection()->setPdo($newPdo);
        DB::connection()->setReadPdo($newPdo);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $thrown = null;
        try {
            $tearDownMethod = $reflector->getMethod('tearDown');
            $tearDownMethod->setAccessible(true);
            $tearDownMethod->invoke($instance);
        } catch (\Throwable $e) {
            $thrown = $e instanceof \ReflectionException ? $e->getPrevious() : $e;
        }

        $this->assertNotNull($thrown, 'Expected tearDown to throw when config switched midtest.');
        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame("Disposable database ownership refused: database changed mid-test from '{$schema}' to '{$newSchema}'.", $thrown->getMessage());
        $this->assertFalse($isAdmittedMethod->invoke($instance));
        $this->assertNull($reflector->getProperty('admittedDatabaseName')->getValue($instance));

        $destructiveQueries = array_filter($queries, function (string $sql): bool {
            return str_contains(strtoupper($sql), 'DELETE')
                || str_contains(strtoupper($sql), 'DROP')
                || str_contains(strtoupper($sql), 'UPDATE')
                || str_contains(strtoupper($sql), 'INSERT')
                || str_contains(strtoupper($sql), 'FOREIGN_KEY_CHECKS');
        });

        $this->assertEmpty($destructiveQueries, 'Teardown after mid-test drift must produce ZERO destructive queries.');

        $this->refreshApplication();
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_partial_setup_failure_teardown_executes_safely_without_destructive_cleanup(string $testClass): void
    {
        $schema = 'accounting_p8_tmp_999988887777';
        $nonce = bin2hex(random_bytes(16));
        $proof = $this->createProofFile($schema, '127.0.0.1', '3342', $nonce);

        $this->setupConfigAndEnv([
            'env' => [
                'PHASE8_TEST_DB_HOST' => '127.0.0.1',
                'PHASE8_TEST_DB_PORT' => '3342',
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => $nonce,
            ],
        ]);

        $instance = new $testClass('test_dummy');

        // Spy on DB queries to ensure teardown without fixtures produces ZERO destructive queries
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        // Invoke teardown without having completed fixture setup
        $reflector = new ReflectionClass($instance);
        $tearDownMethod = $reflector->getMethod('tearDown');
        $tearDownMethod->setAccessible(true);
        $tearDownMethod->invoke($instance);

        $destructiveQueries = array_filter($queries, function (string $sql): bool {
            return str_contains(strtoupper($sql), 'DELETE')
                || str_contains(strtoupper($sql), 'DROP')
                || str_contains(strtoupper($sql), 'FOREIGN_KEY_CHECKS');
        });

        $this->assertEmpty($destructiveQueries, 'Teardown on unadmitted or partial setup must not execute destructive queries.');

        $this->refreshApplication();
    }

    #[DataProvider('affectedClassesProvider')]
    public function test_admits_ownership_when_all_proof_invariants_satisfied(string $testClass): void
    {
        $schema = 'accounting_p8_tmp_aabbccddee11';
        $nonce = bin2hex(random_bytes(16));
        $proof = $this->createProofFile($schema, '127.0.0.1', '3342', $nonce);

        $this->setupConfigAndEnv([
            'env' => [
                'DB_HOST' => '127.0.0.1',
                'PHASE8_TEST_DB_HOST' => '127.0.0.1',
                'DB_PORT' => '3342',
                'PHASE8_TEST_DB_PORT' => '3342',
                'DB_DATABASE' => $schema,
                'PHASE8_DISPOSABLE_DB_PROOF' => $proof,
                'PHASE8_DISPOSABLE_DB_NONCE' => $nonce,
            ],
        ]);

        $mockPdo = $this->createMockPdoWithSelectedDatabase($schema);

        $instance = new $testClass('test_dummy');
        $instance->afterApplicationCreated(function () use ($mockPdo) {
            DB::connection()->setPdo($mockPdo);
            DB::connection()->setReadPdo($mockPdo);
        });

        $reflector = new ReflectionClass($instance);
        $setupEnv = $reflector->getMethod('setUpTheTestEnvironment');
        $setupEnv->setAccessible(true);
        $setupEnv->invoke($instance);

        $guardMethod = $reflector->getMethod('assertOwnedDisposableDatabase');
        $guardMethod->setAccessible(true);
        $guardMethod->invoke($instance);

        $isAdmittedMethod = $reflector->getMethod('isDisposableDatabaseOwnershipAdmitted');
        $isAdmittedMethod->setAccessible(true);
        $this->assertTrue(
            $isAdmittedMethod->invoke($instance),
            "Expected {$testClass} ownership admission flag to be true when all invariants match."
        );

        $this->refreshApplication();
    }

    /**
     * @param  class-string  $testClass
     * @param  array<string, mixed>  $configOverrides
     */
    private function assertRefusalWithZeroMutation(
        string $testClass,
        string $expectedMessageSubstring,
        ?PDO $mockPdo = null,
        array $configOverrides = [],
        bool $allowReadOnlyQuery = false
    ): void {
        $queries = [];
        $instance = new $testClass('test_dummy');

        $instance->afterApplicationCreated(function () use (&$queries, $mockPdo, $configOverrides) {
            DB::listen(function ($query) use (&$queries) {
                $queries[] = $query->sql;
            });

            if ($mockPdo !== null) {
                DB::connection()->setPdo($mockPdo);
                DB::connection()->setReadPdo($mockPdo);
            }

            if ($configOverrides !== []) {
                config($configOverrides);
            }
        });

        $reflector = new ReflectionClass($instance);

        $thrown = null;
        try {
            $setUpMethod = $reflector->getMethod('setUp');
            $setUpMethod->setAccessible(true);
            $setUpMethod->invoke($instance);
        } catch (\Throwable $e) {
            $thrown = $e instanceof \ReflectionException ? $e->getPrevious() : $e;
            while ($thrown instanceof \ReflectionException && $thrown->getPrevious() !== null) {
                $thrown = $thrown->getPrevious();
            }
        }

        $this->assertNotNull($thrown, "Expected {$testClass}::setUp() to fail closed with exception.");
        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertStringContainsString($expectedMessageSubstring, $thrown->getMessage());

        // Check ownership flag was not admitted
        $isAdmittedMethod = $reflector->getMethod('isDisposableDatabaseOwnershipAdmitted');
        $isAdmittedMethod->setAccessible(true);
        $this->assertFalse(
            $isAdmittedMethod->invoke($instance),
            "Expected {$testClass} ownership admission flag to be false after refusal."
        );

        // Exercise tearDown on the refused test instance
        $tearDownMethod = $reflector->getMethod('tearDown');
        $tearDownMethod->setAccessible(true);
        $tearDownMethod->invoke($instance);

        $this->refreshApplication();

        // Destructive queries must NEVER occur under any circumstances
        $destructiveQueries = array_filter($queries, function (string $sql): bool {
            return str_contains(strtoupper($sql), 'DELETE')
                || str_contains(strtoupper($sql), 'DROP')
                || str_contains(strtoupper($sql), 'UPDATE')
                || str_contains(strtoupper($sql), 'INSERT')
                || str_contains(strtoupper($sql), 'FOREIGN_KEY_CHECKS');
        });

        $this->assertEmpty(
            $destructiveQueries,
            "Expected ZERO destructive database queries on refused {$testClass} lifecycle, but recorded: ".json_encode($destructiveQueries)
        );

        if (! $allowReadOnlyQuery) {
            // For static proof refusals, assert absolutely ZERO queries were issued
            $this->assertEmpty(
                $queries,
                "Expected ZERO total database queries on static refusal for {$testClass}, but recorded: ".json_encode($queries)
            );
        }
    }

    /**
     * @param  array{config?: array<string, mixed>, env?: array<string, string>}  $settings
     */
    private function setupConfigAndEnv(array $settings): void
    {
        $env = array_merge([
            'APP_ENV' => 'testing',
            'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
            'PHASE8_TEST_DB_HOST' => '127.0.0.1',
            'PHASE8_TEST_DB_PORT' => '3342',
            'PHASE8_TEST_DB_USERNAME' => 'test_runner',
            'PHASE8_TEST_DB_EMPTY_PASSWORD' => '1',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3342',
            'DB_DATABASE' => 'accounting_p8_tmp_111122223333',
            'DB_URL' => '',
            'DB_SOCKET' => '',
        ], $settings['env'] ?? []);

        foreach ($env as $key => $val) {
            putenv("{$key}={$val}");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }

        config([
            'database.default' => $env['DB_CONNECTION'],
            'database.connections.mysql.host' => $env['DB_HOST'],
            'database.connections.mysql.port' => (int) $env['DB_PORT'],
            'database.connections.mysql.username' => 'test_runner',
            'database.connections.mysql.password' => '',
            'database.connections.mysql.database' => $env['DB_DATABASE'],
        ]);

        if (isset($settings['config'])) {
            config($settings['config']);
        }
    }

    private function createMockPdoWithSelectedDatabase(string $databaseName): PDO
    {
        $sqlite = new PDO('sqlite::memory:');
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sqlite->exec('CREATE TABLE identity (current_db TEXT)');
        $stmt = $sqlite->prepare('INSERT INTO identity (current_db) VALUES (?)');
        $stmt->execute([$databaseName]);

        return new class($sqlite) extends PDO
        {
            public function __construct(private PDO $sqlite) {}

            /**
             * @param  string  $query
             * @param  array<int|string, mixed>  $options
             */
            public function prepare($query, $options = []): \PDOStatement|false
            {
                return $this->sqlite->prepare('SELECT current_db FROM identity LIMIT 1', $options);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                return $this->sqlite->query('SELECT current_db FROM identity LIMIT 1');
            }
        };
    }

    private function createProofFile(string $schema, string $host, string $port, string $nonce): string
    {
        $path = tempnam(sys_get_temp_dir(), 'proof_test_');
        if ($path === false) {
            throw new RuntimeException('Unable to create test proof file.');
        }
        $this->tempFiles[] = $path;
        chmod($path, 0600);
        file_put_contents($path, json_encode([
            'schema' => $schema,
            'host' => $host,
            'port' => $port,
            'nonce' => $nonce,
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
