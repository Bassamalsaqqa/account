<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key')->collation('ascii_bin');
            $table->string('purpose', 24);
            $table->char('input_hash', 64)->collation('ascii_bin');
            $table->string('provider', 32);
            $table->string('status', 24);
            $table->unsignedBigInteger('reserved_cost_micros');
            $table->unsignedBigInteger('reported_cost_micros')->nullable();
            $table->unsignedInteger('input_units')->nullable();
            $table->unsignedInteger('output_units')->nullable();
            $table->unsignedInteger('audio_seconds')->nullable();
            $table->char('resolved_hash', 64)->nullable();
            $table->char('approved_hash', 64)->nullable();
            $table->text('approved_command')->nullable(); // Encrypted canonical approved fields; no raw input/audio.
            $table->uuid('command_key')->nullable();
            $table->foreignId('sales_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('failure_code', 32)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id', 'request_key'], 'assistant_request_identity');
            $table->index(['company_id', 'created_at'], 'assistant_company_usage');
            $table->index(['company_id', 'user_id', 'created_at'], 'assistant_actor_usage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_requests');
    }
};
