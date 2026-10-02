<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->json('company_snapshot');
            $table->json('customer_snapshot');
            $table->json('money_account_snapshot');
            $table->string('document_locale', 5)->default('ar');
        });
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->foreignId('sales_tax_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoice_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('sales_tax_account_id'));
        Schema::table('customer_payments', fn (Blueprint $table) => $table->dropColumn(['company_snapshot', 'customer_snapshot', 'money_account_snapshot', 'document_locale']));
    }
};
