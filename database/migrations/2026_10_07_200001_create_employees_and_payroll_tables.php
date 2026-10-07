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
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 255);
            $table->string('phone', 64)->nullable();
            $table->string('job_title', 255)->nullable();
            $table->date('hire_date')->nullable();
            $table->decimal('default_salary', 20, 6)->default(0);
            $table->string('salary_currency_code', 3);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'active']);
        });

        Schema::create('employee_advances', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('advance_number', 32);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->json('employee_snapshot');
            $table->date('advance_date');

            $table->string('currency_code', 3);
            $table->decimal('amount', 20, 6);
            $table->decimal('exchange_rate', 20, 10);
            $table->decimal('base_amount', 20, 6);

            $table->string('payment_method', 16); // cash, bank, check
            $table->foreignId('money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('check_id')->nullable()->unique()->constrained('checks')->restrictOnDelete();

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

            $table->unique(['company_id', 'advance_number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'advance_date']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('salary_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('salary_number', 32);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->json('employee_snapshot');

            $table->date('recognition_date');
            $table->date('period_start');
            $table->date('period_end');

            $table->string('currency_code', 3);
            $table->decimal('exchange_rate', 20, 10);

            $table->decimal('base_salary', 20, 6);
            $table->decimal('bonus', 20, 6)->default(0);
            $table->decimal('deduction', 20, 6)->default(0);
            $table->decimal('earned_salary', 20, 6);
            $table->decimal('advance_applied', 20, 6)->default(0);
            $table->decimal('net_payable', 20, 6);

            $table->decimal('base_earned_salary', 20, 6);
            $table->decimal('base_advance_relief', 20, 6)->default(0);
            $table->decimal('base_payable', 20, 6);
            $table->decimal('realized_fx_gain_loss_base', 20, 6)->default(0);

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

            $table->unique(['company_id', 'salary_number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'recognition_date']);
            $table->index(['company_id', 'employee_id', 'period_start', 'period_end'], 'idx_sal_entries_emp_period');
            $table->index(['company_id', 'status']);
        });

        Schema::create('salary_advance_allocations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_entry_id')->constrained('salary_entries')->restrictOnDelete();
            $table->foreignId('employee_advance_id')->constrained('employee_advances')->restrictOnDelete();

            $table->decimal('allocated_amount', 20, 6);
            $table->decimal('advance_base_consumed', 20, 6);
            $table->decimal('salary_base_relief', 20, 6);
            $table->decimal('realized_fx_gain_loss_base', 20, 6);

            $table->string('status', 16)->default('active'); // active, reversed
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('reversed_at')->nullable();

            $table->index(['company_id', 'employee_advance_id', 'status'], 'idx_sal_adv_alloc_advance_status');
            $table->index(['company_id', 'salary_entry_id', 'status'], 'idx_sal_adv_alloc_entry_status');
        });

        Schema::create('salary_payments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('payment_number', 32);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->json('employee_snapshot');
            $table->date('payment_date');

            $table->string('currency_code', 3);
            $table->decimal('amount', 20, 6);
            $table->decimal('exchange_rate', 20, 10);
            $table->decimal('base_amount', 20, 6);
            $table->decimal('salary_book_relief_base', 20, 6);
            $table->decimal('realized_fx_gain_loss_base', 20, 6)->default(0);

            $table->string('payment_method', 16); // cash, bank, check
            $table->foreignId('money_account_id')->nullable()->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('check_id')->nullable()->unique()->constrained('checks')->restrictOnDelete();

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

            $table->unique(['company_id', 'payment_number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'payment_date']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('salary_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_payment_id')->constrained('salary_payments')->restrictOnDelete();
            $table->foreignId('salary_entry_id')->constrained('salary_entries')->restrictOnDelete();

            $table->decimal('allocated_amount', 20, 6);
            $table->decimal('salary_book_relief_base', 20, 6);
            $table->decimal('settlement_base', 20, 6);
            $table->decimal('realized_fx_gain_loss_base', 20, 6);

            $table->string('status', 16)->default('active'); // active, reversed
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('reversed_at')->nullable();

            $table->index(['company_id', 'salary_payment_id', 'status'], 'idx_sal_pay_alloc_payment_status');
            $table->index(['company_id', 'salary_entry_id', 'status'], 'idx_sal_pay_alloc_entry_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Phase7MigrationSafety::assertEmpty();
        if (
            (Schema::hasTable('employee_advances') && DB::table('employee_advances')->exists()) ||
            (Schema::hasTable('salary_entries') && DB::table('salary_entries')->exists()) ||
            (Schema::hasTable('salary_payments') && DB::table('salary_payments')->exists())
        ) {
            throw new RuntimeException('Cannot rollback Phase 7 migrations: payroll financial history exists.');
        }

        Schema::dropIfExists('salary_payment_allocations');
        Schema::dropIfExists('salary_payments');
        Schema::dropIfExists('salary_advance_allocations');
        Schema::dropIfExists('salary_entries');
        Schema::dropIfExists('employee_advances');
        Schema::dropIfExists('employees');
    }
};
