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
        Schema::create('money_transfers', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('transfer_number', 64);
            $table->date('transfer_date');
            foreach (['from', 'to'] as $side) {
                $table->foreignId($side.'_money_account_id')->constrained('money_accounts')->restrictOnDelete();
                $table->foreignId($side.'_ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
                $table->char($side.'_currency_code', 3);
                $table->decimal($side.'_amount', 20, 6);
                $table->decimal($side.'_exchange_rate', 20, 10);
                $table->json($side.'_account_snapshot');
            }
            $table->char('base_currency_code', 3);
            $table->decimal('base_value_from', 20, 6);
            $table->decimal('base_value_to', 20, 6);
            $table->decimal('fx_gain_loss_base', 20, 6);
            $table->foreignId('posting_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('is_reversed')->default(false);
            $table->date('reversal_date')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reversal_posting_batch_id')->nullable()->constrained('posting_batches')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable();
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'transfer_number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'transfer_date']);
        });
    }

    public function down(): void
    {
        if (DB::table('money_transfers')->exists()) {
            throw new RuntimeException('Cannot remove Transfer provenance while financial history exists.');
        }
        Schema::dropIfExists('money_transfers');
    }
};
