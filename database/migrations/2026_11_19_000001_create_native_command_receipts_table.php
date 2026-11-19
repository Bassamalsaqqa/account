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
        Schema::create('native_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('command_type', 64);
            $table->string('idempotency_key', 128)->collation('ascii_bin');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->char('device_id', 36);
            $table->char('request_hash', 64)->collation('ascii_bin');
            $table->json('payload');
            $table->json('response_snapshot');
            $table->string('entity_type', 64)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_public_id', 64)->nullable();
            $table->string('status', 32)->default('completed');
            $table->timestamps();

            $table->unique(
                ['company_id', 'idempotency_key'],
                'native_command_receipts_company_idempotency_unique'
            );
            $table->index(['company_id', 'actor_id']);
            $table->index(['company_id', 'device_id']);
            $table->index(['company_id', 'command_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('native_command_receipts');
    }
};
