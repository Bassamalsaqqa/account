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
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('quotation_number', 64);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->decimal('exchange_rate', 20, 10);
            $table->date('issue_date');
            $table->date('expiry_date')->nullable();
            $table->string('status', 32)->default('draft'); // draft, sent, accepted, rejected, expired, converted
            $table->string('pricing_tier', 32)->default('regular');
            $table->decimal('subtotal_base', 20, 6)->default(0);
            $table->decimal('discount_total_base', 20, 6)->default(0);
            $table->decimal('tax_total_base', 20, 6)->default(0);
            $table->decimal('grand_total_base', 20, 6)->default(0);
            $table->decimal('subtotal_currency', 20, 6)->default(0);
            $table->decimal('discount_total_currency', 20, 6)->default(0);
            $table->decimal('tax_total_currency', 20, 6)->default(0);
            $table->decimal('grand_total_currency', 20, 6)->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->string('document_locale', 5)->default('ar');
            $table->json('customer_snapshot')->nullable();
            $table->json('company_snapshot')->nullable();
            $table->unsignedBigInteger('converted_to_invoice_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'quotation_number']);
            $table->index(['company_id', 'status', 'issue_date']);
            $table->index(['company_id', 'customer_id']);
        });

        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained('product_units')->nullOnDelete();
            $table->string('item_description', 500);
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_price', 20, 6);
            $table->string('discount_type', 16)->nullable(); // percent, fixed
            $table->decimal('discount_value', 20, 6)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            $table->decimal('tax_rate_snapshot', 12, 6)->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('line_subtotal', 20, 6)->default(0);
            $table->decimal('line_discount', 20, 6)->default(0);
            $table->decimal('line_tax', 20, 6)->default(0);
            $table->decimal('line_total', 20, 6)->default(0);
            $table->decimal('line_total_base', 20, 6)->default(0);
            $table->decimal('unit_conversion_ratio', 20, 6)->default(1);
            $table->decimal('quantity_base', 20, 6)->default(0);
            $table->string('unit_name_ar', 100)->nullable();
            $table->string('unit_name_en', 100)->nullable();
            $table->string('product_sku', 64)->nullable();
            $table->string('product_name_ar', 255)->nullable();
            $table->string('product_name_en', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'quotation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
    }
};
