<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('purchase_number', 64)->nullable();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('vendor_invoice_number', 128)->nullable();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('draft');
            $table->date('purchase_date');
            $table->date('due_date')->nullable();
            $table->char('currency_code', 3);
            $table->char('base_currency_code', 3);
            $table->decimal('exchange_rate', 20, 10);
            $table->string('document_locale', 5);
            foreach (['subtotal', 'discount_total', 'tax_total', 'grand_total'] as $amount) {
                $table->decimal($amount.'_currency', 20, 6);
                $table->decimal($amount.'_base', 20, 6);
            }
            $table->text('notes')->nullable();
            $table->json('vendor_snapshot')->nullable();
            $table->json('company_snapshot')->nullable();
            $table->foreignId('posting_batch_id')->nullable()->constrained('posting_batches')->restrictOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('void_posting_batch_id')->nullable()->constrained('posting_batches')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'purchase_number']);
            $table->index(['company_id', 'status', 'purchase_date']);
            $table->index(['company_id', 'vendor_id', 'purchase_date']);
            $table->index(['company_id', 'due_date']);
            $table->index(['company_id', 'vendor_invoice_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
