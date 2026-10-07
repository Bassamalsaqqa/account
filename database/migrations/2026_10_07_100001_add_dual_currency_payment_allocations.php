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
        // Phase 4/5E only allowed equal currencies. Refuse to reinterpret corrupt legacy data.
        foreach ([['customer', 'sales_invoices', 'sales_invoice_id'], ['vendor', 'purchases', 'purchase_id']] as [$domain, $documents, $documentId]) {
            if (DB::table($domain.'_payment_allocations as a')->join($domain.'_payments as p', 'p.id', '=', 'a.'.$domain.'_payment_id')
                ->join($documents.' as d', 'd.id', '=', 'a.'.$documentId)
                ->where(function ($q) {
                    $q->whereColumn('p.currency_code', '!=', 'd.currency_code')->orWhereColumn('a.company_id', '!=', 'p.company_id')->orWhereColumn('d.company_id', '!=', 'p.company_id');
                })->exists()) {
                throw new RuntimeException('Legacy allocation currency/company invariant is corrupt; dual-currency migration stopped.');
            }
        }
        foreach (['customer', 'vendor'] as $domain) {
            Schema::table($domain.'_payment_allocations', function (Blueprint $table): void {
                $table->decimal('payment_currency_amount', 20, 6)->default(0);
            });
            DB::table($domain.'_payment_allocations')->update(['payment_currency_amount' => DB::raw('allocated_amount')]);
            foreach ([$domain.'_payments', $domain.'_payment_application_events'] as $tableName) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->unsignedTinyInteger('allocation_version')->default(1);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['customer', 'vendor'] as $domain) {
            // Disposable rehearsal only; refuse lossy rollback if Phase 6 cross-currency history exists.
            if (DB::table($domain.'_payment_allocations')->whereColumn('payment_currency_amount', '!=', 'allocated_amount')->exists()
                || DB::table($domain.'_payments')->where('allocation_version', '!=', 1)->exists()
                || DB::table($domain.'_payment_application_events')->where('allocation_version', '!=', 1)->exists()) {
                throw new RuntimeException('Cannot remove dual-currency provenance after Phase 6 business use.');
            }
        }
        foreach (['customer', 'vendor'] as $domain) {
            Schema::table($domain.'_payment_allocations', fn (Blueprint $table) => $table->dropColumn('payment_currency_amount'));
            foreach ([$domain.'_payments', $domain.'_payment_application_events'] as $tableName) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('allocation_version'));
            }
        }
    }
};
