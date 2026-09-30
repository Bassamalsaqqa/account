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
        Schema::create('posting_batches', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('batch_number', 64)->nullable();
            $table->date('posting_date');
            $table->string('status', 32)->default('posted'); // posted, reversed
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->char('transaction_currency_code', 3);
            $table->char('base_currency_code', 3);
            $table->decimal('exchange_rate', 20, 10);
            $table->string('description', 512)->nullable();
            $table->string('idempotency_key', 191);
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('posted_at');
            $table->foreignId('reversal_of_id')->nullable()->constrained('posting_batches')->nullOnDelete();
            $table->foreignId('reversed_by_batch_id')->nullable()->constrained('posting_batches')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'posting_date']);
            $table->index(['company_id', 'source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posting_batches');
    }
};
