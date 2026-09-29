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
        Schema::create('company_inventory_settings', function (Blueprint $table) {
            $table->foreignId('company_id')->primary()->constrained('companies')->cascadeOnDelete();
            $table->boolean('allow_negative_stock')->default(false);
            $table->string('default_cost_method', 32)->default('moving_average');
            $table->unsignedInteger('default_expiry_warning_days')->default(30);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_inventory_settings');
    }
};
