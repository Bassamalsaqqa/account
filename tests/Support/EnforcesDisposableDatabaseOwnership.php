<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Enforces explicit disposable database ownership for non-transactional test lifecycles.
 *
 * Guarantees that tests without RefreshDatabase outer transactions only execute against
 * an explicitly owned disposable MariaDB database on 127.0.0.1:3342 matching
 * accounting_p8_tmp_* with valid cryptographic proof nonce, and isolate committed
 * fixtures into a dedicated owned schema without mutating the batch primary schema.
 */
trait EnforcesDisposableDatabaseOwnership
{
    protected bool $disposableDatabaseOwnershipAdmitted = false;

    protected ?string $admittedDatabaseName = null;

    protected ?DisposableMariaDbSchema $isolatedFixtureSchema = null;

    /** @var array<string, int> */
    protected array $primaryTableCounts = [];

    /** @var list<string> */
    protected array $primaryTableNames = [];

    /** @var list<string> */
    protected array $primaryMigrations = [];

    /**
     * Explicitly assert that the active database connection is an owned, disposable MariaDB
     * database on 127.0.0.1:3342 matching accounting_p8_tmp_* with valid ownership proof.
     *
     * Fails closed with zero database queries on any invalid proof, configuration, or environment.
     */
    protected function assertOwnedDisposableDatabase(): void
    {
        $this->disposableDatabaseOwnershipAdmitted = false;
        $this->admittedDatabaseName = null;

        // 1. Framework and environment check
        $booted = function_exists('app') && app()->bound('env');
        if (($booted && ! app()->environment('testing')) || getenv('APP_ENV') !== 'testing') {
            throw new RuntimeException('Disposable database ownership refused: APP_ENV is not testing.');
        }

        // 2. Opt-in flag check
        if (getenv('PHASE8_ALLOW_DISPOSABLE_DB') !== '1') {
            throw new RuntimeException('Disposable database ownership refused: PHASE8_ALLOW_DISPOSABLE_DB opt-in flag is missing or not 1.');
        }

        // 3. Default connection check
        $defaultConnection = (string) config('database.default');
        if ($defaultConnection !== 'mysql') {
            throw new RuntimeException("Disposable database ownership refused: default database connection must be 'mysql', got '{$defaultConnection}'.");
        }

        // 4. Connection configuration check
        $mysqlConfig = config('database.connections.mysql');
        if (! is_array($mysqlConfig)) {
            throw new RuntimeException('Disposable database ownership refused: database.connections.mysql configuration is missing.');
        }

        if (! empty($mysqlConfig['unix_socket'])) {
            throw new RuntimeException('Disposable database ownership refused: unix_socket connection is not allowed for disposable database.');
        }

        if (! empty($mysqlConfig['url'])) {
            throw new RuntimeException('Disposable database ownership refused: database URL configuration is forbidden; explicit host and port required.');
        }

        if (isset($mysqlConfig['read']) || isset($mysqlConfig['write'])) {
            throw new RuntimeException('Disposable database ownership refused: read/write separation configuration is forbidden for disposable database.');
        }

        // Prohibit persistent 3353 and any non-3342 port in config
        $configuredPort = (int) ($mysqlConfig['port'] ?? 0);
        if ($configuredPort === 3353) {
            throw new RuntimeException('Forbidden: cannot run on persistent 3353 database.');
        }
        if ($configuredPort !== 3342) {
            throw new RuntimeException('Disposable database ownership refused: port must be 3342, got '.$configuredPort.'.');
        }

        $configuredHost = (string) ($mysqlConfig['host'] ?? '');
        if ($configuredHost !== '127.0.0.1') {
            throw new RuntimeException('Disposable database ownership refused: connection host must be 127.0.0.1, got '.$configuredHost.'.');
        }

        $configuredDatabase = (string) ($mysqlConfig['database'] ?? '');
        if (! preg_match('/^accounting_p8_tmp_[a-f0-9]{12}$/D', $configuredDatabase)) {
            throw new RuntimeException('Disposable database ownership refused: database name does not match disposable schema pattern: '.$configuredDatabase);
        }

        // 5. Administrative settings check
        DisposableMariaDbSchema::testSettings();

        // 6. DB connection instance check
        $connection = DB::connection();
        if ($connection->getName() !== 'mysql') {
            throw new RuntimeException("Disposable database ownership refused: active connection name must be 'mysql', got '{$connection->getName()}'.");
        }
        $dbHost = (string) $connection->getConfig('host');
        $dbPort = (int) ($connection->getConfig('port') ?? 0);
        $dbName = (string) $connection->getDatabaseName();

        if ($dbPort === 3353) {
            throw new RuntimeException('Forbidden: connection port points to persistent 3353 database.');
        }
        if ($dbPort !== 3342) {
            throw new RuntimeException('Disposable database ownership refused: connection port must be 3342, got '.$dbPort.'.');
        }
        if ($dbHost !== '127.0.0.1') {
            throw new RuntimeException('Disposable database ownership refused: connection host must be 127.0.0.1, got '.$dbHost.'.');
        }
        if ($dbName !== $configuredDatabase) {
            throw new RuntimeException("Disposable database ownership refused: connection database name '{$dbName}' does not match configured '{$configuredDatabase}'.");
        }

        // 7. Cryptographic ownership proof assertion (proof file, host, port, schema, nonce)
        // Never treat schema prefix or environment alone as ownership.
        DisposableMariaDbSchema::assertPrimarySchema($dbName);

        // 8. Safely inspect actual selected schema / PDO identity via read-only check.
        // Runs ONLY AFTER static proof is valid.
        $actualDb = $this->resolveActualSelectedDatabase($connection);
        if ($actualDb !== $dbName) {
            throw new RuntimeException("Disposable database ownership refused: actual selected schema '{$actualDb}' does not match expected owned schema '{$dbName}'.");
        }

        $this->disposableDatabaseOwnershipAdmitted = true;
        $this->admittedDatabaseName = $dbName;
    }

