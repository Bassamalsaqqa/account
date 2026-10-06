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
        Schema::create('vendor_payments', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('payment_number', 64);
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('money_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->date('payment_date');
            $table->string('payment_method', 32)->default('cash'); // cash, bank_transfer
            $table->char('currency_code', 3);
            $table->char('base_currency_code', 3);
            $table->decimal('amount', 20, 6);
            $table->decimal('exchange_rate', 20, 10);
            $table->decimal('amount_base', 20, 6);
            $table->string('reference_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('document_locale', 10)->default('ar');
            $table->json('vendor_snapshot');
            $table->json('company_snapshot');
            $table->foreignId('posting_batch_id')->nullable()->constrained('posting_batches')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
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
            $table->unique(['company_id', 'idempotency_key'], 'vp_company_idempotency_unique');
            $table->index(['company_id', 'vendor_id', 'payment_date']);
            $table->index(['company_id', 'money_account_id']);
        });

        Schema::create('vendor_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('vendor_payment_id')->constrained('vendor_payments')->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->decimal('allocated_amount', 20, 6);
            $table->decimal('purchase_exchange_rate', 20, 10);
            $table->decimal('payment_exchange_rate', 20, 10);
            $table->decimal('base_amount_applied_to_payable', 20, 6);
            $table->decimal('settlement_base_value', 20, 6);
            $table->decimal('realized_fx_gain_loss_base', 20, 6)->default(0); // Signed: S - B (positive loss, negative gain)
            $table->timestamps();

            $table->index(['company_id', 'vendor_payment_id'], 'vpa_payment_idx');
            $table->index(['company_id', 'purchase_id'], 'vpa_purchase_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_payment_allocations');
        Schema::dropIfExists('vendor_payments');
    }
};
