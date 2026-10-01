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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->string('movement_type', 64);
            $table->date('movement_date');
            $table->decimal('quantity_delta_base', 20, 6);
            $table->decimal('unit_cost_base', 20, 6);
            $table->decimal('value_delta_base', 20, 6);
            $table->decimal('average_cost_after', 20, 6);
            $table->decimal('quantity_after_product_company', 20, 6);
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('source_quantity', 20, 6)->nullable();
            $table->decimal('conversion_to_base', 20, 6)->nullable();
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->string('idempotency_key', 191)->nullable();
            $table->string('reason', 512)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'product_id', 'movement_date']);
            $table->index(['company_id', 'warehouse_id', 'movement_date']);
            $table->index(['company_id', 'lot_id', 'movement_date']);
            $table->index(['company_id', 'source_type', 'source_id']);
            $table->index(['company_id', 'idempotency_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
