<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add DB-level UNIQUE constraint on (company_id, idempotency_key) for stock_movements.
     * Also drops the non-unique index since the unique constraint covers it.
     */
    public function up(): void
    {
        // Drop the original non-unique index first (created in original migration)
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unique(['company_id', 'idempotency_key'], 'stock_movements_company_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique('stock_movements_company_idempotency_unique');
        });
    }
};
