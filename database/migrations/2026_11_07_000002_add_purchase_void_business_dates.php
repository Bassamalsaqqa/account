<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preflight all history before DDL (MariaDB DDL is not transactional).
        $dates = [];
        foreach (['purchases' => 'purchase_void', 'purchase_returns' => 'purchase_return_void'] as $table => $source) {
            foreach (DB::table($table)->where('status', 'void')->get() as $row) {
                $date = $row->void_posting_batch_id === null ? null : DB::table('posting_batches')
                    ->where('company_id', $row->company_id)->where('id', $row->void_posting_batch_id)
                    ->where('reversal_of_id', $row->posting_batch_id)->where('status', 'posted')->value('posting_date');
                $stockDates = DB::table('stock_movements')->where('company_id', $row->company_id)
                    ->where('source_type', $source)->where('source_id', $row->id)->distinct()->pluck('movement_date');
                if ($date === null && $stockDates->count() === 1) {
                    $date = $stockDates->sole();
                }
                if ($date === null || $stockDates->contains(fn ($stockDate) => $stockDate !== $date)) {
                    throw new RuntimeException('Ambiguous historical void business date; explicit history review required.');
                }
                $dates[$table][$row->id] = $date;
            }
        }
        foreach (['purchases', 'purchase_returns'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->date('void_business_date')->nullable());
            foreach ($dates[$table] ?? [] as $id => $date) {
                DB::table($table)->where('id', $id)->update(['void_business_date' => $date]);
            }
        }
    }

    public function down(): void
    {
        foreach (['purchases', 'purchase_returns'] as $table) {
            if (DB::table($table)->where(fn ($query) => $query->where('status', 'void')->orWhereNotNull('void_business_date')->orWhereNotNull('voided_at')->orWhereNotNull('void_posting_batch_id'))->exists()) {
                throw new RuntimeException('Cannot remove historical void business dates.');
            }
        }
        foreach (['purchases', 'purchase_returns'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('void_business_date'));
        }
    }
};
