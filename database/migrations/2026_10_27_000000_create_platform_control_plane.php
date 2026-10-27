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
        Schema::create('platform_operator_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('active')->default(false);
            $table->json('permissions');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });

        Schema::create('platform_provisioning_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operator_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->char('payload_fingerprint', 64);
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['operator_user_id', 'request_key'], 'platform_provision_actor_request_unique');
        });

        Schema::create('platform_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_key', 80);
            $table->json('metadata');
            $table->timestamp('created_at');
            $table->index(['created_at', 'id']);
        });

        Schema::create('platform_company_health', function (Blueprint $table): void {
            $table->foreignId('company_id')->primary()->constrained()->restrictOnDelete();
            $table->json('domains');
            $table->timestamp('checked_at');
        });
    }

    public function down(): void
    {
        foreach (['platform_company_health', 'platform_audit_events', 'platform_provisioning_requests', 'platform_operator_grants'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Refusing to remove populated platform access or audit history. Retain the additive schema during source rollback.');
            }
        }

        Schema::dropIfExists('platform_company_health');
        Schema::dropIfExists('platform_audit_events');
        Schema::dropIfExists('platform_provisioning_requests');
        Schema::dropIfExists('platform_operator_grants');
    }
};
