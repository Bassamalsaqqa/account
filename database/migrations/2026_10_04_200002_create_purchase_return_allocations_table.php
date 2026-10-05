<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('purchase_return_line_id')->constrained('purchase_return_lines')->cascadeOnDelete();
            $table->foreignId('original_stock_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('purchase_line_lot_id')->nullable()->constrained('purchase_line_lots')->restrictOnDelete();
            $table->foreignId('inventory_lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->decimal('quantity_base', 20, 6);
            $table->decimal('historical_value_base', 20, 6)->nullable();
            $table->decimal('inventory_value_removed_base', 20, 6)->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['purchase_return_line_id', 'original_stock_movement_id'], 'pr_alloc_line_orig_mov_unique');
            $table->index(['company_id', 'purchase_return_id'], 'pra_company_return_idx');
            $table->index(['company_id', 'original_stock_movement_id'], 'pra_company_orig_mov_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_allocations');
    }
};
