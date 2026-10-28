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
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('name_ar', 255)->nullable()->change();
        });

        Schema::table('vendors', function (Blueprint $table): void {
            $table->string('name_ar', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Rollback behavior: Reversal is blocked if any English-only customers or
     * vendors exist where name_ar is null (including soft-deleted records).
     * The system never fabricates Arabic translations or corrupts historical
     * records to force a schema rollback. When no null Arabic names exist,
     * the columns are safely restored to NOT NULL.
     */
    public function down(): void
    {
        $hasNullCustomerNames = DB::table('customers')->whereNull('name_ar')->exists();
        $hasNullVendorNames = DB::table('vendors')->whereNull('name_ar')->exists();

        if ($hasNullCustomerNames || $hasNullVendorNames) {
            throw new RuntimeException('Cannot reverse migration: English-only customers or vendors exist with null Arabic name. Reversal blocked to preserve data integrity and prevent fabricating Arabic names.');
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('name_ar', 255)->nullable(false)->change();
        });

        Schema::table('vendors', function (Blueprint $table): void {
            $table->string('name_ar', 255)->nullable(false)->change();
        });
    }
};
