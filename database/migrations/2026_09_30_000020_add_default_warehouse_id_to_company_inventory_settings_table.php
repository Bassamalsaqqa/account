<?php

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
        Schema::table('company_inventory_settings', function (Blueprint $table) {
            $table->foreignId('default_warehouse_id')
                ->nullable()
                ->after('allow_negative_stock')
                ->constrained('warehouses')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_inventory_settings', function (Blueprint $table) {
            $table->dropForeign(['default_warehouse_id']);
            $table->dropColumn('default_warehouse_id');
        });
    }
};
