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
        Schema::create('vendor_payment_application_events', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('vendor_payment_id')->constrained('vendor_payments')->restrictOnDelete();
            $table->date('application_date');
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->foreignId('posting_batch_id')->nullable()->constrained('posting_batches')->restrictOnDelete();
            $table->foreignId('reversal_posting_batch_id')->nullable()->constrained('posting_batches', indexName: 'vpae_reversal_fk')->restrictOnDelete();
            $table->foreignId('applied_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('applied_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'idempotency_key'], 'vpae_company_key_unique');
            $table->index(['company_id', 'vendor_payment_id'], 'vpae_payment_idx');
        });

        Schema::table('vendor_payment_allocations', function (Blueprint $table): void {
            $table->foreignId('application_event_id')->nullable()->constrained('vendor_payment_application_events')->restrictOnDelete();
            $table->unsignedBigInteger('prior_posting_batch_id')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_payment_allocations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('application_event_id');
            $table->dropColumn('prior_posting_batch_id');
        });

        Schema::dropIfExists('vendor_payment_application_events');
    }
};
