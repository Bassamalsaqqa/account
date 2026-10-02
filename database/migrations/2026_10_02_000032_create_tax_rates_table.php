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
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name_ar', 255);
            $table->string('name_en', 255)->nullable();
            $table->decimal('rate', 12, 6);
            $table->string('calculation', 16)->default('exclusive'); // exclusive, inclusive
            $table->boolean('active')->default(true);
            $table->foreignId('sales_tax_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('purchase_tax_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
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
        Schema::dropIfExists('tax_rates');
    }
};
