<?php

declare(strict_types=1);

use App\Services\Phase7\Phase7MigrationSafety;
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
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name_ar', 255);
            $table->string('name_en', 255)->nullable();
            $table->foreignId('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'active']);
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('expense_number', 32);
            $table->date('expense_date');

            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->json('category_snapshot');

            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();
            $table->json('vendor_snapshot')->nullable();
            $table->string('payee_name', 255)->nullable();

            $table->string('classification', 32); // operating, landed_cost
            $table->text('description');
            $table->string('currency_code', 3);
            $table->decimal('amount', 20, 6);
            $table->decimal('exchange_rate', 20, 10);
            $table->decimal('base_amount', 20, 6);

            $table->string('payment_method', 16); // cash, bank, check
            $table->foreignId('money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('check_id')->nullable()->unique()->constrained('checks')->restrictOnDelete();

            $table->string('attachment_path', 512)->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->string('attachment_mime', 128)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('posting_batch_id')->nullable()->constrained('posting_batches')->restrictOnDelete();
            $table->string('status', 16); // posted, reversed
            $table->timestamp('posted_at');
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();

            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_posting_batch_id')->nullable()->constrained('posting_batches')->nullOnDelete();
            $table->string('reversal_reason', 500)->nullable();

            $table->char('request_hash', 64);
            $table->string('idempotency_key', 128);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'expense_number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'expense_date']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'classification']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Phase7MigrationSafety::assertEmpty();
        if (Schema::hasTable('expenses') && DB::table('expenses')->exists()) {
            throw new RuntimeException('Cannot rollback Phase 7 migrations: expenses financial history exists.');
        }

        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
