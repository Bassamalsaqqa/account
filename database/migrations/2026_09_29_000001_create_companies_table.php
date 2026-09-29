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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name_ar', 255);
            $table->string('name_en', 255)->nullable();
            $table->string('legal_name_ar', 255)->nullable();
            $table->string('legal_name_en', 255)->nullable();
            $table->char('base_currency_code', 3)->default('ILS');
            $table->string('default_locale', 5)->default('ar');
            $table->string('timezone', 64)->default('Asia/Hebron');
            $table->string('phone', 64)->nullable();
            $table->string('whatsapp', 64)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->text('address_ar')->nullable();
            $table->text('address_en')->nullable();
            $table->string('registration_number', 128)->nullable();
            $table->string('tax_number', 128)->nullable();
            $table->string('logo_path', 512)->nullable();
            $table->string('stamp_path', 512)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
