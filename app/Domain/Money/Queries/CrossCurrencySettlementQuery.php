<?php

declare(strict_types=1);

namespace App\Domain\Money\Queries;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Read-only currency conversion legs. Payment principal occurs once; these reclassify its consumed credit. */
final class CrossCurrencySettlementQuery
{
    /** @param list<int> $partyIds
     * @return list<array<string, mixed>>
     */
    public function legs(int $companyId, array $partyIds, string $domain, bool $activeOnly = false): array
    {
        if (! in_array($domain, ['customer', 'vendor'], true)) {
            throw new InvalidArgumentException('Invalid settlement domain.');
        }
        $vendor = $domain === 'vendor';
        $documentTable = $vendor ? 'purchases' : 'sales_invoices';
        $documentKey = $vendor ? 'purchase_id' : 'sales_invoice_id';
        $partyKey = $domain.'_id';
        $paymentKey = $domain.'_payment_id';
        $query = DB::table($domain.'_payment_allocations as a')->join($domain.'_payments as p', 'p.id', '=', 'a.'.$paymentKey)
            ->join($documentTable.' as d', 'd.id', '=', 'a.'.$documentKey)
            ->leftJoin($domain.'_payment_application_events as e', 'e.id', '=', 'a.application_event_id')
            ->leftJoin('posting_batches as pr', 'pr.id', '=', 'p.reversal_posting_batch_id')
            ->leftJoin('posting_batches as er', 'er.id', '=', 'e.reversal_posting_batch_id')
            ->where('a.company_id', $companyId)->where('p.company_id', $companyId)->where('d.company_id', $companyId)
            ->whereColumn('d.'.$partyKey, 'p.'.$partyKey)->whereIn('p.'.$partyKey, $partyIds)
            ->whereNotNull('p.posting_batch_id')->whereColumn('d.currency_code', '!=', 'p.currency_code')
            ->where(fn ($q) => $q->whereNull('a.application_event_id')->orWhere(fn ($q) => $q->where('e.company_id', $companyId)->whereColumn('e.'.$paymentKey, 'p.id')->whereNotNull('e.applied_at')));
        if ($activeOnly) {
            $query->where('p.is_reversed', false)->where(fn ($q) => $q->whereNull('a.application_event_id')->orWhereNull('e.reversed_at'));
        }
        $rows = $query->orderBy('a.id')->get(['a.id', 'a.allocated_amount', 'a.payment_currency_amount', 'p.'.$partyKey.' as party_id',
            'p.currency_code as payment_currency', 'd.currency_code as document_currency', 'p.payment_number', 'p.payment_date', 'p.created_at', 'p.is_reversed',
            'e.application_date', 'e.reversed_at as event_reversed_at', 'pr.posting_date as payment_reversal_date', 'er.posting_date as event_reversal_date']);
        $legs = [];
        foreach ($rows as $row) {
            $date = $row->application_date ?? $row->payment_date;
            foreach ([[$row->payment_currency, BigDecimal::of($row->payment_currency_amount)], [$row->document_currency, BigDecimal::of($row->allocated_amount)->negated()]] as [$currency, $amount]) {
                $leg = ['id' => (int) $row->id, 'party_id' => (int) $row->party_id, 'date' => $date, 'currency' => $currency,
                    'number' => $row->payment_number, 'amount' => (string) $amount, 'created_at' => $row->created_at, 'reversal' => false];
                $legs[] = $leg;
                if (! $activeOnly && ($row->is_reversed || $row->event_reversed_at !== null)) {
                    $reversedDate = $row->event_reversal_date ?? $row->payment_reversal_date;
                    if ($reversedDate === null) {
                        throw new InvalidArgumentException('Cross-currency settlement reversal business date is missing.');
                    }
                    $legs[] = array_replace($leg, ['date' => $reversedDate, 'amount' => (string) $amount->negated(), 'reversal' => true]);
                }
            }
        }

        return $legs;
    }
}
