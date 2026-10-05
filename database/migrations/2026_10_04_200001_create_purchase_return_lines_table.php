<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('purchase_line_id')->constrained('purchase_lines')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_unit_id')->constrained('product_units')->restrictOnDelete();
            $table->string('item_description', 500);
            foreach (['quantity', 'quantity_base', 'unit_cost', 'discount_value', 'line_discount', 'line_subtotal', 'line_tax', 'line_total', 'line_subtotal_base', 'line_discount_base', 'line_tax_base', 'line_total_base', 'unit_conversion_ratio'] as $amount) {
                $table->decimal($amount, 20, 6)->default(0);
            }
            $table->string('discount_type', 16)->nullable();
            $table->decimal('tax_rate_snapshot', 12, 6)->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->foreignId('purchase_tax_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('unit_name_ar', 128)->nullable();
            $table->string('unit_name_en', 128)->nullable();
            $table->string('product_sku', 128)->nullable();
            $table->string('product_name_ar');
            $table->string('product_name_en')->nullable();
            $table->decimal('historical_receipt_value_base', 20, 6)->nullable();
            $table->decimal('inventory_value_removed_base', 20, 6)->nullable();
            $table->decimal('valuation_adjustment_base', 20, 6)->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['purchase_return_id', 'purchase_line_id']);
            $table->unique(['purchase_return_id', 'line_number']);
            $table->index(['company_id', 'purchase_return_id']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_lines');
    }
};
