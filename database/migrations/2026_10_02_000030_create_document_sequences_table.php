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
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('document_type', 32); // quotation, sales_invoice, sales_return, customer_payment
            $table->string('prefix', 32);
            $table->unsignedSmallInteger('year')->default(0);
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(4);
            $table->string('reset_policy', 16)->default('yearly'); // never, yearly
            $table->timestamps();

            // Store year or 0 in a composite unique index safe across MariaDB versions
            $table->unique(['company_id', 'document_type', 'year'], 'doc_sequences_company_type_year_unique');
            $table->index(['company_id', 'document_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
