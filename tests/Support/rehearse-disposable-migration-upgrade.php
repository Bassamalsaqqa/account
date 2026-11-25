<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;

$root = dirname(__DIR__, 2);
$baseline = $argv[1] ?? '';
if ($argc !== 2 || preg_match('/^[a-f0-9]{40}$/D', $baseline) !== 1) {
    fwrite(STDERR, "Usage: php tests/Support/rehearse-disposable-migration-upgrade.php <baseline-commit-sha>\n");
    exit(1);
}

$run = static function (array $command, array $environment = []) use ($root): string {
    $process = new Process($command, $root, $environment);
    $process->setTimeout(300)->mustRun();

    return trim($process->getOutput());
};

$migrations = static function (PDO $connection): array {
    return $connection->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
};

$definitions = static function (PDO $connection): array {
    $definitions = [];
    foreach ($connection->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $identifier = '`'.str_replace('`', '``', $table).'`';
        $definitions[$table] = $connection->query('SHOW CREATE TABLE '.$identifier)->fetch(PDO::FETCH_NUM)[1];
    }

    return $definitions;
};

$upgraded = null;
$fresh = null;
$exitCode = 0;
try {
    DisposableMariaDbSchema::testSettings();
    if ($run(['git', 'status', '--porcelain']) !== '') {
        throw new RuntimeException('The candidate checkout must be clean.');
    }
    $run(['git', 'merge-base', '--is-ancestor', $baseline, 'HEAD']);
    $location = 'database/migrations';
    $baselineFiles = explode("\n", $run(['git', 'ls-tree', '-r', '--name-only', $baseline, '--', $location]));
    $baselineFiles = array_values(array_filter($baselineFiles, static fn (string $path): bool => str_ends_with($path, '.php')));
    $changes = explode("\n", $run(['git', 'diff', '--name-status', $baseline, 'HEAD', '--', $location, 'database/schema']));
    $added = [];
    foreach ($changes as $change) {
        if (preg_match('/^A\t(database\/migrations\/[^\/]+\.php)$/D', $change, $matches) !== 1) {
            throw new RuntimeException('The rehearsal requires an additive migration chain with unchanged baseline files and schema dumps.');
        }
        $added[] = basename($matches[1], '.php');
    }
    sort($added);
    if ($baselineFiles === [] || $added === []) {
        throw new RuntimeException('Both the baseline and candidate migration sets must be nonempty.');
    }

    $upgraded = DisposableMariaDbSchema::create();
    $fresh = DisposableMariaDbSchema::create();
    $paths = array_map(static fn (string $file): string => '--path='.$file, $baselineFiles);
    $run([PHP_BINARY, 'artisan', 'migrate', '--database=mysql', '--force', ...$paths], $upgraded->environment());
    $original = $migrations($upgraded->createSeparatePdo());
    if ($original !== array_map(static fn (string $file): string => basename($file, '.php'), $baselineFiles)) {
        throw new RuntimeException('The applied baseline differs from the pinned Git migration set.');
    }

    $upgraded->migrate();
    $fresh->migrate();
    $upgradedConnection = $upgraded->createSeparatePdo();
    $freshConnection = $fresh->createSeparatePdo();
    $upgradedMigrations = $migrations($upgradedConnection);
    $freshDefinitions = $definitions($freshConnection);

    if (array_values(array_diff($upgradedMigrations, $original)) !== $added
        || $upgradedMigrations !== $migrations($freshConnection)
        || $definitions($upgradedConnection) !== $freshDefinitions) {
        throw new RuntimeException('The upgraded baseline differs from a fresh candidate schema.');
    }

    printf(
        "Synthetic upgrade passed on MariaDB %s. Baseline %s (%d migrations). Candidate %s (%d added migrations, %d matching table definitions).\n",
        $upgradedConnection->query('SELECT VERSION()')->fetchColumn(),
        $baseline,
        count($original),
        $run(['git', 'rev-parse', 'HEAD']),
        count($added),
        count($freshDefinitions)
    );
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    unset($upgradedConnection, $freshConnection);
    try {
        $fresh?->drop();
    } finally {
        $upgraded?->drop();
    }
}

exit($exitCode);
