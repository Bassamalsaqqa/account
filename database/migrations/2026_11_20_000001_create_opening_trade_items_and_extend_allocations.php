<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('opening_trade_items', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('side', 20); // 'customer' or 'vendor'
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();
            $table->date('opening_date');
            $table->string('original_reference', 100)->nullable();
            $table->date('due_date')->nullable();
            $table->char('currency_code', 3);
            $table->decimal('amount_currency', 20, 6);
            $table->decimal('exchange_rate', 20, 10);
            $table->decimal('carrying_value_base', 20, 6);
            $table->json('party_snapshot');
            $table->json('company_snapshot');
            $table->json('control_route');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('source_identity', 50)->default('manual');
            $table->string('idempotency_key', 255);
            $table->char('idempotency_payload_hash', 64);
            $table->foreignId('posting_batch_id')->nullable()->constrained('posting_batches')->restrictOnDelete();
            $table->string('status', 20)->default('posted'); // 'posted' or 'reversed'
            $table->foreignId('reversal_posting_batch_id')->nullable()->constrained('posting_batches')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('correction_reason', 512)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'oti_company_idempotency_unique');
            $table->index(['company_id', 'side', 'opening_date'], 'oti_company_side_date_idx');
            $table->index(['company_id', 'customer_id', 'status'], 'oti_company_customer_status_idx');
            $table->index(['company_id', 'vendor_id', 'status'], 'oti_company_vendor_status_idx');
            $table->index(['company_id', 'posting_batch_id'], 'oti_company_batch_idx');
        });

        Schema::table('customer_payment_allocations', function (Blueprint $table): void {
            $table->foreignId('sales_invoice_id')->nullable()->change();
            $table->foreignId('opening_trade_item_id')->nullable()->after('sales_invoice_id')
                ->constrained('opening_trade_items')->restrictOnDelete();
            $table->index(['company_id', 'opening_trade_item_id'], 'cpa_opening_item_idx');
        });

        Schema::table('vendor_payment_allocations', function (Blueprint $table): void {
            $table->foreignId('purchase_id')->nullable()->change();
            $table->foreignId('opening_trade_item_id')->nullable()->after('purchase_id')
                ->constrained('opening_trade_items')->restrictOnDelete();
            $table->index(['company_id', 'opening_trade_item_id'], 'vpa_opening_item_idx');
        });
        DB::statement('ALTER TABLE customer_payment_allocations ADD CONSTRAINT cpa_exactly_one_target CHECK ((sales_invoice_id IS NULL) <> (opening_trade_item_id IS NULL))');
        DB::statement('ALTER TABLE vendor_payment_allocations ADD CONSTRAINT vpa_exactly_one_target CHECK ((purchase_id IS NULL) <> (opening_trade_item_id IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Refuse before any DDL: MariaDB DDL commits independently, so a late
        // failure cannot restore already dropped financial provenance.
        if (DB::table('opening_trade_items')->exists()
            || DB::table('customer_payment_allocations')->whereNotNull('opening_trade_item_id')->exists()
            || DB::table('vendor_payment_allocations')->whereNotNull('opening_trade_item_id')->exists()) {
            throw new RuntimeException('Cannot downgrade populated opening trade history. Retain this schema and use a forward correction.');
        }

        DB::statement('ALTER TABLE customer_payment_allocations DROP CONSTRAINT cpa_exactly_one_target');
        DB::statement('ALTER TABLE vendor_payment_allocations DROP CONSTRAINT vpa_exactly_one_target');

        Schema::table('vendor_payment_allocations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('opening_trade_item_id');
            $table->foreignId('purchase_id')->nullable(false)->change();
        });

        Schema::table('customer_payment_allocations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('opening_trade_item_id');
            $table->foreignId('sales_invoice_id')->nullable(false)->change();
        });

        Schema::dropIfExists('opening_trade_items');
    }
};
