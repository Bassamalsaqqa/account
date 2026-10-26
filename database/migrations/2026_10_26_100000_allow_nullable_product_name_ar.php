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
        Schema::table('products', function (Blueprint $table): void {
            $table->string('name_ar', 255)->nullable()->change();
        });

        Schema::table('purchase_lines', function (Blueprint $table): void {
            $table->string('product_name_ar', 255)->nullable()->change();
        });

        Schema::table('purchase_return_lines', function (Blueprint $table): void {
            $table->string('product_name_ar', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Rollback behavior: Reversal is blocked if any English-only products or
     * historical transaction lines exist where name_ar is null. The system
     * never fabricates Arabic translations or corrupts historical document
     * snapshots to force a schema rollback. When no null Arabic names exist,
     * the columns are safely restored to NOT NULL.
     */
    public function down(): void
    {
        $hasNullProductNames = DB::table('products')->whereNull('name_ar')->exists();
        $hasNullPurchaseLines = DB::table('purchase_lines')->whereNull('product_name_ar')->exists();
        $hasNullPurchaseReturnLines = DB::table('purchase_return_lines')->whereNull('product_name_ar')->exists();

        if ($hasNullProductNames || $hasNullPurchaseLines || $hasNullPurchaseReturnLines) {
            throw new RuntimeException('Cannot reverse migration: English-only products or transaction snapshots exist with null Arabic name. Reversal blocked to preserve data integrity and prevent fabricating Arabic names.');
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->string('name_ar', 255)->nullable(false)->change();
        });

        Schema::table('purchase_lines', function (Blueprint $table): void {
            $table->string('product_name_ar', 255)->nullable(false)->change();
        });

        Schema::table('purchase_return_lines', function (Blueprint $table): void {
            $table->string('product_name_ar', 255)->nullable(false)->change();
        });
    }
};
