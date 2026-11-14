<?php

declare(strict_types=1);

namespace ReleaseAudit;

use Throwable;

require __DIR__.'/Guard.php';
require __DIR__.'/Fingerprint.php';

try {
    if (PHP_SAPI !== 'cli' || (count($argv) !== 4 && count($argv) !== 5)) {
        Guard::refuse();
    }
    if (! preg_match('/^[a-f0-9]{64}$/D', $argv[3]) || ! hash_equals(Guard::implementationDigest(), $argv[3])) {
        Guard::refuse();
    }
    $disposition = null;
    if (count($argv) === 5) {
        if ($argv[4] !== '--accept-operational-volatility') {
            Guard::refuse();
        }
        $disposition = $argv[4];
    }
    $reports = [];
    foreach (array_slice($argv, 1, 2) as $path) {
        Guard::path(dirname($path), false);
        Guard::path($path, true);
        $reports[] = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
    [$a, $b] = $reports;
    if (($a['version'] ?? null) !== 1 || ($b['version'] ?? null) !== 1 || ! isset($a['implementation_sha256'], $a['tables'], $b['tables'])
        || ! is_array($a['tables']) || ! is_array($b['tables']) || empty($a['tables']) || empty($b['tables'])
        || ! is_string($a['implementation_sha256']) || ! hash_equals($argv[3], $a['implementation_sha256'])
        || $a['implementation_sha256'] !== ($b['implementation_sha256'] ?? null)) {
        Guard::refuse();
    }

    $allTableNames = array_unique(array_merge(array_keys($a['tables']), array_keys($b['tables'])));
    sort($allTableNames);

    $preservationDiffs = [];
    $schemaDiffs = [];
    $operationalChurn = [];

    foreach ($allTableNames as $name) {
        if (! array_key_exists($name, $a['tables']) || ! array_key_exists($name, $b['tables'])) {
            $schemaDiffs[$name] = ['type' => 'table_membership_changed'];

            continue;
        }

        $tA = $a['tables'][$name];
        $tB = $b['tables'][$name];
        $expectedClassification = in_array($name, Fingerprint::OPERATIONAL_MUTABLE_TABLES, true) ? 'operational_mutable' : 'preservation';
        foreach ([$tA, $tB] as $tableReport) {
            if (! is_array($tableReport) || ($tableReport['classification'] ?? null) !== $expectedClassification
                || ! isset($tableReport['rows']) || ! is_int($tableReport['rows']) || $tableReport['rows'] < 0) {
                Guard::refuse();
            }
            foreach (['schema_sha256', 'normalized_schema_sha256', 'rows_sha256'] as $field) {
                if (! is_string($tableReport[$field] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $tableReport[$field])) {
                    Guard::refuse();
                }
            }
            if (array_key_exists('auto_increment', $tableReport) && $tableReport['auto_increment'] !== null) {
                $ai = $tableReport['auto_increment'];
                if (is_int($ai)) {
                    if ($ai < 0) {
                        Guard::refuse();
                    }
                } elseif (is_string($ai)) {
                    if (! preg_match('/^\d+$/D', $ai)) {
                        Guard::refuse();
                    }
                } else {
                    Guard::refuse();
                }
            }
        }

        $normA = $tA['normalized_schema_sha256'] ?? $tA['schema_sha256'] ?? '';
        $normB = $tB['normalized_schema_sha256'] ?? $tB['schema_sha256'] ?? '';
        if ($normA !== $normB) {
            $schemaDiffs[$name] = [
                'type' => 'substantive_schema_changed',
                'schema_before' => $normA,
                'schema_after' => $normB,
            ];

            continue;
        }

        $rowsMatch = ($tA['rows'] === $tB['rows']) && ($tA['rows_sha256'] === $tB['rows_sha256']);
        $schemaExactMatch = ($tA['schema_sha256'] === $tB['schema_sha256']);

        if ($rowsMatch && $schemaExactMatch) {
            continue;
        }

        $isOperational = $expectedClassification === 'operational_mutable';

        if (! $isOperational) {
            $preservationDiffs[$name] = [
                'rows_before' => $tA['rows'],
                'rows_after' => $tB['rows'],
                'rows_sha256_before' => $tA['rows_sha256'],
                'rows_sha256_after' => $tB['rows_sha256'],
                'counter_before' => $tA['auto_increment'] ?? null,
                'counter_after' => $tB['auto_increment'] ?? null,
            ];
        } else {
            $operationalChurn[$name] = [
                'rows_before' => $tA['rows'],
                'rows_after' => $tB['rows'],
                'rows_sha256_before' => $tA['rows_sha256'],
                'rows_sha256_after' => $tB['rows_sha256'],
                'counter_before' => $tA['auto_increment'] ?? null,
                'counter_after' => $tB['auto_increment'] ?? null,
            ];
        }
    }

    if ($preservationDiffs === [] && $schemaDiffs === [] && $operationalChurn === []) {
        fwrite(STDOUT, "FINGERPRINTS_EQUAL\n");
        exit(0);
    }

    if ($preservationDiffs !== [] || $schemaDiffs !== []) {
        fwrite(STDOUT, "FINGERPRINTS_DIFFER\n");
        if ($schemaDiffs !== []) {
            fwrite(STDERR, 'Schema differences detected in: '.implode(', ', array_keys($schemaDiffs))."\n");
        }
        if ($preservationDiffs !== []) {
            fwrite(STDERR, 'Preservation differences detected in: '.implode(', ', array_keys($preservationDiffs))."\n");
        }
        exit(2);
    }

    // Operational churn only
    $churnList = implode(', ', array_keys($operationalChurn));
    if ($disposition === '--accept-operational-volatility') {
        fwrite(STDOUT, "OPERATIONAL_VOLATILITY_ACCEPTED\n");
        fwrite(STDERR, "Operational churn accepted in: {$churnList}\n");
        fwrite(STDERR, "NOTICE: Operational volatility acceptance is a comparison disposition only; it is NOT a release approval or production certification.\n");
        exit(0);
    }

    fwrite(STDOUT, "OPERATIONAL_VOLATILITY_ONLY\n");
    fwrite(STDERR, "Operational churn detected in: {$churnList}\n");
    exit(3);
} catch (Throwable) {
    fwrite(STDERR, "AUDIT_REFUSED\n");
    exit(1);
}
