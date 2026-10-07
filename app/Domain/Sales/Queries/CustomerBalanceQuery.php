<?php

declare(strict_types=1);

namespace App\Domain\Sales\Queries;

use App\Domain\Money\Queries\CrossCurrencySettlementQuery;
use App\Models\CustomerPayment;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;

final class CustomerBalanceQuery
{
    /**
     * Customer AR includes unallocated receipts and credit returns. Never aggregate different currencies.
     *
     * @param  list<int>  $customerIds
     * @return array<int, array<string, array{invoiced: string, outstanding: string}>>
     */
    public function execute(array $customerIds): array
    {
        $companyId = app(CompanyContext::class)->companyId();
        $result = [];
        $queries = [
            [SalesInvoice::where('status', SalesInvoice::STATUS_POSTED), 'grand_total_currency', true],
            [SalesReturn::where('status', SalesReturn::STATUS_POSTED), 'grand_total_currency', false],
            [CustomerPayment::where('is_reversed', false), 'amount', false],
        ];
        foreach ($queries as [$query, $column, $debit]) {
            $rows = $query->where('company_id', $companyId)->whereIn('customer_id', $customerIds)
                ->selectRaw("customer_id, currency_code, SUM({$column}) AS total")
                ->groupBy('customer_id', 'currency_code')->get();
            foreach ($rows as $row) {
                $id = (int) $row->getAttribute('customer_id');
                $currency = (string) $row->getAttribute('currency_code');
                $balance = $result[$id][$currency] ?? ['invoiced' => '0.000000', 'outstanding' => '0.000000'];
                $amount = BigDecimal::of((string) $row->getAttribute('total'));
                if ($debit) {
                    $balance['invoiced'] = (string) $amount;
                } else {
                    $amount = $amount->negated();
                }
                $balance['outstanding'] = (string) BigDecimal::of($balance['outstanding'])->plus($amount);
                $result[$id][$currency] = $balance;
            }
        }

        foreach (app(CrossCurrencySettlementQuery::class)->legs((int) $companyId, $customerIds, 'customer', true) as $leg) {
            $id = $leg['party_id'];
            $currency = $leg['currency'];
            $entry = $result[$id][$currency] ?? ['invoiced' => '0.000000', 'outstanding' => '0.000000'];
            $entry['outstanding'] = (string) BigDecimal::of($entry['outstanding'])->plus($leg['amount'])->toScale(6);
            $result[$id][$currency] = $entry;
        }

        return $result;
    }
}
