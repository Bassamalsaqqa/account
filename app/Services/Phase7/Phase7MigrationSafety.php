<?php

declare(strict_types=1);

namespace App\Services\Phase7;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class Phase7MigrationSafety
{
    public static function assertEmpty(): void
    {
        foreach (['expenses', 'employee_advances', 'salary_entries', 'salary_payments', 'salary_advance_allocations', 'salary_payment_allocations', 'landed_cost_allocations'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Phase 7 rollback refuses existing history: '.$table);
            }
        }
        if (Schema::hasColumn('purchase_lines', 'landed_cost_allocated_base') && DB::table('purchase_lines')->where('landed_cost_allocated_base', '<>', '0')->exists()) {
            throw new RuntimeException('Phase 7 rollback refuses capitalized acquisition history.');
        }
    }
}
