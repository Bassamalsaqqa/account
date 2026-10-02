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
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('payment_number', 64);
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('money_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->date('payment_date');
            $table->string('payment_method', 32)->default('cash'); // cash, bank_transfer
            $table->char('currency_code', 3);
            $table->decimal('amount', 20, 6);
            $table->decimal('exchange_rate', 20, 10);
            $table->decimal('amount_base', 20, 6);
            $table->string('reference_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('posting_batch_id')->nullable()->constrained('posting_batches')->restrictOnDelete();
            $table->boolean('is_reversed')->default(false);
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable();
            $table->foreignId('reversal_posting_batch_id')->nullable()->constrained('posting_batches')->restrictOnDelete();
            $table->string('idempotency_key', 128)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'payment_number']);
            $table->unique(['company_id', 'idempotency_key'], 'cp_company_idempotency_unique');
            $table->index(['company_id', 'customer_id', 'payment_date']);
            $table->index(['company_id', 'money_account_id']);
        });

        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_payment_id')->constrained('customer_payments')->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->decimal('allocated_amount', 20, 6);
            $table->decimal('invoice_exchange_rate', 20, 10);
            $table->decimal('payment_exchange_rate', 20, 10);
            $table->decimal('base_amount_applied_to_receivable', 20, 6);
            $table->decimal('settlement_base_value', 20, 6);
            $table->decimal('realized_fx_gain_loss_base', 20, 6)->default(0);
            $table->timestamps();

            $table->index(['company_id', 'customer_payment_id'], 'cpa_payment_idx');
            $table->index(['company_id', 'sales_invoice_id'], 'cpa_invoice_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_payment_allocations');
        Schema::dropIfExists('customer_payments');
    }
};
