<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || ! str_starts_with(DB::connection()->getDatabaseName(), 'accounting_phase7_')) {
    throw new RuntimeException('Disposable Phase 7 DB only.');
}
$mode = $argv[1];
$file = $argv[2];
$snapshot = function (): array {
    $result = [];
    foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $t) {
        if (in_array($t, ['migrations', 'expense_categories', 'expenses', 'employees', 'employee_advances', 'salary_entries', 'salary_payments', 'salary_advance_allocations', 'salary_payment_allocations', 'landed_cost_allocations'], true)) {
            continue;
        }
        $cols = array_values(array_diff(Schema::getColumnListing($t), ['landed_cost_allocated_base']));
        sort($cols);
        $rows = DB::table($t)->select($cols)->get()->map(fn ($r) => json_encode($r, JSON_THROW_ON_ERROR))->all();
        sort($rows);
        $result[$t] = ['count' => count($rows), 'hash' => hash('sha256', implode("\n", $rows))];
    }ksort($result);

    return $result;
};
if ($mode === 'before') {
    file_put_contents($file, json_encode($snapshot(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    exit;
}
$before = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
$forward = $snapshot();
if ($before !== $forward) {
    throw new RuntimeException('Legacy data drift in forward migration.');
}
if (DB::table('purchase_lines')->where('landed_cost_allocated_base', '<>', '0')->exists()) {
    throw new RuntimeException('Legacy landed default not zero.');
}
if (Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
$back = $snapshot();
if ($before !== $back) {
    throw new RuntimeException('Legacy data drift in rollback.');
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
$after = $snapshot();
if ($before !== $after) {
    throw new RuntimeException('Legacy data drift in reapply.');
}
$report = ['healthy' => true, 'database' => DB::connection()->getDatabaseName(), 'tables_preserved' => count($before), 'before' => $before, 'forward' => $forward, 'rollback' => $back, 'reapplied' => $after, 'legacy_landed_default_zero' => true];
file_put_contents($file.'.result.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['healthy' => true, 'tables_preserved' => count($before)]);
