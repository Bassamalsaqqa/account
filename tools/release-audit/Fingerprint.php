<?php

declare(strict_types=1);

namespace ReleaseAudit;

use PDO;
use PDOStatement;

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

    /**
     * @param  array<string, int>  $limits
     * @return array<string, array{rows: int, schema_sha256: string, normalized_schema_sha256: string, auto_increment: ?int, rows_sha256: string, classification: string}>
     */
    public static function capture(PDO $pdo, array $limits = []): array
    {
        $maxExecutionSeconds = $limits['max_execution_seconds'] ?? 60;
        $maxTotalRows = $limits['max_total_rows'] ?? 500000;
        $maxTotalBytes = $limits['max_total_bytes'] ?? 104857600;
        $maxMemoryBytes = $limits['max_memory_bytes'] ?? 67108864;

        $startTime = microtime(true);
        $totalRows = 0;
        $totalBytes = 0;
        $result = [];

        $tablesStmt = $pdo->query('SELECT TABLE_NAME, ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY BINARY TABLE_NAME');
        $tables = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tables as $table) {
            $name = (string) $table['TABLE_NAME'];
            if ($table['ENGINE'] !== 'InnoDB' || $table['TABLE_TYPE'] !== 'BASE TABLE' || ! preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
                Guard::refuse();
            }

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
            $normalizedDdl = preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $ddl) ?? $ddl;
            $counter = preg_match('/\s+AUTO_INCREMENT=(\d+)/i', $ddl, $m) ? (int) $m[1] : null;

            $hash = hash_init('sha256');

            // Unbuffered streaming: explicitly scope unbuffered attribute for row fetching
            // so client memory does not materialize large result sets.
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $rowsStmt = null;
            $count = 0;
            try {
                $rowsStmt = $pdo->query("SELECT * FROM `{$name}` ORDER BY ".implode(',', $order));
                while (($row = $rowsStmt->fetch(PDO::FETCH_NUM)) !== false) {
                    $totalRows++;
                    if ($totalRows > $maxTotalRows) {
                        Guard::refuse();
                    }

                    $rowHeader = 'R'.count($row).';';
                    hash_update($hash, $rowHeader);
                    $totalBytes += strlen($rowHeader);

                    foreach ($row as $value) {
                        $framed = self::frame($value === null ? null : (string) $value);
                        hash_update($hash, $framed);
                        $totalBytes += strlen($framed);
                    }

                    if ($totalBytes > $maxTotalBytes) {
                        Guard::refuse();
                    }

                    if (($count % 1000) === 0) {
                        if ((microtime(true) - $startTime) > $maxExecutionSeconds) {
                            Guard::refuse();
                        }
                        if (memory_get_usage(true) > $maxMemoryBytes) {
                            Guard::refuse();
                        }
                    }
                    $count++;
                }
            } finally {
                if ($rowsStmt instanceof PDOStatement) {
                    $rowsStmt->closeCursor();
                }
                // Always restore buffered queries for subsequent metadata and canonical service queries.
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }

            if ((microtime(true) - $startTime) > $maxExecutionSeconds || memory_get_usage(true) > $maxMemoryBytes) {
                Guard::refuse();
            }

            $result[$name] = [
                'rows' => $count,
                'schema_sha256' => hash('sha256', $ddl),
                'normalized_schema_sha256' => hash('sha256', $normalizedDdl),
                'auto_increment' => $counter,
                'rows_sha256' => hash_final($hash),
                'classification' => in_array($name, self::OPERATIONAL_MUTABLE_TABLES, true) ? 'operational_mutable' : 'preservation',
            ];
        }

        self::$lastMetrics = [
            'total_rows' => $totalRows,
            'total_bytes' => $totalBytes,
            'duration_seconds' => round(microtime(true) - $startTime, 4),
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
