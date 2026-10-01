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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('sku', 128)->nullable();
            $table->string('name_ar', 255);
            $table->string('name_en', 255)->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('product_type', 32)->default('stock'); // stock, non_stock, service
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->boolean('track_stock')->default(true);
            $table->boolean('track_expiry')->default(false);
            $table->decimal('minimum_stock_base', 20, 6)->nullable();
            $table->decimal('default_purchase_cost_base', 20, 6)->nullable();
            $table->decimal('default_sale_price_base', 20, 6)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'sku']);
            $table->index(['company_id', 'active']);
            $table->index(['company_id', 'category_id']);
            $table->index(['company_id', 'product_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
