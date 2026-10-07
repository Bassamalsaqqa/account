<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('direction', 10);
            $table->string('check_number', 100);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('drawn_money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->string('bank_name', 200);
            $table->string('drawer', 200)->nullable();
            $table->string('currency_code', 3);
            $table->string('base_currency_code', 3);
            $table->decimal('amount', 20, 6);
            $table->decimal('exchange_rate', 20, 10);
            $table->decimal('amount_base', 20, 6);
            $table->date('received_issued_date');
            $table->date('due_date');
            $table->string('status', 20);
            $table->json('party_snapshot');
            $table->json('bank_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->json('request_payload');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'direction', 'status', 'due_date']);
        });
        Schema::create('check_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('check_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 20);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->date('event_date');
            $table->foreignId('money_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->decimal('settlement_base', 20, 6)->nullable();
            $table->decimal('fx_gain_loss_base', 20, 6)->nullable();
            $table->foreignId('posting_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_posting_batch_id')->nullable()->constrained('posting_batches')->restrictOnDelete();
            $table->foreignId('payment_reversal_posting_batch_id')->nullable()->constrained('posting_batches')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->json('request_payload');
            $table->text('notes')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'check_id', 'id']);
        });
        foreach (['customer_payments', 'vendor_payments'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('money_account_id')->nullable()->change();
                $table->foreignId('check_id')->nullable()->unique()->constrained('checks')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('checks')->exists()) {
            throw new RuntimeException('Cannot remove Check provenance while instruments exist.');
        }
        foreach (['customer_payments', 'vendor_payments'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('check_id');
                $table->foreignId('money_account_id')->nullable(false)->change();
            });
        }
        Schema::dropIfExists('check_events');
        Schema::dropIfExists('checks');
    }
};
