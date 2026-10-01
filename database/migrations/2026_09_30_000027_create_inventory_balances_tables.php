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
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('quantity_base', 20, 6)->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['company_id', 'product_id', 'warehouse_id']);
            $table->index(['company_id', 'warehouse_id']);
        });

        Schema::create('inventory_cost_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('quantity_base', 20, 6)->default(0);
            $table->decimal('average_cost_base', 20, 6)->default(0);
            $table->decimal('inventory_value_base', 20, 6)->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['company_id', 'product_id']);
        });

        Schema::create('inventory_lot_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('inventory_lots')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('quantity_base', 20, 6)->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['company_id', 'lot_id', 'warehouse_id']);
            $table->index(['company_id', 'warehouse_id']);
            $table->index(['company_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_lot_balances');
        Schema::dropIfExists('inventory_cost_states');
        Schema::dropIfExists('inventory_balances');
    }
};
