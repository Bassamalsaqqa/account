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
        Schema::create('platform_subscriptions', function (Blueprint $table): void {
            $table->foreignId('company_id')->primary()->constrained()->restrictOnDelete();
            $table->string('plan', 100);
            $table->decimal('fee', 20, 6);
            $table->char('currency', 3);
            $table->date('starts_on');
            $table->date('renews_on');
            $table->unsignedSmallInteger('grace_days');
            $table->json('reminder_days');
            $table->boolean('enforcement_enabled')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('renews_on');
        });
        Schema::create('platform_subscription_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('subscription_revision');
            $table->string('plan', 100);
            $table->decimal('amount', 20, 6);
            $table->char('currency', 3);
            $table->date('starts_on');
            $table->date('ends_before');
            $table->timestamp('created_at');
            $table->unique(['id', 'company_id']);
        });
        Schema::create('platform_subscription_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('charge_id');
            $table->foreign(['charge_id', 'company_id'], 'platform_payment_charge_company_fk')
                ->references(['id', 'company_id'])->on('platform_subscription_charges')->restrictOnDelete();
            $table->decimal('amount', 20, 6);
            $table->char('currency', 3);
            $table->date('received_on');
            $table->string('reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('operator_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('platform_billing_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('operator_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('operation', 32);
            $table->char('payload_fingerprint', 64);
            // Whitelisted commercial inputs and before/after history; never credentials.
            $table->json('payload');
            $table->json('result');
            $table->timestamp('created_at');
            $table->unique(['operator_user_id', 'request_key'], 'platform_billing_operator_request_unique');
        });
    }

    public function down(): void
    {
        $tables = ['platform_billing_requests', 'platform_subscription_payments', 'platform_subscription_charges', 'platform_subscriptions'];
        foreach ($tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Retain populated commercial history during source rollback; never delete receipts to force a downgrade.');
            }
        }
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
