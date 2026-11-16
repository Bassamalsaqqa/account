<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['company_id', 'active', 'name_ar'], 'idx_products_co_active_name_ar');
            $table->index(['company_id', 'active', 'name_en'], 'idx_products_co_active_name_en');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->index(['company_id', 'status', 'name_ar'], 'idx_customers_co_status_name_ar');
            $table->index(['company_id', 'status', 'name_en'], 'idx_customers_co_status_name_en');
        });

        Schema::table('vendors', function (Blueprint $table): void {
            $table->index(['company_id', 'status', 'name_ar'], 'idx_vendors_co_status_name_ar');
            $table->index(['company_id', 'status', 'name_en'], 'idx_vendors_co_status_name_en');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            $table->dropIndex('idx_vendors_co_status_name_en');
            $table->dropIndex('idx_vendors_co_status_name_ar');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('idx_customers_co_status_name_en');
            $table->dropIndex('idx_customers_co_status_name_ar');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('idx_products_co_active_name_en');
            $table->dropIndex('idx_products_co_active_name_ar');
        });
    }
};
