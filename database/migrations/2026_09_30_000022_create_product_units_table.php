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
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('conversion_to_base', 20, 6);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_default_purchase')->default(false);
            $table->boolean('is_default_sale')->default(false);
            $table->decimal('default_purchase_price_base', 20, 6)->nullable();
            $table->decimal('default_sale_price_base', 20, 6)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
            $table->index(['company_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};
