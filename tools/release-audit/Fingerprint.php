<?php

declare(strict_types=1);

namespace ReleaseAudit;

use PDO;

final class Fingerprint
{
    public static function frame(?string $value): string
    {
        return $value === null ? 'N;' : 'S'.strlen($value).':'.$value;
    }

    /** @return array<string, array{rows: int, schema_sha256: string, rows_sha256: string, classification: string}> */
    public static function capture(PDO $pdo): array
    {
        $result = [];
        $tables = $pdo->query('SELECT TABLE_NAME, ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY BINARY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tables as $table) {
            $name = (string) $table['TABLE_NAME'];
            if ($table['ENGINE'] !== 'InnoDB' || $table['TABLE_TYPE'] !== 'BASE TABLE' || ! preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
                Guard::refuse();
            }
            $pk = $pdo->query("SHOW INDEX FROM `{$name}` WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
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
            $hash = hash_init('sha256');
            $rows = $pdo->query("SELECT * FROM `{$name}` ORDER BY ".implode(',', $order));
            $count = 0;
            while (($row = $rows->fetch(PDO::FETCH_NUM)) !== false) {
                hash_update($hash, 'R'.count($row).';');
                foreach ($row as $value) {
                    hash_update($hash, self::frame($value === null ? null : (string) $value));
                }
                $count++;
            }
            $result[$name] = [
                'rows' => $count,
                'schema_sha256' => hash('sha256', $ddl),
                'rows_sha256' => hash_final($hash),
                'classification' => in_array($name, ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'], true) ? 'operational_mutable' : 'preservation',
            ];
        }

        return $result;
    }
}
