<?php

declare(strict_types=1);

namespace App\Application\CompanyDataExport\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ExportSnapshotSession
{
    private bool $ownsTransaction = false;

    private ?string $previousStatementTimeout = null;

    private ?string $previousIsolationLevel = null;

    private bool $modifiedSessionTimeout = false;

    private bool $modifiedIsolation = false;

    /** @var \WeakMap<Connection, bool>|null */
    private static ?\WeakMap $hookedConnections = null;

    private static ?float $activeDeadline = null;

    private static ?ExportSnapshotSession $activeInstance = null;

    /**
     * Establish the REPEATABLE READ READ ONLY snapshot and bounded statement timeout.
     * Fails closed if MariaDB statement timeout cannot be set in MariaDB/MySQL environments.
     *
     * @throws RuntimeException
     */
    public function begin(float $timeoutSeconds, ?float $deadline = null): void
    {
        $deadline ??= microtime(true) + $timeoutSeconds;
        if (DB::transactionLevel() === 0) {
            $driver = DB::connection()->getDriverName();

            // 1. Read initial isolation level
            try {
                $level = DB::scalar('SELECT @@session.tx_isolation');
                if ($level === null) {
                    $level = DB::scalar('SELECT @@session.transaction_isolation');
                }
                if (is_string($level)) {
                    $this->previousIsolationLevel = $level;
                }
            } catch (\Throwable) {
                $this->previousIsolationLevel = null;
            }

            // 2. Bound statement timeout (fail-closed if MariaDB timeout cannot be set)
            if ($driver === 'mysql') {
                try {
                    $timeout = DB::scalar('SELECT @@session.max_statement_time');
                    if ($timeout !== null && preg_match('/^\d+(?:\.\d+)?$/D', (string) $timeout)) {
                        $this->previousStatementTimeout = (string) $timeout;
                    } else {
                        throw new RuntimeException('MariaDB max_statement_time variable is not accessible.');
                    }

                    $timeoutInt = max(1, (int) ceil($timeoutSeconds));
                    DB::connection()->getPdo()->exec("SET SESSION max_statement_time = {$timeoutInt}");
                    $this->modifiedSessionTimeout = true;
                } catch (\Throwable $e) {
                    throw new RuntimeException('Failed to set MariaDB max_statement_time: statement timeout is required for export safety.', 0, $e);
                }
            } elseif (! app()->environment('testing')) {
                // Fail closed if not MySQL in production
                throw new RuntimeException('Export requires MariaDB/MySQL connection with statement timeout support.');
            }

            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            DB::beginTransaction();
            $this->ownsTransaction = true;
        } elseif (! app()->environment('testing')) {
            abort(500, 'Cannot export company data inside an active transaction.');
        }

        self::$activeDeadline = $deadline;
        self::$activeInstance = $this;
        $this->ensureBeforeExecutingHook();
    }

    /**
     * Dynamically update the remaining SQL statement execution deadline.
     * Fails closed if MariaDB statement timeout cannot be updated.
     *
     * @throws RuntimeException
     */
    public function applyStatementTimeout(int $remainingSeconds): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $timeoutInt = max(1, $remainingSeconds);
            try {
                // Avoid recursively invoking our own beforeExecuting hook.
                DB::connection()->getPdo()->exec("SET SESSION max_statement_time = {$timeoutInt}");
                $this->modifiedSessionTimeout = true;
            } catch (\Throwable $e) {
                throw new RuntimeException('Failed to update MariaDB max_statement_time remaining deadline.', 0, $e);
            }
        }
    }

    /**
     * Register a single beforeExecuting hook on DB connection to enforce per-SQL deadline
     * without growing the listeners array.
     */
    private function ensureBeforeExecutingHook(): void
    {
        $connection = DB::connection();
        self::$hookedConnections ??= new \WeakMap;
        if (isset(self::$hookedConnections[$connection])) {
            return;
        }

        DB::beforeExecuting(static function (string $query, array $bindings, $connection): void {
            if (self::$activeDeadline === null || self::$activeInstance === null) {
                return;
            }

            $now = microtime(true);
            if ($now >= self::$activeDeadline) {
                throw new RuntimeException(__('company_data_export.execution_timeout'));
            }

            if ($connection->getDriverName() === 'mysql') {
                $remaining = max(1, (int) ceil(self::$activeDeadline - $now));
                self::$activeInstance->applyStatementTimeout($remaining);
            }
        });

        self::$hookedConnections[$connection] = true;
    }

    /**
     * Restore database transaction, statement timeout, and session isolation level cleanly.
     *
     * @throws RuntimeException
     */
    public function restore(): void
    {
        self::$activeDeadline = null;
        self::$activeInstance = null;

        $cleanupFailed = false;

        if ($this->ownsTransaction) {
            try {
                DB::rollBack();
            } catch (\Throwable) {
                $cleanupFailed = true;
                try {
                    DB::purge();
                } catch (\Throwable) {
                }
            } finally {
                $this->ownsTransaction = false;
            }
        }

        if ($this->modifiedSessionTimeout && $this->previousStatementTimeout !== null) {
            try {
                DB::statement('SET SESSION max_statement_time = '.$this->previousStatementTimeout);
            } catch (\Throwable) {
                $cleanupFailed = true;
                try {
                    DB::purge();
                } catch (\Throwable) {
                }
            } finally {
                $this->modifiedSessionTimeout = false;
            }
        }

        if ($this->modifiedIsolation && $this->previousIsolationLevel !== null) {
            try {
                $normalized = str_replace('-', ' ', $this->previousIsolationLevel);
                DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$normalized);
            } catch (\Throwable) {
                $cleanupFailed = true;
                try {
                    DB::purge();
                } catch (\Throwable) {
                }
            } finally {
                $this->modifiedIsolation = false;
            }
        }

        if ($cleanupFailed) {
            throw new RuntimeException('Failed to restore database session settings cleanly after export.');
        }
    }

    public function __destruct()
    {
        try {
            $this->restore();
        } catch (\Throwable) {
        }
    }
}
