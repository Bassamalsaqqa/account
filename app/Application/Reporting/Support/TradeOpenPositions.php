<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use App\Application\Reporting\DTO\ReportFilters;
use App\Models\Company;
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
        if ($vendor) {
            $query->where('status', 'posted');
        } else {
            $query->where(fn ($q) => $q->where('status', 'posted')->orWhere(fn ($q) => $q->where('status', 'void')->whereHas('voidPostingBatch', fn ($b) => $b->where('company_id', $company->id)->where('posting_date', '>', $cutoff))));
        }
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

            return $primary !== 0 ? $primary : ($a[$vendor ? 'purchase_id' : 'invoice_id'] <=> $b[$vendor ? 'purchase_id' : 'invoice_id']);
        });
        $totals = [];
        $group = null;
        $groupKey = null;
        /** @param array<string,mixed>|null $row */
        $emit = static function (?array $row) use ($pager): void {
            if ($row !== null) {
                $pager->add($row);
            }
        };
        // Group order permits an aging accumulator for just one party/currency at a time.
        $query->orderBy($party.'_id')->orderBy('currency_code')->orderBy('id')->chunk(200,
            function (Collection $documents) use ($vendor, $aging, $party, $dateField, $cutoff, $asOf, $company, $pager, &$totals, &$group, &$groupKey, $emit): void {
                $positions = $vendor ? app(PurchasePayableAsOf::class)->forHistory($documents, $asOf) : app(ReceivablePositionAsOf::class)->forInvoices($documents, $cutoff);
                foreach ($documents as $document) {
                    $value = $positions[(int) $document->id] ?? BigDecimal::zero();
                    if (! $value->isPositive()) {
                        continue;
                    }
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
                            ($vendor ? 'purchase_number' : 'invoice_number') => (string) $document->getAttribute($vendor ? 'purchase_number' : 'invoice_number'),
                            $dateField => Carbon::parse($document->getAttribute($dateField))->toDateString(),
                            'due_date' => $due, 'days_overdue' => $days, 'grand_total_currency' => (string) BigDecimal::of($document->grand_total_currency)->toScale(6),
                            'outstanding_currency' => (string) $value->toScale(6)]);

                        continue;
                    }
                    $key = $identity[$party.'_id'].'/'.$currency;
                    $totalKey = $vendor ? 'gross_open' : 'total_outstanding';
                    if ($key !== $groupKey) {
                        $emit($group);
                        $groupKey = $key;
                        $group = $identity + array_fill_keys(['unspecified', 'current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus', $totalKey], '0.000000');
                    }
                    $bucket = $due === null ? 'unspecified' : ($due >= $cutoff ? 'current' : ($days <= 30 ? 'days_1_30' : ($days <= 60 ? 'days_31_60' : ($days <= 90 ? 'days_61_90' : 'days_90_plus'))));
                    // Document identity remains a persisted snapshot. Latest date/id wins within each group.
                    $stamp = Carbon::parse($document->getAttribute($dateField))->toDateString().'/'.str_pad((string) $document->id, 20, '0', STR_PAD_LEFT);
                    if (! isset($group['_identity_stamp']) || $stamp > $group['_identity_stamp']) {
                        $group[$party.'_name_ar'] = $identity[$party.'_name_ar'];
                        $group[$party.'_name_en'] = $identity[$party.'_name_en'];
                        $group['_identity_stamp'] = $stamp;
                    }
                    foreach ([$bucket, $totalKey] as $field) {
                        $group[$field] = (string) BigDecimal::of($group[$field])->plus($value)->toScale(6);
                        $totals[$currency][$field] = ($totals[$currency][$field] ?? BigDecimal::zero())->plus($value);
                    }
                }
            });
        $emit($group);
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
            ? ['currencies' => $formatted, ($vendor ? 'vendor_count' : 'customer_count') => $pager->count()]
            : [($vendor ? 'unpaid_purchase_count' : ($overdue ? 'overdue_invoice_count' : 'unpaid_invoice_count')) => $pager->count(), 'outstanding_by_currency' => $formatted]];
    }
}
