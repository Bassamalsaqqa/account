<?php

declare(strict_types=1);

namespace ReleaseAudit;

use PDO;
use PDOStatement;

final class Budget
{
    private float $startTime;

    private int $maxExecutionSeconds;

    private int $maxTotalRows;

    private int $maxTotalBytes;

    private int $maxMemoryBytes;

    private int $maxCellBytes;

    private int $totalRows = 0;

    private int $totalBytes = 0;

    private int $peakMemoryBytes = 0;

    /**
     * @param  array<string, int>  $limits
     */
    public function __construct(array $limits = [])
    {
        $this->startTime = microtime(true);
        $this->maxExecutionSeconds = $limits['max_execution_seconds'] ?? 60;
        $this->maxTotalRows = $limits['max_total_rows'] ?? 500000;
        $this->maxTotalBytes = $limits['max_total_bytes'] ?? 104857600;
        $this->maxMemoryBytes = $limits['max_memory_bytes'] ?? 67108864;
        $this->maxCellBytes = min(16777216, max(65536, intdiv($this->maxMemoryBytes, 4)));
        $this->peakMemoryBytes = memory_get_peak_usage(true);
        $this->assertWithinLimits();
    }

    public function recordRow(int $bytes = 0): void
    {
        $this->totalRows++;
        $this->totalBytes += $bytes;
        $this->assertWithinLimits();
    }

    public function recordBytes(int $bytes): void
    {
        $this->totalBytes += $bytes;
        $this->assertWithinLimits();
    }

    public function checkCellBytes(int $bytes): void
    {
        if ($bytes > $this->maxCellBytes || ($this->totalBytes + $bytes) > $this->maxTotalBytes) {
            Guard::refuse();
        }
    }

    public function assertWithinLimits(): void
    {
        $now = microtime(true);
        $currentMemory = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        if ($peak > $this->peakMemoryBytes) {
            $this->peakMemoryBytes = $peak;
        }

        if (($now - $this->startTime) > $this->maxExecutionSeconds) {
            Guard::refuse();
        }
        if ($currentMemory > $this->maxMemoryBytes || $peak > $this->maxMemoryBytes) {
            Guard::refuse();
        }
        if ($this->totalRows > $this->maxTotalRows) {
            Guard::refuse();
        }
        if ($this->totalBytes > $this->maxTotalBytes) {
            Guard::refuse();
        }
    }

    public function remainingExecutionSeconds(): int
    {
        $elapsed = microtime(true) - $this->startTime;
        $remaining = (int) floor($this->maxExecutionSeconds - $elapsed);

        return max(1, $remaining);
    }

    public function maxTotalRows(): int
    {
        return $this->maxTotalRows;
    }

    public function maxTotalBytes(): int
    {
        return $this->maxTotalBytes;
    }

    public function maxMemoryBytes(): int
    {
        return $this->maxMemoryBytes;
    }

    public function maxCellBytes(): int
    {
        return $this->maxCellBytes;
    }

    public function maxExecutionSeconds(): int
    {
        return $this->maxExecutionSeconds;
    }

    public function totalRows(): int
    {
        return $this->totalRows;
    }

    public function totalBytes(): int
    {
        return $this->totalBytes;
    }

    /**
     * @return array{total_rows: int, total_bytes: int, duration_seconds: float, peak_memory_bytes: int}
     */
    public function metrics(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'total_bytes' => $this->totalBytes,
            'duration_seconds' => round(microtime(true) - $this->startTime, 4),
            'peak_memory_bytes' => $this->peakMemoryBytes,
        ];
    }
}

final class Fingerprint
{
    /** @var list<string> */
    public const array OPERATIONAL_MUTABLE_TABLES = [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
    ];

    /** @var array{total_rows: int, total_bytes: int, duration_seconds: float, peak_memory_bytes: int}|null */
    private static ?array $lastMetrics = null;

    public static function frame(?string $value): string
    {
        return $value === null ? 'N;' : 'S'.strlen($value).':'.$value;
    }

