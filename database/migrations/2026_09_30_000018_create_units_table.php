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
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name_ar', 128);
            $table->string('name_en', 128)->nullable();
            $table->string('symbol_ar', 32)->nullable();
            $table->string('symbol_en', 32)->nullable();
            $table->boolean('allows_fraction')->default(false);
            $table->unsignedTinyInteger('decimal_places')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
