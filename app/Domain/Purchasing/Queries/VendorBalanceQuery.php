<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Queries;

use App\Domain\Money\Queries\CrossCurrencySettlementQuery;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\VendorPayment;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;

final class VendorBalanceQuery
{
    /**
     * Vendor AP per vendor and currency independently:
     * Posted Purchases - Posted Returns - active Payments.
     * Positive = payable, zero = settled, negative = credit/advance.
     * Never aggregate different currencies together.
     *
     * @param  list<int>  $vendorIds
     * @return array<int, array<string, array{purchased: string, returned: string, paid: string, balance: string}>>
     */
    public function execute(array $vendorIds): array
    {
        $context = app(CompanyContext::class);
        $companyId = $context->companyId();
        $result = [];

        if (empty($vendorIds)) {
            return [];
        }

        // 1. Posted Purchases
        $purchaseRows = Purchase::where('company_id', $companyId)
            ->whereIn('vendor_id', $vendorIds)
            ->where('status', Purchase::STATUS_POSTED)
            ->selectRaw('vendor_id, currency_code, SUM(grand_total_currency) AS total')
            ->groupBy('vendor_id', 'currency_code')
            ->get();

        foreach ($purchaseRows as $row) {
            $id = (int) $row->getAttribute('vendor_id');
            $currency = (string) $row->getAttribute('currency_code');
            $entry = $result[$id][$currency] ?? $this->emptyEntry();
            $entry['purchased'] = (string) BigDecimal::of((string) $row->getAttribute('total'))->toScale(6);
            $entry['balance'] = (string) BigDecimal::of($entry['balance'])->plus($entry['purchased'])->toScale(6);
            $result[$id][$currency] = $entry;
        }

        // 2. Posted Returns
        $returnRows = PurchaseReturn::where('company_id', $companyId)
            ->whereIn('vendor_id', $vendorIds)
            ->where('status', Purchase::STATUS_POSTED)
            ->whereNotNull('posting_batch_id')
            ->selectRaw('vendor_id, currency_code, SUM(grand_total_currency) AS total')
            ->groupBy('vendor_id', 'currency_code')
            ->get();

        foreach ($returnRows as $row) {
            $id = (int) $row->getAttribute('vendor_id');
            $currency = (string) $row->getAttribute('currency_code');
            $entry = $result[$id][$currency] ?? $this->emptyEntry();
            $entry['returned'] = (string) BigDecimal::of((string) $row->getAttribute('total'))->toScale(6);
            $entry['balance'] = (string) BigDecimal::of($entry['balance'])->minus($entry['returned'])->toScale(6);
            $result[$id][$currency] = $entry;
        }

        // 3. Active (unreversed) Vendor Payments
        $paymentRows = VendorPayment::where('company_id', $companyId)
            ->whereIn('vendor_id', $vendorIds)
            ->where('is_reversed', false)
            ->whereNotNull('posting_batch_id')
            ->selectRaw('vendor_id, currency_code, SUM(amount) AS total')
            ->groupBy('vendor_id', 'currency_code')
            ->get();

        foreach ($paymentRows as $row) {
            $id = (int) $row->getAttribute('vendor_id');
            $currency = (string) $row->getAttribute('currency_code');
            $entry = $result[$id][$currency] ?? $this->emptyEntry();
            $entry['paid'] = (string) BigDecimal::of((string) $row->getAttribute('total'))->toScale(6);
            $entry['balance'] = (string) BigDecimal::of($entry['balance'])->minus($entry['paid'])->toScale(6);
            $result[$id][$currency] = $entry;
        }

        foreach (app(CrossCurrencySettlementQuery::class)->legs((int) $companyId, $vendorIds, 'vendor', true) as $leg) {
            $id = $leg['party_id'];
            $currency = $leg['currency'];
            $entry = $result[$id][$currency] ?? $this->emptyEntry();
            $entry['balance'] = (string) BigDecimal::of($entry['balance'])->plus($leg['amount'])->toScale(6);
            $result[$id][$currency] = $entry;
        }

        return $result;
    }

    /**
     * @return array{purchased: string, returned: string, paid: string, balance: string}
     */
    private function emptyEntry(): array
    {
        return [
            'purchased' => '0.000000',
            'returned' => '0.000000',
            'paid' => '0.000000',
            'balance' => '0.000000',
        ];
    }
}