    public static function configureStatementTimeout(PDO $pdo, int $timeoutSeconds): void
    {
        $timeoutSeconds = max(1, $timeoutSeconds);

        // Check MariaDB max_statement_time
        try {
            $stmt = $pdo->query('SELECT @@session.max_statement_time');
            if ($stmt !== false && $stmt->fetchColumn() !== false) {
                $pdo->exec("SET SESSION max_statement_time = {$timeoutSeconds}");

                return;
            }
        } catch (\Throwable) {
            // Fall through
        }

        // Check MySQL max_execution_time (milliseconds)
        try {
            $stmt = $pdo->query('SELECT @@session.max_execution_time');
            if ($stmt !== false && $stmt->fetchColumn() !== false) {
                $timeoutMs = $timeoutSeconds * 1000;
                $pdo->exec("SET SESSION max_execution_time = {$timeoutMs}");

                return;
            }
        } catch (\Throwable) {
            // Fall through
        }

        // Neither statement timeout variable is supported; refuse unsupported workload.
        Guard::refuse();
    }

    /**
     * @return array{normalized_schema_sha256: string, auto_increment: int|string|null}
     */
    public static function normalizeDdl(string $ddl): array
    {
        $lastParen = strrpos($ddl, ')');
        if ($lastParen === false) {
            Guard::refuse();
        }

        $tableDef = substr($ddl, 0, $lastParen + 1);
        $tableOptions = substr($ddl, $lastParen + 1);

        $len = strlen($tableOptions);
        $inQuote = false;
        $quoteChar = '';
        $cleanOptions = '';
        $counter = null;
        $i = 0;

        while ($i < $len) {
            $ch = $tableOptions[$i];
            if ($inQuote) {
                $cleanOptions .= $ch;
                if ($ch === $quoteChar) {
                    if ($i + 1 < $len && $tableOptions[$i + 1] === $quoteChar) {
                        $cleanOptions .= $quoteChar;
                        $i += 2;

                        continue;
                    }
                    if ($i > 0 && $tableOptions[$i - 1] === '\\') {
                        $i++;

                        continue;
                    }
                    $inQuote = false;
                }
                $i++;

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inQuote = true;
                $quoteChar = $ch;
                $cleanOptions .= $ch;
                $i++;

                continue;
            }

            // Outside quotes: match \s*AUTO_INCREMENT=(\d+)
            if (preg_match('/^(\s*AUTO_INCREMENT=(\d+))/i', substr($tableOptions, $i), $matches)) {
                $fullMatch = $matches[1];
                $digits = $matches[2];
                $parsedInt = filter_var($digits, FILTER_VALIDATE_INT);
                $counter = $parsedInt !== false ? $parsedInt : $digits;
                $i += strlen($fullMatch);

                continue;
            }

            $cleanOptions .= $ch;
            $i++;
        }

        $normalizedDdl = $tableDef.$cleanOptions;

        return [
            'normalized_schema_sha256' => hash('sha256', $normalizedDdl),
            'auto_increment' => $counter,
        ];
    }

    /**
     * @param  array<string, int>|Budget  $limits
     * @return array<string, array{rows: int, schema_sha256: string, normalized_schema_sha256: string, auto_increment: int|string|null, rows_sha256: string, classification: string}>
     */
    public static function capture(PDO $pdo, array|Budget $limits = []): array
    {
        $budget = $limits instanceof Budget ? $limits : new Budget($limits);

        self::configureStatementTimeout($pdo, $budget->remainingExecutionSeconds());
        $previousBuffered = (bool) $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);

