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
        Schema::create('company_document_settings', function (Blueprint $table) {
            $table->foreignId('company_id')->primary()->constrained('companies')->cascadeOnDelete();
            $table->string('default_document_locale', 5)->default('ar');
            $table->boolean('show_logo')->default(true);
            $table->boolean('show_qr_by_default')->default(false);
            $table->boolean('show_product_images_on_quotes')->default(false);
            $table->text('invoice_footer_ar')->nullable();
            $table->text('invoice_footer_en')->nullable();
            $table->text('quotation_terms_ar')->nullable();
            $table->text('quotation_terms_en')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_document_settings');
    }
};
