<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_confirmation_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->uuid('source_public_id')->nullable();
            $table->uuid('request_key');
            $table->char('payload_digest', 64);
            $table->text('confirmation');
            $table->timestamp('created_at');
            $table->unique(['company_id', 'request_key'], 'purchase_confirmation_request_key');
        });
    }

    public function down(): void
    {
        if (DB::table('purchase_confirmation_receipts')->exists()) {
            throw new RuntimeException('Purchase confirmation provenance exists; retain this migration.');
        }
        Schema::dropIfExists('purchase_confirmation_receipts');
    }
};
