<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || ! str_starts_with((string) config('database.connections.mysql.database'), 'accounting_phase6_')) {
    throw new RuntimeException('Disposable Phase 6 testing database only.');
}
$snapshot = function (): array {
    $result = [];
    foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $table) {
        if (in_array($table, ['migrations', 'checks', 'check_events', 'money_transfers'], true)) {
            continue;
        }
        $columns = array_values(array_diff(Schema::getColumnListing($table), ['allocation_version', 'payment_currency_amount', 'check_id']));
        sort($columns, SORT_STRING);
        $rows = DB::table($table)->select($columns)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
        sort($rows, SORT_STRING);
        $result[$table] = ['count' => count($rows), 'hash' => hash('sha256', implode("\n", $rows))];
    }
    ksort($result);

    return $result;
};
$before = $snapshot();
if (Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
$rolledBack = $snapshot();
if ($before !== $rolledBack) {
    file_put_contents($argv[1], json_encode(['before' => $before, 'rolled_back' => $rolledBack], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    throw new RuntimeException('Pre-existing row values changed during rollback.');
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
$after = $snapshot();
if ($before !== $after) {
    throw new RuntimeException('Pre-existing row values changed during reapply.');
}
foreach (['customer', 'vendor'] as $domain) {
    if (DB::table($domain.'_payment_allocations')->whereColumn('allocated_amount', '!=', 'payment_currency_amount')->exists()) {
        throw new RuntimeException('Legacy exact currency backfill failed.');
    }
    if (DB::table($domain.'_payments')->where('allocation_version', '!=', 1)->exists()) {
        throw new RuntimeException('Legacy request version changed.');
    }
}
$report = ['healthy' => true, 'database' => config('database.connections.mysql.database'), 'preserved_table_count' => count($before),
    'before' => $before, 'rolled_back' => $rolledBack, 'reapplied' => $after, 'legacy_allocation_backfill' => true];
file_put_contents($argv[1], json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['healthy' => true, 'tables' => count($before), 'legacy_backfill' => true]);
