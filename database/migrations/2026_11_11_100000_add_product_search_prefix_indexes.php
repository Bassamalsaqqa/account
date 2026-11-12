<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Forward composite prefix indexes to optimize bounded name searches without full-table scans.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['company_id', 'active', 'name_ar'], 'products_company_active_name_ar_idx');
            $table->index(['company_id', 'active', 'name_en'], 'products_company_active_name_en_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_company_active_name_ar_idx');
            $table->dropIndex('products_company_active_name_en_idx');
        });
    }
};
