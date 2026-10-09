<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('public_shares', 'access_profile')) {
            // Code rollback retains historical grants. A forward rerun must verify the complete retained schema.
            foreach (['content_version', 'encrypted_snapshot', 'content_hash', 'subject_revision', 'request_key', 'request_hash', 'issued_at'] as $column) {
                if (! Schema::hasColumn('public_shares', $column)) {
                    throw new RuntimeException('Incomplete retained public-share schema requires repair.');
                }
            }
            if (! Schema::hasIndex('public_shares', 'public_share_company_request_unique')) {
                throw new RuntimeException('Retained public-share idempotency constraint is missing.');
            }

            return;
        }
        Schema::table('public_shares', function (Blueprint $table): void {
            $table->string('access_profile', 32)->nullable(); // null identifies genuine legacy grants
            $table->unsignedSmallInteger('content_version')->nullable();
            $table->mediumText('encrypted_snapshot')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->char('subject_revision', 64)->nullable();
            $table->string('request_key', 100)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->unique(['company_id', 'request_key'], 'public_share_company_request_unique');
        });
    }

    public function down(): void
    {
        // Forward-only: intentionally retain issued grants and encrypted historical content.
    }
};
