<?php

declare(strict_types=1);

use App\Models\Company;
use App\Services\Tenancy\CompanyRoleService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_company_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->boolean('personal_credentials_enabled')->default(false);
            $table->boolean('follow_up_enabled')->default(false);
            $table->string('workflow_mode', 16)->default('seamless');
            $table->string('speech_method', 32)->default('cloud');
            $table->unsignedBigInteger('revision')->default(1);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_provider_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique()->collation('ascii_bin');
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('credential_source', 24);
            $table->string('provider', 32);
            $table->string('model', 80);
            $table->string('region', 32);
            $table->string('capability', 32);
            $table->text('secret_ciphertext');
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamp('revoked_at')->nullable();
            $table->string('verification_status', 32)->default('configured_unverified');
            $table->timestamps();
            $table->index(['company_id', 'owner_user_id'], 'ai_connection_ownership');
        });
        Schema::create('ai_user_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->json('preferences')->nullable();
            $table->json('consents')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id'], 'ai_preference_identity');
        });
        Schema::table('assistant_requests', function (Blueprint $table): void {
            $table->string('intent_kind', 16)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('region', 32)->nullable();
            $table->string('credential_source', 24)->nullable();
            $table->foreignId('connection_id')->nullable()->constrained('ai_provider_connections')->restrictOnDelete();
            $table->unsignedBigInteger('connection_revision')->nullable();
            $table->unsignedBigInteger('policy_revision')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedBigInteger('estimated_cost_micros')->nullable();
            $table->string('cost_status', 16)->default('unknown');
            $table->string('pricing_reference', 255)->nullable();
            $table->foreignId('purchase_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('form_key')->nullable()->collation('ascii_bin');
        });
        Schema::create('ai_purchase_draft_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('form_key')->collation('ascii_bin');
            $table->char('payload_hash', 64)->collation('ascii_bin');
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'user_id', 'form_key'], 'ai_purchase_draft_identity');
        });
        Company::query()->orderBy('id')->each(fn (Company $company) => app(CompanyRoleService::class)->upgradeSalesCatalog($company));
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_purchase_draft_receipts');
        Schema::table('assistant_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_id');
            $table->dropConstrainedForeignId('connection_id');
            $table->dropColumn(['intent_kind', 'model', 'region', 'credential_source', 'connection_revision',
                'policy_revision', 'attempt_count', 'estimated_cost_micros', 'cost_status', 'pricing_reference', 'form_key']);
        });
        Schema::dropIfExists('ai_user_preferences');
        Schema::dropIfExists('ai_provider_connections');
        Schema::dropIfExists('ai_company_policies');
        // Inert permissions remain to preserve explicit Owner delegation history.
    }
};