    /**
     * Revalidate ownership during tearDown before any database interaction.
     * Ensures configuration, connection, and proof have not drifted mid-test.
     */
    protected function revalidateOwnedDisposableDatabase(): void
    {
        $priorAdmitted = $this->admittedDatabaseName;
        $this->disposableDatabaseOwnershipAdmitted = false;
        $this->admittedDatabaseName = null;

        $this->assertOwnedDisposableDatabase();

        $newlyAdmitted = $this->admittedDatabaseName;
        if ($priorAdmitted !== null && $newlyAdmitted !== $priorAdmitted) {
            $this->disposableDatabaseOwnershipAdmitted = false;
            $this->admittedDatabaseName = null;
            throw new RuntimeException("Disposable database ownership refused: database changed mid-test from '{$priorAdmitted}' to '{$newlyAdmitted}'.");
        }
    }

    /**
     * Check whether ownership has been admitted.
     */
    protected function isDisposableDatabaseOwnershipAdmitted(): bool
    {
        return $this->disposableDatabaseOwnershipAdmitted;
    }

    /**
     * Read actual selected database identity from the connection via read-only SELECT.
     */
    protected function resolveActualSelectedDatabase(ConnectionInterface $connection): string
    {
        $result = $connection->selectOne('SELECT DATABASE() AS current_db');
        if ($result === null) {
            throw new RuntimeException('Disposable database ownership refused: unable to determine actual selected database (SELECT DATABASE() returned NULL).');
        }

        $actual = is_object($result) ? ($result->current_db ?? null) : ($result['current_db'] ?? null);
        if (! is_string($actual) || $actual === '') {
            throw new RuntimeException('Disposable database ownership refused: invalid database identity returned from SELECT DATABASE().');
        }

        return $actual;
    }

