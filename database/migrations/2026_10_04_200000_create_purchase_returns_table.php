<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('return_number', 64)->nullable();
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->char('base_currency_code', 3);
            $table->decimal('exchange_rate', 20, 10);
            $table->date('return_date');
            $table->string('status', 32)->default('draft');
            foreach (['subtotal', 'discount_total', 'tax_total', 'grand_total'] as $amount) {
                $table->decimal($amount.'_currency', 20, 6)->default(0);
                $table->decimal($amount.'_base', 20, 6)->default(0);
            }
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('document_locale', 5)->default('ar');
            $table->json('vendor_snapshot')->nullable();
            $table->json('company_snapshot')->nullable();
            $table->foreignId('posting_batch_id')->nullable()->constrained('posting_batches')->restrictOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'return_number']);
            $table->index(['company_id', 'status', 'return_date']);
            $table->index(['company_id', 'purchase_id']);
            $table->index(['company_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
