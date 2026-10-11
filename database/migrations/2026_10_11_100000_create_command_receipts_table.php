<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 64);
            $table->string('idempotency_key', 128)->collation('ascii_bin');
            $table->foreignId('target_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->char('request_hash', 64);
            $table->timestamps();

            $table->unique(
                ['company_id', 'user_id', 'action', 'idempotency_key'],
                'command_receipts_tenant_actor_action_key_unique'
            );
            $table->index(['company_id', 'action']);
            $table->index(['company_id', 'sales_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_receipts');
    }
};
