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
        Schema::create('bug_reports', function (Blueprint $table): void {
            $table->id();
            $table->char('public_ref', 26)->collation('ascii_bin')->unique();

            // Server-derived submitter, effective impersonated actor, and company scope (no cascade deletes).
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('effective_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->boolean('is_impersonated')->default(false);

            $table->string('source', 16)->default('web');
            $table->string('category', 32);
            $table->string('locale', 8)->default('ar');
            $table->string('route_name', 120)->nullable();
            $table->string('screen_path', 160)->nullable();
            $table->string('request_id', 64)->nullable()->collation('ascii_bin');
            $table->string('failed_request_id', 64)->nullable()->collation('ascii_bin');

            $table->string('release_version', 64)->nullable();
            $table->char('release_source_sha', 40)->nullable()->collation('ascii_bin');
            $table->char('asset_manifest_sha256', 64)->nullable()->collation('ascii_bin');

            $table->boolean('include_diagnostics')->default(true);
            $table->string('note', 1000);
            $table->json('sanitized_context')->nullable();

            $table->string('idempotency_key', 64)->collation('ascii_bin');
            $table->char('payload_fingerprint', 64)->collation('ascii_bin');

            $table->string('status', 24)->default('new');
            $table->foreignId('duplicate_of_id')->nullable()->constrained('bug_reports')->restrictOnDelete();
            $table->string('triage_note', 1000)->nullable();
            $table->char('fixed_release_sha', 40)->nullable()->collation('ascii_bin');
            $table->string('resolution_evidence', 1000)->nullable();
            $table->foreignId('triaged_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('triaged_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['reporter_user_id', 'idempotency_key'], 'bug_reports_submitter_idempotency_unique');
            $table->index(['status', 'created_at', 'id'], 'bug_reports_status_created_idx');
            $table->index(['company_id', 'created_at'], 'bug_reports_company_created_idx');
            $table->index(['request_id'], 'bug_reports_request_id_idx');
            $table->index(['failed_request_id'], 'bug_reports_failed_request_id_idx');
            $table->index(['release_source_sha', 'status'], 'bug_reports_release_status_idx');
            $table->index(['created_at', 'id'], 'bug_reports_created_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('bug_reports') && DB::table('bug_reports')->exists()) {
            throw new RuntimeException('Refusing to remove populated bug report history. Retain the forward schema during source rollback.');
        }

        Schema::dropIfExists('bug_reports');
    }
};
