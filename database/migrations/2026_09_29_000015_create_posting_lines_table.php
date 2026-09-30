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
        Schema::create('posting_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('posting_batch_id')->constrained('posting_batches')->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('description', 512)->nullable();
            $table->decimal('debit_base', 20, 6)->default('0.000000');
            $table->decimal('credit_base', 20, 6)->default('0.000000');
            $table->char('transaction_currency_code', 3)->nullable();
            $table->decimal('transaction_amount', 20, 6)->nullable();
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'ledger_account_id']);
            $table->index(['company_id', 'posting_batch_id']);
            $table->unique(['posting_batch_id', 'line_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posting_lines');
    }
};
