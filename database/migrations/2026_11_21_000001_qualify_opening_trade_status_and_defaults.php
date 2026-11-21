<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Explicit prevalidation: verify all existing records satisfy the contract before running any DDL
        $incompatibleCount = DB::table('opening_trade_items')
            ->where(function ($q): void {
                $q->whereNull('status')
                    ->orWhereNotIn('status', ['posted', 'reversed']);
            })
            ->count();

        if ($incompatibleCount > 0) {
            throw new RuntimeException("Cannot upgrade opening_trade_items schema: {$incompatibleCount} incompatible record(s) found. Prior schema preserved.");
        }

        // 2. Perform supported single ALTER operation in MariaDB/MySQL
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE opening_trade_items MODIFY status VARCHAR(20) NOT NULL, ADD CONSTRAINT oti_status_valid CHECK (status IN ('posted', 'reversed'))");
        } else {
            Schema::table('opening_trade_items', function (Blueprint $table): void {
                $table->string('status', 20)->default(null)->nullable(false)->change();
            });
            DB::statement("ALTER TABLE opening_trade_items ADD CONSTRAINT oti_status_valid CHECK (status IN ('posted', 'reversed'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('opening_trade_items')->exists()) {
            throw new RuntimeException('Cannot downgrade populated opening trade history. Retain this schema and use a forward correction.');
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE opening_trade_items DROP CONSTRAINT IF EXISTS oti_status_valid, MODIFY status VARCHAR(20) NOT NULL DEFAULT 'posted'");
        } else {
            DB::statement('ALTER TABLE opening_trade_items DROP CONSTRAINT IF EXISTS oti_status_valid');
            Schema::table('opening_trade_items', function (Blueprint $table): void {
                $table->string('status', 20)->default('posted')->change();
            });
        }
    }
};
