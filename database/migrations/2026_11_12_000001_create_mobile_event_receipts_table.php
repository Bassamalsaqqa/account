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
        Schema::create('mobile_event_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('client_event_id', 64)->collation('ascii_bin');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->char('device_id', 36);
            $table->foreign('device_id')->references('device_id')->on('mobile_devices')->restrictOnDelete();
            $table->unsignedSmallInteger('event_version');
            $table->string('representation', 64);
            $table->char('payload_digest', 64);
            $table->longText('original_canonical_bytes')->nullable();
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->string('sales_invoice_public_id', 32)->nullable();
            $table->foreignId('customer_payment_id')->nullable()->constrained('customer_payments')->nullOnDelete();
            $table->string('customer_payment_public_id', 32)->nullable();
            $table->foreignId('command_receipt_id')->nullable()->constrained('command_receipts')->nullOnDelete();
            $table->string('status', 32)->default('completed');
            $table->json('terminal_canonical_result')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'client_event_id'],
                'mobile_event_receipts_company_client_event_unique'
            );

            $table->index(['company_id', 'actor_id']);
            $table->index(['company_id', 'device_id']);
            $table->index(['company_id', 'sales_invoice_id']);
            $table->index(['company_id', 'customer_payment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mobile_event_receipts');
    }
};
