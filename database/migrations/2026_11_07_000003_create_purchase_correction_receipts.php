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
        Schema::create('purchase_correction_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('replacement_id')->nullable()->constrained('purchases')->restrictOnDelete();
            $table->uuid('request_key');
            $table->char('payload_digest', 64);
            $table->string('mode', 6);
            $table->text('reason');
            $table->date('business_date');
            $table->timestamp('created_at');
            $table->unique(['company_id', 'request_key']);
            $table->unique(['company_id', 'purchase_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('purchase_correction_receipts')->exists()) {
            throw new RuntimeException('Correction provenance must be retained.');
        }
        Schema::dropIfExists('purchase_correction_receipts');
    }
};
