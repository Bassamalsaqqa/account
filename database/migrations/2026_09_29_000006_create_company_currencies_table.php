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
        Schema::create('company_currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->char('currency_code', 3);
            $table->foreign('currency_code')->references('code')->on('currencies')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->boolean('is_base')->default(false);
            $table->unsignedSmallInteger('display_order')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'currency_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_currencies');
    }
};