    /**
     * Assert primary disposable schema ownership and snapshot baseline tables, migrations,
     * and row counts so tearDown can prove zero committed state leaked onto primary.
     */
    protected function capturePrimaryDisposableBaseline(): string
    {
        $this->assertOwnedDisposableDatabase();
        $primary = (string) $this->admittedDatabaseName;
        $connection = DB::connection();

        $tables = array_map(
            static fn (object $row): string => (string) array_values((array) $row)[0],
            $connection->select('SHOW TABLES')
        );
        sort($tables, SORT_STRING);

        $this->primaryTableNames = $tables;
        $this->primaryMigrations = in_array('migrations', $tables, true)
            ? $connection->table('migrations')->orderBy('migration')->pluck('migration')->map('strval')->all()
            : [];

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $connection->table($table)->count();
        }
        $this->primaryTableCounts = $counts;

        return $primary;
    }

    /**
     * Create, migrate, and switch to an isolated per-test disposable schema so non-transactional
     * DDL and multi-process commits never mutate the shared batch primary schema.
     */
    protected function enterIsolatedDisposableSchema(): DisposableMariaDbSchema
    {
        $primary = $this->capturePrimaryDisposableBaseline();
        $schema = DisposableMariaDbSchema::createFromSource($primary);
        $schema->switchLaravelConnection();
        $this->isolatedFixtureSchema = $schema;

        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Isolated disposable schema started with non-zero transaction level.');
        }

        return $schema;
    }

    protected function requireIsolatedDisposableSchema(): DisposableMariaDbSchema
    {
        if ($this->isolatedFixtureSchema === null || ! $this->isolatedFixtureSchema->isOwned()) {
            throw new RuntimeException('Isolated disposable schema is not active or not owned.');
        }

        return $this->isolatedFixtureSchema;
    }

    /**
     * Clean up the isolated per-test disposable schema, restore and revalidate primary ownership,
     * and verify zero tables, migrations, or committed rows leaked onto the batch primary schema.
     */
    protected function exitAndVerifyIsolatedDisposableSchema(?callable $beforeRestore = null): void
    {
        if (! $this->isDisposableDatabaseOwnershipAdmitted()) {
            $this->isolatedFixtureSchema = null;
            $this->primaryTableCounts = [];
            $this->primaryTableNames = [];
            $this->primaryMigrations = [];

            return;
        }

        try {
            if ($beforeRestore !== null) {
                $beforeRestore();
            }

            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }

            app(CompanyContext::class)->clear();
            auth()->forgetUser();
            setPermissionsTeamId(null);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            if ($this->isolatedFixtureSchema !== null) {
                $this->isolatedFixtureSchema->restoreLaravelConnection();
                $this->isolatedFixtureSchema->drop();
                $this->isolatedFixtureSchema = null;
            }

            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }

            $this->revalidateOwnedDisposableDatabase();
            $this->assertSame(0, DB::transactionLevel(), 'Primary disposable schema must have transaction level 0 after tearDown.');

            $connection = DB::connection();
            $actualTables = array_map(
                static fn (object $row): string => (string) array_values((array) $row)[0],
                $connection->select('SHOW TABLES')
            );
            sort($actualTables, SORT_STRING);
            $this->assertSame($this->primaryTableNames, $actualTables, 'Primary disposable schema tables drifted during isolated test execution.');

            if (in_array('migrations', $actualTables, true)) {
                $actualMigrations = $connection->table('migrations')->orderBy('migration')->pluck('migration')->map('strval')->all();
                $this->assertSame($this->primaryMigrations, $actualMigrations, 'Primary disposable schema migrations drifted during isolated test execution.');
            }

            foreach ($this->primaryTableCounts as $table => $expectedCount) {
                $this->assertSame(
                    $expectedCount,
                    (int) $connection->table($table)->count(),
                    "Committed qualification fixtures must not leak into primary test schema table [{$table}]."
                );
            }
        } finally {
            $this->isolatedFixtureSchema = null;
            $this->primaryTableCounts = [];
            $this->primaryTableNames = [];
            $this->primaryMigrations = [];
            $this->disposableDatabaseOwnershipAdmitted = false;
            $this->admittedDatabaseName = null;
            auth()->forgetUser();
        }
    }
}
