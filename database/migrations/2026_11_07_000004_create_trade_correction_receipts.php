<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCES = ['sales_invoice' => 'sales_invoices', 'sales_return' => 'sales_returns', 'purchase_return' => 'purchase_returns'];

    public function up(): void
    {
        foreach (self::SOURCES as $family => $source) {
            Schema::create($family.'_correction_receipts', function (Blueprint $table) use ($source, $family): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->restrictOnDelete();
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('source_id')->constrained($source)->restrictOnDelete();
                $table->foreignId('replacement_id')->nullable()->constrained($source)->restrictOnDelete();
                $table->uuid('request_key');
                $table->char('payload_digest', 64);
                $table->string('mode', 16);
                $table->string('reason', 500);
                $table->date('business_date');
                $table->timestamp('created_at');
                $table->unique(['company_id', 'request_key'], $family.'_correction_key');
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::SOURCES) as $family) {
            if (DB::table($family.'_correction_receipts')->exists()) {
                throw new RuntimeException('Correction provenance exists; retain this migration.');
            }
        }
        foreach (array_reverse(array_keys(self::SOURCES)) as $family) {
            Schema::drop($family.'_correction_receipts');
        }
    }
};
