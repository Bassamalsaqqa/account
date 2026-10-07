<?php

declare(strict_types=1);

namespace App\Domain\Money\Queries;

use App\Models\CompanyCurrency;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Services\Money\MoneyActorGuard;
use App\Services\Purchasing\PurchasePayablePosition;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class SettlementTargetsQuery
{
    /** @return list<array<string, mixed>> */
    public function forParty(int $companyId, int $partyId, string $domain, string $operation = 'create'): array
    {
        if (! in_array($domain, ['vendor', 'customer'], true) || ! in_array($operation, ['create', 'allocate'], true)) {
            throw new \InvalidArgumentException('Unsupported settlement target query.');
        }
        $vendor = $domain === 'vendor';
        app(MoneyActorGuard::class)->authorize($companyId, ($vendor ? 'money.vendor_payment.' : 'money.receipt.').$operation);
        if ($vendor) {
            app(MoneyActorGuard::class)->authorize($companyId, 'purchasing.cost.view');
        }
        $enabled = CompanyCurrency::where('company_id', $companyId)->where('enabled', true)->pluck('currency_code')->all();
        $table = $vendor ? 'purchases' : 'sales_invoices';
        $key = $vendor ? 'purchase_id' : 'sales_invoice_id';
        $prefix = $vendor ? 'vendor' : 'customer';
        $returns = $vendor ? 'purchase_returns' : 'sales_returns';
        $query = $vendor ? Purchase::query() : SalesInvoice::query();
        // Filter open documents before LIMIT, so old settled documents cannot starve newer open targets.
        $documents = $query->where($table.'.company_id', $companyId)->where($prefix.'_id', $partyId)->where($table.'.status', 'posted')->whereIn($table.'.currency_code', $enabled)
            ->whereRaw("{$table}.grand_total_currency >
                COALESCE((SELECT SUM(r.grand_total_currency) FROM {$returns} r WHERE r.{$key} = {$table}.id AND r.company_id = ? AND r.status = 'posted'), 0)
                + COALESCE((SELECT SUM(a.allocated_amount) FROM {$prefix}_payment_allocations a
                    JOIN {$prefix}_payments p ON p.id = a.{$prefix}_payment_id
                    LEFT JOIN {$prefix}_payment_application_events e ON e.id = a.application_event_id
                    WHERE a.{$key} = {$table}.id AND a.company_id = ? AND p.company_id = ? AND p.is_reversed = 0
                        AND (a.application_event_id IS NULL OR (e.company_id = ? AND e.{$prefix}_payment_id = p.id AND e.applied_at IS NOT NULL AND e.reversed_at IS NULL))), 0)", [$companyId, $companyId, $companyId, $companyId])
            ->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderBy($vendor ? 'purchase_date' : 'issue_date')->orderBy($table.'.id')->limit(50)->get();
        $positions = $vendor ? PurchasePayablePosition::forPurchases($documents) : [];
        $paid = $vendor ? collect() : DB::table('customer_payment_allocations as a')->join('customer_payments as p', 'p.id', '=', 'a.customer_payment_id')
            ->leftJoin('customer_payment_application_events as e', 'e.id', '=', 'a.application_event_id')
            ->where('a.company_id', $companyId)->where('p.company_id', $companyId)->where('p.is_reversed', false)->whereIn('a.sales_invoice_id', $documents->modelKeys())
            ->where(fn ($q) => $q->whereNull('a.application_event_id')->orWhere(fn ($q) => $q->where('e.company_id', $companyId)->whereNotNull('e.applied_at')->whereNull('e.reversed_at')))
            ->selectRaw('a.sales_invoice_id, SUM(a.allocated_amount) AS total')->groupBy('a.sales_invoice_id')->pluck('total', 'sales_invoice_id');
        $returned = $vendor ? collect() : DB::table('sales_returns')->where('company_id', $companyId)->where('status', 'posted')->whereIn('sales_invoice_id', $documents->modelKeys())
            ->selectRaw('sales_invoice_id, SUM(grand_total_currency) AS total')->groupBy('sales_invoice_id')->pluck('total', 'sales_invoice_id');
        $rows = [];
        foreach ($documents as $document) {
            $outstanding = $vendor ? $positions[(int) $document->id]->outstanding : BigDecimal::of($document->grand_total_currency)->minus($paid->get($document->id, '0'))->minus($returned->get($document->id, '0'));
            if (! $outstanding->isPositive()) {
                continue;
            }
            $rows[] = ['id' => (int) $document->id, 'number' => $document->getAttribute($vendor ? 'purchase_number' : 'invoice_number'),
                'currency' => $document->currency_code, 'date' => Carbon::parse($document->getAttribute($vendor ? 'purchase_date' : 'issue_date'))->toDateString(),
                'outstanding' => (string) $outstanding->toScale(6), 'rate' => $document->exchange_rate];
        }

        return $rows;
    }
}
