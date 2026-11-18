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
        Schema::create('mobile_shift_closures', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained('money_accounts')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->char('device_id', 36)->nullable();
            $table->date('shift_date');
            $table->char('currency_code', 3);
            $table->unsignedTinyInteger('currency_minor_units')->default(2);
            $table->decimal('opening_cash', 20, 6)->nullable();
            $table->decimal('expected_cash', 20, 6);
            $table->decimal('counted_cash', 20, 6);
            $table->decimal('variance', 20, 6);
            $table->string('variance_status', 16);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->text('notes')->nullable();
            $table->json('actor_snapshot');
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->unique(
                ['company_id', 'idempotency_key'],
                'mobile_shift_closures_company_idempotency_unique'
            );
            $table->index(['company_id', 'shift_date']);
            $table->index(['company_id', 'cash_account_id']);
            $table->index(['company_id', 'actor_id']);
            $table->index(['company_id', 'device_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mobile_shift_closures');
    }
};
