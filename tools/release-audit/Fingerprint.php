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

    public function checkCellBytes(int|string|null $bytes): void
    {
        if ($bytes === null) {
            return;
        }
        if (is_string($bytes)) {
            if (bccomp($bytes, (string) $this->maxCellBytes) > 0) {
                Guard::refuse();
            }
            if (bccomp(bcadd((string) $this->totalBytes, $bytes), (string) $this->maxTotalBytes) > 0) {
                Guard::refuse();
            }

            return;
        }
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

        if (($now - $this->startTime) >= $this->maxExecutionSeconds) {
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
        $this->assertWithinLimits();
        $elapsed = microtime(true) - $this->startTime;
        $remaining = (int) floor($this->maxExecutionSeconds - $elapsed);
        if ($remaining <= 0) {
            Guard::refuse();
        }

        return $remaining;
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
        if ($timeoutSeconds <= 0) {
            Guard::refuse();
        }

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
     * Locate table definition closing parenthesis using quote-aware paren nesting depth,
     * so that parentheses inside default expressions, column comments, and table comments
     * are never misidentified as the table definition boundary.
     *
     * @return array{normalized_schema_sha256: string, auto_increment: int|string|null}
     */
    public static function normalizeDdl(string $ddl): array
    {
        $len = strlen($ddl);
        $inQuote = false;
        $quoteChar = '';
        $depth = 0;
        $foundOpen = false;
        $closingParenIndex = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $ddl[$i];
            if ($inQuote) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $i++;

                    continue;
                }
                if ($ch === $quoteChar) {
                    if ($i + 1 < $len && $ddl[$i + 1] === $quoteChar) {
                        $i++;

                        continue;
                    }
                    $inQuote = false;
                }

                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $inQuote = true;
                $quoteChar = $ch;

                continue;
            }

            if ($ch === '(') {
                $depth++;
                $foundOpen = true;
            } elseif ($ch === ')') {
                if ($depth > 0) {
                    $depth--;
                    if ($depth === 0 && $foundOpen) {
                        $closingParenIndex = $i;
                        break;
                    }
                }
            }
        }

        if ($closingParenIndex === false) {
            Guard::refuse();
        }

        $tableDef = substr($ddl, 0, $closingParenIndex + 1);
        $tableOptions = substr($ddl, $closingParenIndex + 1);

        $optLen = strlen($tableOptions);
        $inQuote = false;
        $quoteChar = '';
        $cleanOptions = '';
        $counter = null;
        $j = 0;

        while ($j < $optLen) {
            $ch = $tableOptions[$j];
            if ($inQuote) {
                $cleanOptions .= $ch;
                if ($ch === '\\' && $j + 1 < $optLen) {
                    $cleanOptions .= $tableOptions[$j + 1];
                    $j += 2;

                    continue;
                }
                if ($ch === $quoteChar) {
                    if ($j + 1 < $optLen && $tableOptions[$j + 1] === $quoteChar) {
                        $cleanOptions .= $quoteChar;
                        $j += 2;

                        continue;
                    }
                    $inQuote = false;
                }
                $j++;

                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $inQuote = true;
                $quoteChar = $ch;
                $cleanOptions .= $ch;
                $j++;

                continue;
            }

            // Outside quotes in table options: match \s*AUTO_INCREMENT=(\d+)
            if (preg_match('/^(\s*AUTO_INCREMENT=(\d+))/i', substr($tableOptions, $j), $matches)) {
                $fullMatch = $matches[1];
                $digits = $matches[2];
                $parsedInt = filter_var($digits, FILTER_VALIDATE_INT);
                $counter = $parsedInt !== false ? $parsedInt : $digits;
                $j += strlen($fullMatch);

                continue;
            }

            $cleanOptions .= $ch;
            $j++;
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

        $tablesStmt = $pdo->query('SELECT TABLE_NAME, ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY BINARY TABLE_NAME');
        $tables = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);
        $budget->recordBytes(strlen(serialize($tables)));

        foreach ($tables as $table) {
            $name = (string) ($table['TABLE_NAME'] ?? '');
            if (($table['ENGINE'] ?? '') !== 'InnoDB' || ($table['TABLE_TYPE'] ?? '') !== 'BASE TABLE' || ! preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
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

            // Fetch table column names via zero-row query
            $colsStmt = $pdo->query("SELECT * FROM `{$name}` LIMIT 0");
            $numCols = $colsStmt->columnCount();
            if ($numCols < 1) {
                Guard::refuse();
            }
            $cols = [];
            for ($i = 0; $i < $numCols; $i++) {
                $meta = $colsStmt->getColumnMeta($i);
                if (! isset($meta['name']) || ! preg_match('/^[a-zA-Z0-9_]+$/D', (string) $meta['name'])) {
                    Guard::refuse();
                }
                $cols[] = (string) $meta['name'];
            }

            // -------------------------------------------------------------
            // PHASE A: Engine-only numeric aggregate query.
            // Returns ONLY bounded numeric aggregates (zero payload materialization).
            // Computes COUNT(*), MAX row byte sum, SUM total byte sum, and MAX cell bytes per column.
            // -------------------------------------------------------------
            $maxCellExprs = array_map(fn ($c) => "MAX(OCTET_LENGTH(`{$c}`))", $cols);
            $sumRowExpr = 'MAX('.implode(' + ', array_map(fn ($c) => "COALESCE(OCTET_LENGTH(`{$c}`), 0)", $cols)).')';
            $sumTotalExpr = 'SUM('.implode(' + ', array_map(fn ($c) => "COALESCE(OCTET_LENGTH(`{$c}`), 0)", $cols)).')';
            $aggSql = 'SELECT COUNT(*), '.$sumRowExpr.', '.$sumTotalExpr.', '.implode(', ', $maxCellExprs)." FROM `{$name}`";

            self::configureStatementTimeout($pdo, $budget->remainingExecutionSeconds());
            $aggRow = $pdo->query($aggSql)->fetch(PDO::FETCH_NUM);
            if (! is_array($aggRow) || count($aggRow) !== (3 + $numCols)) {
                Guard::refuse();
            }

            $tableRowCount = (string) $aggRow[0];
            $maxRowBytes = $aggRow[1] !== null ? (string) $aggRow[1] : '0';
            $sumTableBytes = $aggRow[2] !== null ? (string) $aggRow[2] : '0';

            // Verify row count bounds with overflow safety
            if (bccomp($tableRowCount, (string) $budget->maxTotalRows()) > 0) {
                Guard::refuse();
            }
            if (bccomp(bcadd((string) $budget->totalRows(), $tableRowCount), (string) $budget->maxTotalRows()) > 0) {
                Guard::refuse();
            }

            // Verify single row memory limit: a single row's raw bytes cannot exceed maxMemoryBytes / 4
            if (bccomp($maxRowBytes, (string) $budget->maxCellBytes()) > 0) {
                Guard::refuse();
            }

            // Verify total bytes sum against cumulative budget
            if (bccomp($sumTableBytes, (string) $budget->maxTotalBytes()) > 0) {
                Guard::refuse();
            }
            if (bccomp(bcadd((string) $budget->totalBytes(), $sumTableBytes), (string) $budget->maxTotalBytes()) > 0) {
                Guard::refuse();
            }

            // Verify each column's max cell byte size against maxCellBytes limit
            for ($c = 0; $c < $numCols; $c++) {
                $maxCell = $aggRow[3 + $c];
                if ($maxCell !== null) {
                    $budget->checkCellBytes((string) $maxCell);
                }
            }

            // -------------------------------------------------------------
            // PHASE B: Stream ONLY values, incremental hash without doubled frame.
            // Workload is now PROVEN within bounded limits BEFORE payload query runs.
            // -------------------------------------------------------------
            $valExprs = array_map(fn ($c) => "`{$c}`", $cols);
            $streamingSql = 'SELECT '.implode(', ', $valExprs)." FROM `{$name}` ORDER BY ".implode(',', $order);

            $hash = hash_init('sha256');

            // Unbuffered streaming: explicitly scope unbuffered attribute for row fetching
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            self::configureStatementTimeout($pdo, $budget->remainingExecutionSeconds());

            $rowsStmt = null;
            $count = 0;
            $completedNormally = false;

            try {
                $rowsStmt = $pdo->query($streamingSql);
                while (($row = $rowsStmt->fetch(PDO::FETCH_NUM)) !== false) {
                    $rowHeader = "R{$numCols};";
                    hash_update($hash, $rowHeader);
                    $rowBytes = strlen($rowHeader);

                    foreach ($row as $val) {
                        if ($val === null) {
                            hash_update($hash, 'N;');
                            $rowBytes += 2;
                        } else {
                            $strVal = (string) $val;
                            $strLen = strlen($strVal);
                            hash_update($hash, "S{$strLen}:");
                            hash_update($hash, $strVal);
                            $rowBytes += (strlen((string) $strLen) + 2 + $strLen);
                        }
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
