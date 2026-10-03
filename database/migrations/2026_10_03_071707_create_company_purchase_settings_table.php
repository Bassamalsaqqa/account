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
        Schema::create('company_purchase_settings', function (Blueprint $table) {
            $table->foreignId('company_id')->primary()->constrained('companies')->cascadeOnDelete();
            $table->unsignedInteger('default_payment_terms_days')->nullable();
            $table->foreignId('default_receiving_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->boolean('warn_duplicate_vendor_invoice')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_purchase_settings');
    }
};
