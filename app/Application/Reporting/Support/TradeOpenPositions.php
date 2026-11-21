<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportFilters;
use App\Models\Company;
use App\Models\OpeningTradeItem;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Services\Money\ReceivablePositionAsOf;
use App\Services\Purchasing\PurchasePayableAsOf;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

final class TradeOpenPositions
{
    /** @return array{rows:list<array<string,mixed>>,totals:array<string,mixed>,count:int} */
    public static function read(Company $company, ReportFilters $filters, bool $vendor = false, bool $aging = false, bool $overdue = false): array
    {
        $party = $vendor ? 'vendor' : 'customer';
        $dateField = $vendor ? 'purchase_date' : 'issue_date';
        $cutoff = $filters->period->endDate;
        $asOf = Carbon::parse($cutoff, (string) $company->timezone)->startOfDay();
        $query = ($vendor ? Purchase::query() : SalesInvoice::query())->where('company_id', $company->id)->whereNotNull('posting_batch_id')->where($dateField, '<=', $cutoff);
        $query->where(fn ($q) => $q->where('status', 'posted')->orWhere(function ($q) use ($vendor, $company, $cutoff): void {
            $q->where('status', 'void');
            if ($vendor) {
                $q->where('void_business_date', '>', $cutoff);
            } else {
                $q->whereHas('voidPostingBatch', fn ($batch) => $batch->where('company_id', $company->id)->where('posting_date', '>', $cutoff));
            }
        }));
        $partyId = $vendor ? $filters->vendorId : $filters->customerId;
        if ($partyId !== null) {
            $query->where($party.'_id', $partyId);
        }
        if ($filters->warehouseId !== null) {
            $query->where('warehouse_id', $filters->warehouseId);
        }
        if ($filters->currencyCode !== null) {
            $query->where('currency_code', $filters->currencyCode);
        }
        if ($overdue) {
            $query->whereNotNull('due_date')->where('due_date', '<', $cutoff);
        }
        $sort = $filters->sort ?? ($overdue ? 'overdue_desc' : 'outstanding_desc');
        $pager = new TradePageAccumulator($filters, static function (array $a, array $b) use ($sort, $aging, $party, $dateField, $vendor): int {
            if ($aging) {
                return [$a[$party.'_id'], $a['currency_code']] <=> [$b[$party.'_id'], $b['currency_code']];
            }
            // Amount ranking is only within a currency, never a USD-vs-ILS price claim.
            $currency = $a['currency_code'] <=> $b['currency_code'];
            if ($currency !== 0) {
                return $currency;
            }
            $primary = match ($sort) {
                'due_date_asc' => ($a['due_date'] ?? '9999-12-31') <=> ($b['due_date'] ?? '9999-12-31'),
                'issue_date_desc','purchase_date_desc' => $b[$dateField] <=> $a[$dateField],
                'overdue_desc' => $b['days_overdue'] <=> $a['days_overdue'],
                default => BigDecimal::of($b['outstanding_currency'])->compareTo(BigDecimal::of($a['outstanding_currency'])),
            };

            return $primary !== 0 ? $primary : (($a[$vendor ? 'purchase_id' : 'invoice_id'] ?? $a['opening_trade_item_id'] ?? 0) <=> ($b[$vendor ? 'purchase_id' : 'invoice_id'] ?? $b['opening_trade_item_id'] ?? 0));
        });
        $totals = [];
        $agingGroups = [];
        $partiesWithBalance = [];

        // 1. Process standard trade documents (Invoices or Purchases)
        $query->orderBy($party.'_id')->orderBy('currency_code')->orderBy('id')->chunk(200,
            function (Collection $documents) use ($vendor, $aging, $party, $dateField, $cutoff, $asOf, $company, $pager, &$totals, &$agingGroups, &$partiesWithBalance): void {
                $positions = $vendor ? app(PurchasePayableAsOf::class)->forHistory($documents, $asOf) : app(ReceivablePositionAsOf::class)->forInvoices($documents, $cutoff);
                foreach ($documents as $document) {
                    $value = $positions[(int) $document->id] ?? BigDecimal::zero();
                    if (! $value->isPositive()) {
                        continue;
                    }
                    $partyIdVal = (int) $document->getAttribute($party.'_id');
                    $partiesWithBalance[$partyIdVal] = true;
                    $currency = (string) $document->currency_code;
                    $due = $document->due_date ? Carbon::parse($document->due_date, (string) $company->timezone)->toDateString() : null;
                    $days = $due === null ? 0 : max(0, (int) Carbon::parse($due, (string) $company->timezone)->diffInDays($asOf, false));
                    $snapshot = $document->getAttribute($party.'_snapshot') ?? [];
                    $identity = [$party.'_id' => (int) $document->getAttribute($party.'_id'),
                        $party.'_name_ar' => (string) ($snapshot['name_ar'] ?? $snapshot['name_en'] ?? ''),
                        $party.'_name_en' => (string) ($snapshot['name_en'] ?? $snapshot['name_ar'] ?? ''), 'currency_code' => $currency];
                    if (! $aging) {
                        $totals[$currency] = ($totals[$currency] ?? BigDecimal::zero())->plus($value);
                        $pager->add($identity + [
                            ($vendor ? 'purchase_id' : 'invoice_id') => (int) $document->id,
                            'opening_trade_item_id' => null,
                            ($vendor ? 'purchase_number' : 'invoice_number') => (string) $document->getAttribute($vendor ? 'purchase_number' : 'invoice_number'),
                            $dateField => Carbon::parse($document->getAttribute($dateField))->toDateString(),
                            'due_date' => $due, 'days_overdue' => $days, 'grand_total_currency' => (string) BigDecimal::of($document->grand_total_currency)->toScale(6),
                            'outstanding_currency' => (string) $value->toScale(6)]);

                        continue;
                    }
                    $key = $identity[$party.'_id'].'/'.$currency;
                    $totalKey = $vendor ? 'gross_open' : 'total_outstanding';
                    if (! isset($agingGroups[$key])) {
                        $agingGroups[$key] = $identity + array_fill_keys(['unspecified', 'current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus', $totalKey], '0.000000');
                    }
                    $bucket = $due === null ? 'unspecified' : ($due >= $cutoff ? 'current' : ($days <= 30 ? 'days_1_30' : ($days <= 60 ? 'days_31_60' : ($days <= 90 ? 'days_61_90' : 'days_90_plus'))));
                    // Document identity remains a persisted snapshot. Latest date/id wins within each group.
                    $stamp = Carbon::parse($document->getAttribute($dateField))->toDateString().'/'.str_pad((string) $document->id, 20, '0', STR_PAD_LEFT);
                    if (! isset($agingGroups[$key]['_identity_stamp']) || $stamp > $agingGroups[$key]['_identity_stamp']) {
                        $agingGroups[$key][$party.'_name_ar'] = $identity[$party.'_name_ar'];
                        $agingGroups[$key][$party.'_name_en'] = $identity[$party.'_name_en'];
                        $agingGroups[$key]['_identity_stamp'] = $stamp;
                    }
                    foreach ([$bucket, $totalKey] as $field) {
                        $agingGroups[$key][$field] = (string) BigDecimal::of($agingGroups[$key][$field])->plus($value)->toScale(6);
                        $totals[$currency][$field] = ($totals[$currency][$field] ?? BigDecimal::zero())->plus($value);
                    }
                }
            });

        // 2. Process OpeningTradeItems
        $side = $vendor ? OpeningTradeItem::SIDE_VENDOR : OpeningTradeItem::SIDE_CUSTOMER;
        $openingQuery = OpeningTradeItem::query()
            ->where('company_id', $company->id)
            ->where('side', $side)
            ->where('opening_date', '<=', $cutoff)
            ->where(function ($q) use ($cutoff): void {
                $q->where(function ($posted): void {
                    $posted->where('status', OpeningTradeItem::STATUS_POSTED)
                        ->whereNull('reversal_posting_batch_id');
                })->orWhere(function ($rev) use ($cutoff): void {
                    $rev->where('status', OpeningTradeItem::STATUS_REVERSED)
                        ->whereHas('reversalPostingBatch', fn ($b) => $b->where('posting_date', '>', $cutoff));
                });
            });

        if ($partyId !== null) {
            $openingQuery->where($party.'_id', $partyId);
        }
        if ($filters->currencyCode !== null) {
            $openingQuery->where('currency_code', $filters->currencyCode);
        }
        if ($overdue) {
            $openingQuery->whereNotNull('due_date')->where('due_date', '<', $cutoff);
        }

        $openingItems = $openingQuery->orderBy($party.'_id')->orderBy('currency_code')->orderBy('id')->get();
        foreach ($openingItems as $item) {
            $value = $item->calculateOutstanding($cutoff);
            if (! $value->isPositive()) {
                continue;
            }
            $partyIdVal = (int) $item->getAttribute($party.'_id');
            $partiesWithBalance[$partyIdVal] = true;
            $currency = (string) $item->currency_code;
            $due = $item->due_date ? Carbon::parse($item->due_date, (string) $company->timezone)->toDateString() : null;
            $days = $due === null ? 0 : max(0, (int) Carbon::parse($due, (string) $company->timezone)->diffInDays($asOf, false));
            $snapshot = $item->party_snapshot ?? [];
            $identity = [
                $party.'_id' => $partyIdVal,
                $party.'_name_ar' => (string) ($snapshot['name_ar'] ?? $snapshot['name_en'] ?? ''),
                $party.'_name_en' => (string) ($snapshot['name_en'] ?? $snapshot['name_ar'] ?? ''),
                'currency_code' => $currency,
            ];

            if (! $aging) {
                $totals[$currency] = ($totals[$currency] ?? BigDecimal::zero())->plus($value);
                $pager->add($identity + [
                    ($vendor ? 'purchase_id' : 'invoice_id') => null,
                    'opening_trade_item_id' => (int) $item->id,
                    ($vendor ? 'purchase_number' : 'invoice_number') => $item->reference_number ?: ('OPEN-'.$item->public_id),
                    $dateField => Carbon::parse($item->opening_date)->toDateString(),
                    'due_date' => $due,
                    'days_overdue' => $days,
                    'grand_total_currency' => (string) BigDecimal::of($item->amount)->toScale(6),
                    'outstanding_currency' => (string) $value->toScale(6),
                ]);

                continue;
            }

            $key = $identity[$party.'_id'].'/'.$currency;
            $totalKey = $vendor ? 'gross_open' : 'total_outstanding';
            if (! isset($agingGroups[$key])) {
                $agingGroups[$key] = $identity + array_fill_keys(['unspecified', 'current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus', $totalKey], '0.000000');
            }
            $bucket = $due === null ? 'unspecified' : ($due >= $cutoff ? 'current' : ($days <= 30 ? 'days_1_30' : ($days <= 60 ? 'days_31_60' : ($days <= 90 ? 'days_61_90' : 'days_90_plus'))));
            $stamp = Carbon::parse($item->opening_date)->toDateString().'/'.str_pad((string) $item->id, 20, '0', STR_PAD_LEFT);
            if (! isset($agingGroups[$key]['_identity_stamp']) || $stamp > $agingGroups[$key]['_identity_stamp']) {
                $agingGroups[$key][$party.'_name_ar'] = $identity[$party.'_name_ar'];
                $agingGroups[$key][$party.'_name_en'] = $identity[$party.'_name_en'];
                $agingGroups[$key]['_identity_stamp'] = $stamp;
            }
            foreach ([$bucket, $totalKey] as $field) {
                $agingGroups[$key][$field] = (string) BigDecimal::of($agingGroups[$key][$field])->plus($value)->toScale(6);
                $totals[$currency][$field] = ($totals[$currency][$field] ?? BigDecimal::zero())->plus($value);
            }
        }

        if ($aging) {
            foreach ($agingGroups as $grp) {
                $pager->add($grp);
            }
        }

        $distinctPartyCount = count($partiesWithBalance);
        $formatted = [];
        foreach ($totals as $currency => $values) {
            if (! $aging) {
                $formatted[$currency] = (string) $values->toScale(6);

                continue;
            }
            $formatted[$currency] = ['currency_code' => $currency];
            foreach (['unspecified', 'current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus', $vendor ? 'gross_open' : 'total_outstanding'] as $field) {
                $formatted[$currency][$field] = (string) ($values[$field] ?? BigDecimal::zero())->toScale(6);
            }
        }
        $rows = $pager->rows();
        foreach ($rows as &$row) {
            unset($row['_identity_stamp']);
        } unset($row);

        return ['rows' => $rows, 'count' => $pager->count(), 'totals' => $aging
            ? ['currencies' => $formatted, ($vendor ? 'vendor_count' : 'customer_count') => $distinctPartyCount]
            : [($vendor ? 'unpaid_purchase_count' : ($overdue ? 'overdue_invoice_count' : 'unpaid_invoice_count')) => $pager->count(), 'outstanding_by_currency' => $formatted]];
    }
}
