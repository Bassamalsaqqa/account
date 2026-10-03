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
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 64)->nullable();
            $table->string('name_ar', 255);
            $table->string('name_en', 255)->nullable();
            $table->string('business_name_ar', 255)->nullable();
            $table->string('business_name_en', 255)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('whatsapp', 64)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->text('address_ar')->nullable();
            $table->text('address_en')->nullable();
            $table->string('city_ar', 100)->nullable();
            $table->string('city_en', 100)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->char('country_code', 2)->default('PS');
            $table->string('preferred_locale', 5)->nullable();
            $table->char('default_currency_code', 3)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'name_ar']);
            $table->index(['company_id', 'phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