        $tablesStmt = $pdo->query('SELECT TABLE_NAME, ENGINE, TABLE_TYPE, TABLE_ROWS, DATA_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY BINARY TABLE_NAME');
        $tables = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);
        $budget->recordBytes(strlen(serialize($tables)));

        // Validate table cardinality and workload bounds before running full scans
        foreach ($tables as $table) {
            $name = (string) ($table['TABLE_NAME'] ?? '');
            if (($table['ENGINE'] ?? '') !== 'InnoDB' || ($table['TABLE_TYPE'] ?? '') !== 'BASE TABLE' || ! preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
                Guard::refuse();
            }

            // Hard workload refusal if single table estimate already exceeds entire budget
            if (isset($table['DATA_LENGTH']) && $table['DATA_LENGTH'] !== null && (int) $table['DATA_LENGTH'] > $budget->maxTotalBytes()) {
                Guard::refuse();
            }
            if (isset($table['TABLE_ROWS']) && $table['TABLE_ROWS'] !== null && (int) $table['TABLE_ROWS'] > $budget->maxTotalRows()) {
                Guard::refuse();
            }
        }

        $result = [];
        $captureRows = 0;
        $captureBytes = 0;
        $captureStartTime = microtime(true);

        foreach ($tables as $table) {
            $name = (string) $table['TABLE_NAME'];
            $budget->assertWithinLimits();

            $pkStmt = $pdo->query("SHOW INDEX FROM `{$name}` WHERE Key_name = 'PRIMARY'");
            $pk = $pkStmt->fetchAll(PDO::FETCH_ASSOC);
            if ($pk === []) {
                Guard::refuse();
            }
            usort($pk, fn ($a, $b) => $a['Seq_in_index'] <=> $b['Seq_in_index']);
            $order = [];
            foreach ($pk as $key) {
                if (! preg_match('/^[a-zA-Z0-9_]+$/D', (string) $key['Column_name'])) {
                    Guard::refuse();
                }
                $order[] = '`'.$key['Column_name'].'`';
            }

            $createTable = $pdo->query("SHOW CREATE TABLE `{$name}`")->fetch(PDO::FETCH_NUM);
            if (! is_array($createTable) || ! isset($createTable[1]) || ! is_string($createTable[1])) {
                Guard::refuse();
            }
            $ddl = $createTable[1];
            $ddlInfo = self::normalizeDdl($ddl);

            $hash = hash_init('sha256');

            // Unbuffered streaming: explicitly scope unbuffered attribute for row fetching
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            self::configureStatementTimeout($pdo, $budget->remainingExecutionSeconds());

            $rowsStmt = null;
            $count = 0;
            $completedNormally = false;

            try {
                $rowsStmt = $pdo->query("SELECT * FROM `{$name}` ORDER BY ".implode(',', $order));
                while (($row = $rowsStmt->fetch(PDO::FETCH_NUM)) !== false) {
                    $rowHeader = 'R'.count($row).';';
                    $rowBytes = strlen($rowHeader);
                    hash_update($hash, $rowHeader);

                    foreach ($row as $value) {
                        $strValue = $value === null ? null : (string) $value;
                        if ($strValue !== null) {
                            $budget->checkCellBytes(strlen($strValue));
                        }
                        $framed = self::frame($strValue);
                        hash_update($hash, $framed);
                        $rowBytes += strlen($framed);
                    }

                    $budget->recordRow($rowBytes);
                    $captureRows++;
                    $captureBytes += $rowBytes;
                    $count++;
                }
                $completedNormally = true;
            } finally {
                if ($completedNormally) {
                    if ($rowsStmt instanceof PDOStatement) {
                        $rowsStmt->closeCursor();
                    }
                    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $previousBuffered);
                } else {
                    // Early abort: do NOT call closeCursor()! Under mysqlnd, closeCursor() drains
                    // unread network packets over the wire, defeating time and byte limits.
                    // Instead, leave undrained and ensure caller drops the connection.
                }
            }

            $budget->assertWithinLimits();

            $result[$name] = [
                'rows' => $count,
                'schema_sha256' => hash('sha256', $ddl),
                'normalized_schema_sha256' => $ddlInfo['normalized_schema_sha256'],
                'auto_increment' => $ddlInfo['auto_increment'],
                'rows_sha256' => hash_final($hash),
                'classification' => in_array($name, self::OPERATIONAL_MUTABLE_TABLES, true) ? 'operational_mutable' : 'preservation',
            ];
        }

        self::$lastMetrics = [
            'total_rows' => $captureRows,
            'total_bytes' => $captureBytes,
            'duration_seconds' => round(microtime(true) - $captureStartTime, 4),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ];

        return $result;
    }

    /** @return array{total_rows: int, total_bytes: int, duration_seconds: float, peak_memory_bytes: int}|null */
    public static function lastMetrics(): ?array
    {
        return self::$lastMetrics;
    }
}
