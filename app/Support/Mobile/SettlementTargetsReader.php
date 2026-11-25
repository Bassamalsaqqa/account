<?php

declare(strict_types=1);

namespace App\Support\Mobile;

use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPaymentAllocation;
use App\Models\OpeningTradeItem;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\Vendor;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Purchasing\PurchasePayablePosition;
use App\Services\Purchasing\VendorFinancialRead;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SettlementTargetsReader
{
    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 250;

    private const int STREAM_CHUNK_SIZE = 100;

    private const int MAX_CANDIDATES_SCAN = 2000;

    /**
     * Read open settlement targets for a customer with bounded keyset cursor pagination.
     */
    public function readCustomerTargets(
        Request $request,
        Company $company,
        Customer $customer,
        CurrencyMinorUnitsResolver $minorUnitsResolver
    ): JsonResponse {
        $limitResult = $this->parseLimit($request);
        if ($limitResult instanceof JsonResponse) {
            return $limitResult;
        }
        $limit = $limitResult;

        $currencyFilter = $request->query('currency');
        $currencyCode = is_string($currencyFilter) && trim($currencyFilter) !== ''
            ? strtoupper(trim($currencyFilter))
            : null;

        $cursorData = null;
        $cursorParam = $request->query('cursor');
        if (is_string($cursorParam) && trim($cursorParam) !== '') {
            try {
                $cursorData = SettlementTargetsCursorCodec::decode(
                    trim($cursorParam),
                    (string) $company->public_id,
                    (string) $customer->public_id,
                    $currencyCode
                );
            } catch (Throwable $e) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Invalid pagination cursor: '.$e->getMessage(),
                    status: 422
                );
            }

            if ($cursorData !== null && ! in_array($cursorData['target_type'], ['invoice', 'opening_trade_item'], true)) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Invalid pagination cursor: target_type ['.$cursorData['target_type'].'] is not valid for customer settlement targets.',
                    status: 422
                );
            }
        }

        $tz = (string) ($company->timezone ?: 'UTC');
        $today = now($tz)->toDateString();

        // Two-stream candidate iterator: Invoices & Customer Opening Trade Items
        $invoiceBuffer = [];
        $openingBuffer = [];
        $invoiceExhausted = false;
        $openingExhausted = false;
        $lastInvoiceKeyset = null;
        $lastOpeningKeyset = null;

        $fetchInvoiceChunk = function () use (
            $company,
            $customer,
            $currencyCode,
            &$lastInvoiceKeyset,
            &$invoiceExhausted,
            $cursorData,
            $tz
        ): array {
            if ($invoiceExhausted) {
                return [];
            }

            $q = SalesInvoice::query()
                ->where('company_id', $company->id)
                ->where('customer_id', $customer->id)
                ->where('status', SalesInvoice::STATUS_POSTED);

            if ($currencyCode !== null) {
                $q->where('currency_code', $currencyCode);
            }

            if ($lastInvoiceKeyset !== null) {
                $d = $lastInvoiceKeyset['date'];
                $id = $lastInvoiceKeyset['id'];
                $q->where(function ($sub) use ($d, $id): void {
                    $sub->where('issue_date', '>', $d)
                        ->orWhere(function ($eq) use ($d, $id): void {
                            $eq->where('issue_date', '=', $d)->where('public_id', '>', $id);
                        });
                });
            } elseif ($cursorData !== null) {
                $cD = $cursorData['business_date'];
                $cT = $cursorData['target_type'];
                $cI = $cursorData['target_id'];
                if ($cT === 'invoice') {
                    $q->where(function ($sub) use ($cD, $cI): void {
                        $sub->where('issue_date', '>', $cD)
                            ->orWhere(function ($eq) use ($cD, $cI): void {
                                $eq->where('issue_date', '=', $cD)->where('public_id', '>', $cI);
                            });
                    });
                } else {
                    $q->where('issue_date', '>', $cD);
                }
            }

            $items = $q->orderBy('issue_date', 'asc')
                ->orderBy('public_id', 'asc')
                ->limit(self::STREAM_CHUNK_SIZE)
                ->get();

            if ($items->count() < self::STREAM_CHUNK_SIZE) {
                $invoiceExhausted = true;
            }

            if ($items->isEmpty()) {
                return [];
            }

            $last = $items->last();
            $lastInvoiceKeyset = [
                'date' => Carbon::parse($last->issue_date, $tz)->toDateString(),
                'id' => (string) $last->public_id,
            ];

            return $items->all();
        };

        $fetchOpeningChunk = function () use (
            $company,
            $customer,
            $currencyCode,
            &$lastOpeningKeyset,
            &$openingExhausted,
            $cursorData,
            $tz
        ): array {
            if ($openingExhausted) {
                return [];
            }

            $q = OpeningTradeItem::query()
                ->where('company_id', $company->id)
                ->where('customer_id', $customer->id)
                ->where('side', OpeningTradeItem::SIDE_CUSTOMER)
                ->where('status', OpeningTradeItem::STATUS_POSTED)
                ->whereNotNull('posting_batch_id')
                ->with(['postingBatch', 'reversalPostingBatch']);

            if ($currencyCode !== null) {
                $q->where('currency_code', $currencyCode);
            }

            if ($lastOpeningKeyset !== null) {
                $d = $lastOpeningKeyset['date'];
                $id = $lastOpeningKeyset['id'];
                $q->where(function ($sub) use ($d, $id): void {
                    $sub->where('opening_date', '>', $d)
                        ->orWhere(function ($eq) use ($d, $id): void {
                            $eq->where('opening_date', '=', $d)->where('public_id', '>', $id);
                        });
                });
            } elseif ($cursorData !== null) {
                $cD = $cursorData['business_date'];
                $cT = $cursorData['target_type'];
                $cI = $cursorData['target_id'];
                if ($cT === 'invoice') {
                    $q->where('opening_date', '>=', $cD);
                } else {
                    $q->where(function ($sub) use ($cD, $cI): void {
                        $sub->where('opening_date', '>', $cD)
                            ->orWhere(function ($eq) use ($cD, $cI): void {
                                $eq->where('opening_date', '=', $cD)->where('public_id', '>', $cI);
                            });
                    });
                }
            }

            $items = $q->orderBy('opening_date', 'asc')
                ->orderBy('public_id', 'asc')
                ->limit(self::STREAM_CHUNK_SIZE)
                ->get();

            if ($items->count() < self::STREAM_CHUNK_SIZE) {
                $openingExhausted = true;
            }

            if ($items->isEmpty()) {
                return [];
            }

            $last = $items->last();
            $lastOpeningKeyset = [
                'date' => Carbon::parse($last->opening_date, $tz)->toDateString(),
                'id' => (string) $last->public_id,
            ];

            return $items->all();
        };

        $openTargets = [];
        /** @var int $candidatesScanned */
        $candidatesScanned = 0;
        $lastEvaluatedCandidate = null;

        while (count($openTargets) < $limit + 1 && $candidatesScanned < self::MAX_CANDIDATES_SCAN) {
            $batchBudget = min(50, self::MAX_CANDIDATES_SCAN - $candidatesScanned);

            // Assemble a batch of candidates in strict chronological order within scan budget
            $candidateBatch = [];
            while (count($candidateBatch) < $batchBudget) {
                if (empty($invoiceBuffer) && ! $invoiceExhausted) {
                    $invoiceBuffer = $fetchInvoiceChunk();
                }
                if (empty($openingBuffer) && ! $openingExhausted) {
                    $openingBuffer = $fetchOpeningChunk();
                }

                if (empty($invoiceBuffer) && empty($openingBuffer)) {
                    break;
                }

                if (! empty($invoiceBuffer) && ! empty($openingBuffer)) {
                    $invDate = Carbon::parse($invoiceBuffer[0]->issue_date, $tz)->toDateString();
                    $openDate = Carbon::parse($openingBuffer[0]->opening_date, $tz)->toDateString();
                    $cmp = $this->compareTargets(
                        $invDate,
                        'invoice',
                        (string) $invoiceBuffer[0]->public_id,
                        $openDate,
                        'opening_trade_item',
                        (string) $openingBuffer[0]->public_id
                    );
                    if ($cmp <= 0) {
                        $candidateBatch[] = ['type' => 'invoice', 'model' => array_shift($invoiceBuffer)];
                    } else {
                        $candidateBatch[] = ['type' => 'opening_trade_item', 'model' => array_shift($openingBuffer)];
                    }
                } elseif (! empty($invoiceBuffer)) {
                    $candidateBatch[] = ['type' => 'invoice', 'model' => array_shift($invoiceBuffer)];
                } else {
                    $candidateBatch[] = ['type' => 'opening_trade_item', 'model' => array_shift($openingBuffer)];
                }
            }

            if (empty($candidateBatch)) {
                break;
            }

            // Batch balance resolution
            $invoiceIds = [];
            $openingIds = [];
            foreach ($candidateBatch as $entry) {
                if ($entry['type'] === 'invoice') {
                    $invoiceIds[] = (int) $entry['model']->id;
                } else {
                    $openingIds[] = (int) $entry['model']->id;
                }
            }

            $allocationsByInvoice = [];
            $returnsByInvoice = [];
            if (! empty($invoiceIds)) {
                $allocationsByInvoice = CustomerPaymentAllocation::query()
                    ->where('company_id', $company->id)
                    ->whereIn('sales_invoice_id', $invoiceIds)
                    ->active()
                    ->selectRaw('sales_invoice_id, SUM(allocated_amount) as total_allocated')
                    ->groupBy('sales_invoice_id')
                    ->pluck('total_allocated', 'sales_invoice_id')
                    ->all();

                $returnsByInvoice = SalesReturn::query()
                    ->where('company_id', $company->id)
                    ->whereIn('sales_invoice_id', $invoiceIds)
                    ->where('status', SalesReturn::STATUS_POSTED)
                    ->selectRaw('sales_invoice_id, SUM(grand_total_currency) as total_returned')
                    ->groupBy('sales_invoice_id')
                    ->pluck('total_returned', 'sales_invoice_id')
                    ->all();
            }

            $allocationsByOpening = [];
            if (! empty($openingIds)) {
                $allocationsByOpening = DB::table('customer_payment_allocations as a')
                    ->join('customer_payments as p', 'p.id', '=', 'a.customer_payment_id')
                    ->where('a.company_id', $company->id)
                    ->where('p.company_id', $company->id)
                    ->whereIn('a.opening_trade_item_id', $openingIds)
                    ->where('p.is_reversed', false)
                    ->whereNotNull('p.posting_batch_id')
                    ->where(function ($q): void {
                        $q->whereNull('a.application_event_id')
                            ->orWhereExists(function ($sub): void {
                                $sub->select(DB::raw(1))
                                    ->from('customer_payment_application_events as e')
                                    ->whereColumn('e.id', 'a.application_event_id')
                                    ->whereNotNull('e.applied_at')
                                    ->whereNull('e.reversed_at')
                                    ->whereNull('e.reversal_posting_batch_id');
                            });
                    })
                    ->selectRaw('a.opening_trade_item_id, SUM(a.allocated_amount) as total_allocated')
                    ->groupBy('a.opening_trade_item_id')
                    ->pluck('total_allocated', 'a.opening_trade_item_id')
                    ->all();
            }

            foreach ($candidateBatch as $entry) {
                $candidatesScanned++;
                $model = $entry['model'];
                $candidateDate = $entry['type'] === 'invoice'
                    ? Carbon::parse($model->issue_date, $tz)->toDateString()
                    : Carbon::parse($model->opening_date, $tz)->toDateString();

                $lastEvaluatedCandidate = [
                    'date' => $candidateDate,
                    'type' => $entry['type'],
                    'id' => (string) $model->public_id,
                ];

                if ($entry['type'] === 'invoice') {
                    /** @var SalesInvoice $invoice */
                    $invoice = $entry['model'];
                    $grandTotal = BigDecimal::of((string) $invoice->grand_total_currency);
                    $paid = BigDecimal::of((string) ($allocationsByInvoice[$invoice->id] ?? '0'));
                    $returned = BigDecimal::of((string) ($returnsByInvoice[$invoice->id] ?? '0'));
                    $remaining = $grandTotal->minus($paid)->minus($returned);
                    if ($remaining->isNegative()) {
                        $remaining = BigDecimal::zero();
                    }
                    if (! $remaining->isPositive()) {
                        continue;
                    }

                    $invCurr = (string) $invoice->currency_code;
                    $cmu = $minorUnitsResolver->resolveForCompany((int) $company->id, $invCurr);
                    $allocated = $grandTotal->minus($remaining);
                    if ($allocated->isNegative()) {
                        $allocated = BigDecimal::zero();
                    }

                    $issueDate = Carbon::parse($invoice->issue_date, $tz)->toDateString();
                    $dueDate = $invoice->due_date ? Carbon::parse($invoice->due_date, $tz)->toDateString() : null;
                    $daysOverdue = ($dueDate === null || $dueDate >= $today)
                        ? 0
                        : max(0, (int) Carbon::parse($dueDate, $tz)->diffInDays(now($tz), false));

                    $openTargets[] = [
                        'target_type' => 'invoice',
                        'target_id' => (string) $invoice->public_id,
                        'target_number' => (string) ($invoice->invoice_number ?? $invoice->public_id),
                        'business_date' => $issueDate,
                        'issue_date' => $issueDate,
                        'due_date' => $dueDate,
                        'days_overdue' => $daysOverdue,
                        'currency' => $invCurr,
                        'currency_minor_units' => $cmu,
                        'original_amount' => (string) $grandTotal->toScale($cmu, RoundingMode::HALF_UP),
                        'original_amount_exact' => (string) $grandTotal->toScale(6),
                        'allocated_amount' => (string) $allocated->toScale($cmu, RoundingMode::HALF_UP),
                        'allocated_amount_exact' => (string) $allocated->toScale(6),
                        'remaining_amount' => (string) $remaining->toScale($cmu, RoundingMode::HALF_UP),
                        'remaining_amount_exact' => (string) $remaining->toScale(6),
                    ];
                } else {
                    /** @var OpeningTradeItem $item */
                    $item = $entry['model'];
                    if ($item->hasCanonicalReversal()) {
                        continue;
                    }

                    $original = BigDecimal::of((string) $item->amount_currency);
                    $allocatedPaid = BigDecimal::of((string) ($allocationsByOpening[$item->id] ?? '0'));
                    $remaining = $original->minus($allocatedPaid);
                    if ($remaining->isNegative()) {
                        $remaining = BigDecimal::zero();
                    }
                    if (! $remaining->isPositive()) {
                        continue;
                    }

                    $itemCurr = (string) $item->currency_code;
                    $cmu = $minorUnitsResolver->resolveForCompany((int) $company->id, $itemCurr);
                    $allocated = $original->minus($remaining);
                    if ($allocated->isNegative()) {
                        $allocated = BigDecimal::zero();
                    }

                    $openDate = Carbon::parse($item->opening_date, $tz)->toDateString();
                    $dueDate = $item->due_date ? Carbon::parse($item->due_date, $tz)->toDateString() : null;
                    $daysOverdue = ($dueDate === null || $dueDate >= $today)
                        ? 0
                        : max(0, (int) Carbon::parse($dueDate, $tz)->diffInDays(now($tz), false));

                    $refNum = $item->original_reference ?: ('OPEN-'.$item->public_id);

                    $openTargets[] = [
                        'target_type' => 'opening_trade_item',
                        'target_id' => (string) $item->public_id,
                        'target_number' => (string) $refNum,
                        'business_date' => $openDate,
                        'opening_date' => $openDate,
                        'due_date' => $dueDate,
                        'days_overdue' => $daysOverdue,
                        'currency' => $itemCurr,
                        'currency_minor_units' => $cmu,
                        'original_amount' => (string) $original->toScale($cmu, RoundingMode::HALF_UP),
                        'original_amount_exact' => (string) $original->toScale(6),
                        'allocated_amount' => (string) $allocated->toScale($cmu, RoundingMode::HALF_UP),
                        'allocated_amount_exact' => (string) $allocated->toScale(6),
                        'remaining_amount' => (string) $remaining->toScale($cmu, RoundingMode::HALF_UP),
                        'remaining_amount_exact' => (string) $remaining->toScale(6),
                    ];
                }

                if (count($openTargets) === $limit + 1) {
                    break;
                }
            }
        }

        $hasMore = false;
        $nextCursor = null;

        if (count($openTargets) > $limit) {
            $hasMore = true;
            $pageTargets = array_slice($openTargets, 0, $limit);
            $lastReturned = $pageTargets[$limit - 1];
            $nextCursor = SettlementTargetsCursorCodec::encode(
                (string) $company->public_id,
                (string) $customer->public_id,
                $currencyCode,
                (string) $lastReturned['business_date'],
                (string) $lastReturned['target_type'],
                (string) $lastReturned['target_id']
            );
        } else {
            $pageTargets = $openTargets;

            // Ensure we know true exhaustion state of streams if buffers are empty
            if (empty($invoiceBuffer) && ! $invoiceExhausted) {
                $invoiceBuffer = $fetchInvoiceChunk();
            }
            if (empty($openingBuffer) && ! $openingExhausted) {
                $openingBuffer = $fetchOpeningChunk();
            }

            $streamsExhausted = $invoiceExhausted && $openingExhausted && empty($invoiceBuffer) && empty($openingBuffer);

            if ($candidatesScanned >= self::MAX_CANDIDATES_SCAN && ! $streamsExhausted) {
                $hasMore = true;
                if ($lastEvaluatedCandidate !== null) {
                    $nextCursor = SettlementTargetsCursorCodec::encode(
                        (string) $company->public_id,
                        (string) $customer->public_id,
                        $currencyCode,
                        (string) $lastEvaluatedCandidate['date'],
                        (string) $lastEvaluatedCandidate['type'],
                        (string) $lastEvaluatedCandidate['id']
                    );
                }
            }
        }

        // Truthful page totals
        $totalRemainingByCurrency = [];
        $totalOriginalByCurrency = [];
        foreach ($pageTargets as $target) {
            $curr = $target['currency'];
            $rem = BigDecimal::of($target['remaining_amount_exact']);
            $orig = BigDecimal::of($target['original_amount_exact']);
            $totalRemainingByCurrency[$curr] = ($totalRemainingByCurrency[$curr] ?? BigDecimal::zero())->plus($rem);
            $totalOriginalByCurrency[$curr] = ($totalOriginalByCurrency[$curr] ?? BigDecimal::zero())->plus($orig);
        }

        $formattedRemainingTotals = [];
        $formattedOriginalTotals = [];
        foreach ($totalRemainingByCurrency as $curr => $total) {
            $cmu = $minorUnitsResolver->resolveForCompany((int) $company->id, $curr);
            $formattedRemainingTotals[$curr] = [
                'amount' => (string) $total->toScale($cmu, RoundingMode::HALF_UP),
                'amount_exact' => (string) $total->toScale(6),
                'minor_units' => $cmu,
            ];
            $origTotal = $totalOriginalByCurrency[$curr] ?? BigDecimal::zero();
            $formattedOriginalTotals[$curr] = [
                'amount' => (string) $origTotal->toScale($cmu, RoundingMode::HALF_UP),
                'amount_exact' => (string) $origTotal->toScale(6),
                'minor_units' => $cmu,
            ];
        }

        $baseCurr = (string) $company->base_currency_code;
        $baseCmu = $minorUnitsResolver->resolveForCompany((int) $company->id, $baseCurr);

        return response()->json([
            'customer_id' => (string) $customer->public_id,
            'customer_name' => (string) $customer->displayName(),
            'currency' => $currencyCode ?? $baseCurr,
            'currency_minor_units' => $currencyCode ? $minorUnitsResolver->resolveForCompany((int) $company->id, $currencyCode) : $baseCmu,
            'total_targets' => count($pageTargets),
            'targets' => $pageTargets,
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
            'totals' => [
                'remaining_by_currency' => $formattedRemainingTotals,
                'original_by_currency' => $formattedOriginalTotals,
            ],
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * Read open settlement targets for a supplier with bounded keyset cursor pagination.
     */
    public function readSupplierTargets(
        Request $request,
        Company $company,
        Vendor $vendor,
        CurrencyMinorUnitsResolver $minorUnitsResolver
    ): JsonResponse {
        // Enforce strict cost redaction before any queries or cursor evaluation
        if (! app(VendorFinancialRead::class)->allows((int) $company->id)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Viewing supplier financial settlement targets requires purchasing cost permission.',
                status: 403
            );
        }

        $limitResult = $this->parseLimit($request);
        if ($limitResult instanceof JsonResponse) {
            return $limitResult;
        }
        $limit = $limitResult;

        $currencyFilter = $request->query('currency');
        $currencyCode = is_string($currencyFilter) && trim($currencyFilter) !== ''
            ? strtoupper(trim($currencyFilter))
            : null;

        $cursorData = null;
        $cursorParam = $request->query('cursor');
        if (is_string($cursorParam) && trim($cursorParam) !== '') {
            try {
                $cursorData = SettlementTargetsCursorCodec::decode(
                    trim($cursorParam),
                    (string) $company->public_id,
                    (string) $vendor->public_id,
                    $currencyCode
                );
            } catch (Throwable $e) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Invalid pagination cursor: '.$e->getMessage(),
                    status: 422
                );
            }

            if ($cursorData !== null && ! in_array($cursorData['target_type'], ['purchase', 'opening_trade_item'], true)) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Invalid pagination cursor: target_type ['.$cursorData['target_type'].'] is not valid for supplier settlement targets.',
                    status: 422
                );
            }
        }

        $tz = (string) ($company->timezone ?: 'UTC');
        $today = now($tz)->toDateString();

        // Two-stream candidate iterator: Vendor Opening Trade Items & Purchases
        // Note alphabetical order: 'opening_trade_item' < 'purchase'
        $openingBuffer = [];
        $purchaseBuffer = [];
        $openingExhausted = false;
        $purchaseExhausted = false;
        $lastOpeningKeyset = null;
        $lastPurchaseKeyset = null;

        $fetchOpeningChunk = function () use (
            $company,
            $vendor,
            $currencyCode,
            &$lastOpeningKeyset,
            &$openingExhausted,
            $cursorData,
            $tz
        ): array {
            if ($openingExhausted) {
                return [];
            }

            $q = OpeningTradeItem::query()
                ->where('company_id', $company->id)
                ->where('vendor_id', $vendor->id)
                ->where('side', OpeningTradeItem::SIDE_VENDOR)
                ->where('status', OpeningTradeItem::STATUS_POSTED)
                ->whereNotNull('posting_batch_id')
                ->with(['postingBatch', 'reversalPostingBatch']);

            if ($currencyCode !== null) {
                $q->where('currency_code', $currencyCode);
            }

            if ($lastOpeningKeyset !== null) {
                $d = $lastOpeningKeyset['date'];
                $id = $lastOpeningKeyset['id'];
                $q->where(function ($sub) use ($d, $id): void {
                    $sub->where('opening_date', '>', $d)
                        ->orWhere(function ($eq) use ($d, $id): void {
                            $eq->where('opening_date', '=', $d)->where('public_id', '>', $id);
                        });
                });
            } elseif ($cursorData !== null) {
                $cD = $cursorData['business_date'];
                $cT = $cursorData['target_type'];
                $cI = $cursorData['target_id'];
                if ($cT === 'opening_trade_item') {
                    $q->where(function ($sub) use ($cD, $cI): void {
                        $sub->where('opening_date', '>', $cD)
                            ->orWhere(function ($eq) use ($cD, $cI): void {
                                $eq->where('opening_date', '=', $cD)->where('public_id', '>', $cI);
                            });
                    });
                } else {
                    // $cT === 'purchase'
                    $q->where('opening_date', '>', $cD);
                }
            }

            $items = $q->orderBy('opening_date', 'asc')
                ->orderBy('public_id', 'asc')
                ->limit(self::STREAM_CHUNK_SIZE)
                ->get();

            if ($items->isEmpty()) {
                $openingExhausted = true;

                return [];
            }

            $last = $items->last();
            $lastOpeningKeyset = [
                'date' => Carbon::parse($last->opening_date, $tz)->toDateString(),
                'id' => (string) $last->public_id,
            ];

            return $items->all();
        };

        $fetchPurchaseChunk = function () use (
            $company,
            $vendor,
            $currencyCode,
            &$lastPurchaseKeyset,
            &$purchaseExhausted,
            $cursorData,
            $tz
        ): array {
            if ($purchaseExhausted) {
                return [];
            }

            $q = Purchase::query()
                ->where('company_id', $company->id)
                ->where('vendor_id', $vendor->id)
                ->where('status', Purchase::STATUS_POSTED);

            if ($currencyCode !== null) {
                $q->where('currency_code', $currencyCode);
            }

            if ($lastPurchaseKeyset !== null) {
                $d = $lastPurchaseKeyset['date'];
                $id = $lastPurchaseKeyset['id'];
                $q->where(function ($sub) use ($d, $id): void {
                    $sub->where('purchase_date', '>', $d)
                        ->orWhere(function ($eq) use ($d, $id): void {
                            $eq->where('purchase_date', '=', $d)->where('public_id', '>', $id);
                        });
                });
            } elseif ($cursorData !== null) {
                $cD = $cursorData['business_date'];
                $cT = $cursorData['target_type'];
                $cI = $cursorData['target_id'];
                if ($cT === 'opening_trade_item') {
                    $q->where('purchase_date', '>=', $cD);
                } else {
                    $q->where(function ($sub) use ($cD, $cI): void {
                        $sub->where('purchase_date', '>', $cD)
                            ->orWhere(function ($eq) use ($cD, $cI): void {
                                $eq->where('purchase_date', '=', $cD)->where('public_id', '>', $cI);
                            });
                    });
                }
            }

            $items = $q->orderBy('purchase_date', 'asc')
                ->orderBy('public_id', 'asc')
                ->limit(self::STREAM_CHUNK_SIZE)
                ->get();

            if ($items->isEmpty()) {
                $purchaseExhausted = true;

                return [];
            }

            $last = $items->last();
            $lastPurchaseKeyset = [
                'date' => Carbon::parse($last->purchase_date, $tz)->toDateString(),
                'id' => (string) $last->public_id,
            ];

            return $items->all();
        };

        $openTargets = [];
        /** @var int $candidatesScanned */
        $candidatesScanned = 0;
        $lastEvaluatedCandidate = null;

        while (count($openTargets) < $limit + 1 && $candidatesScanned < self::MAX_CANDIDATES_SCAN) {
            $batchBudget = min(50, self::MAX_CANDIDATES_SCAN - $candidatesScanned);

            // Assemble a batch of candidates in strict chronological order within scan budget
            $candidateBatch = [];
            while (count($candidateBatch) < $batchBudget) {
                if (empty($openingBuffer) && ! $openingExhausted) {
                    $openingBuffer = $fetchOpeningChunk();
                }
                if (empty($purchaseBuffer) && ! $purchaseExhausted) {
                    $purchaseBuffer = $fetchPurchaseChunk();
                }

                if (empty($openingBuffer) && empty($purchaseBuffer)) {
                    break;
                }

                if (! empty($openingBuffer) && ! empty($purchaseBuffer)) {
                    $openDate = Carbon::parse($openingBuffer[0]->opening_date, $tz)->toDateString();
                    $purDate = Carbon::parse($purchaseBuffer[0]->purchase_date, $tz)->toDateString();
                    $cmp = $this->compareTargets(
                        $openDate,
                        'opening_trade_item',
                        (string) $openingBuffer[0]->public_id,
                        $purDate,
                        'purchase',
                        (string) $purchaseBuffer[0]->public_id
                    );
                    if ($cmp <= 0) {
                        $candidateBatch[] = ['type' => 'opening_trade_item', 'model' => array_shift($openingBuffer)];
                    } else {
                        $candidateBatch[] = ['type' => 'purchase', 'model' => array_shift($purchaseBuffer)];
                    }
                } elseif (! empty($openingBuffer)) {
                    $candidateBatch[] = ['type' => 'opening_trade_item', 'model' => array_shift($openingBuffer)];
                } else {
                    $candidateBatch[] = ['type' => 'purchase', 'model' => array_shift($purchaseBuffer)];
                }
            }

            if (empty($candidateBatch)) {
                break;
            }

            // Batch balance resolution
            $purchases = [];
            $openingIds = [];
            foreach ($candidateBatch as $entry) {
                if ($entry['type'] === 'purchase') {
                    $purchases[] = $entry['model'];
                } else {
                    $openingIds[] = (int) $entry['model']->id;
                }
            }

            $payablePositions = [];
            if (! empty($purchases)) {
                $payablePositions = PurchasePayablePosition::forPurchases($purchases);
            }

            $allocationsByOpening = [];
            if (! empty($openingIds)) {
                $allocationsByOpening = DB::table('vendor_payment_allocations as a')
                    ->join('vendor_payments as p', 'p.id', '=', 'a.vendor_payment_id')
                    ->where('a.company_id', $company->id)
                    ->where('p.company_id', $company->id)
                    ->whereIn('a.opening_trade_item_id', $openingIds)
                    ->where('p.is_reversed', false)
                    ->whereNotNull('p.posting_batch_id')
                    ->where(function ($q): void {
                        $q->whereNull('a.application_event_id')
                            ->orWhereExists(function ($sub): void {
                                $sub->select(DB::raw(1))
                                    ->from('vendor_payment_application_events as e')
                                    ->whereColumn('e.id', 'a.application_event_id')
                                    ->whereNotNull('e.applied_at')
                                    ->whereNull('e.reversed_at')
                                    ->whereNull('e.reversal_posting_batch_id');
                            });
                    })
                    ->selectRaw('a.opening_trade_item_id, SUM(a.allocated_amount) as total_allocated')
                    ->groupBy('a.opening_trade_item_id')
                    ->pluck('total_allocated', 'a.opening_trade_item_id')
                    ->all();
            }

            foreach ($candidateBatch as $entry) {
                $candidatesScanned++;
                $model = $entry['model'];
                $candidateDate = $entry['type'] === 'purchase'
                    ? Carbon::parse($model->purchase_date, $tz)->toDateString()
                    : Carbon::parse($model->opening_date, $tz)->toDateString();

                $lastEvaluatedCandidate = [
                    'date' => $candidateDate,
                    'type' => $entry['type'],
                    'id' => (string) $model->public_id,
                ];

                if ($entry['type'] === 'purchase') {
                    /** @var Purchase $purchase */
                    $purchase = $entry['model'];
                    $position = $payablePositions[(int) $purchase->id] ?? null;
                    $remaining = $position !== null ? $position->outstanding : BigDecimal::zero();
                    if (! $remaining->isPositive()) {
                        continue;
                    }

                    $purCurr = (string) $purchase->currency_code;
                    $cmu = $minorUnitsResolver->resolveForCompany((int) $company->id, $purCurr);
                    $grandTotal = BigDecimal::of((string) $purchase->grand_total_currency);
                    $allocated = $grandTotal->minus($remaining);
                    if ($allocated->isNegative()) {
                        $allocated = BigDecimal::zero();
                    }

                    $purchaseDate = Carbon::parse($purchase->purchase_date, $tz)->toDateString();
                    $dueDate = $purchase->due_date ? Carbon::parse($purchase->due_date, $tz)->toDateString() : null;
                    $daysOverdue = ($dueDate === null || $dueDate >= $today)
                        ? 0
                        : max(0, (int) Carbon::parse($dueDate, $tz)->diffInDays(now($tz), false));

                    $openTargets[] = [
                        'target_type' => 'purchase',
                        'target_id' => (string) $purchase->public_id,
                        'target_number' => (string) ($purchase->purchase_number ?? $purchase->public_id),
                        'business_date' => $purchaseDate,
                        'purchase_date' => $purchaseDate,
                        'due_date' => $dueDate,
                        'days_overdue' => $daysOverdue,
                        'currency' => $purCurr,
                        'currency_minor_units' => $cmu,
                        'original_amount' => (string) $grandTotal->toScale($cmu, RoundingMode::HALF_UP),
                        'original_amount_exact' => (string) $grandTotal->toScale(6),
                        'allocated_amount' => (string) $allocated->toScale($cmu, RoundingMode::HALF_UP),
                        'allocated_amount_exact' => (string) $allocated->toScale(6),
                        'remaining_amount' => (string) $remaining->toScale($cmu, RoundingMode::HALF_UP),
                        'remaining_amount_exact' => (string) $remaining->toScale(6),
                    ];
                } else {
                    /** @var OpeningTradeItem $item */
                    $item = $entry['model'];
                    if ($item->hasCanonicalReversal()) {
                        continue;
                    }

                    $original = BigDecimal::of((string) $item->amount_currency);
                    $allocatedPaid = BigDecimal::of((string) ($allocationsByOpening[$item->id] ?? '0'));
                    $remaining = $original->minus($allocatedPaid);
                    if ($remaining->isNegative()) {
                        $remaining = BigDecimal::zero();
                    }
                    if (! $remaining->isPositive()) {
                        continue;
                    }

                    $itemCurr = (string) $item->currency_code;
                    $cmu = $minorUnitsResolver->resolveForCompany((int) $company->id, $itemCurr);
                    $allocated = $original->minus($remaining);
                    if ($allocated->isNegative()) {
                        $allocated = BigDecimal::zero();
                    }

                    $openDate = Carbon::parse($item->opening_date, $tz)->toDateString();
                    $dueDate = $item->due_date ? Carbon::parse($item->due_date, $tz)->toDateString() : null;
                    $daysOverdue = ($dueDate === null || $dueDate >= $today)
                        ? 0
                        : max(0, (int) Carbon::parse($dueDate, $tz)->diffInDays(now($tz), false));

                    $refNum = $item->original_reference ?: ('OPEN-'.$item->public_id);

                    $openTargets[] = [
                        'target_type' => 'opening_trade_item',
                        'target_id' => (string) $item->public_id,
                        'target_number' => (string) $refNum,
                        'business_date' => $openDate,
                        'opening_date' => $openDate,
                        'due_date' => $dueDate,
                        'days_overdue' => $daysOverdue,
                        'currency' => $itemCurr,
                        'currency_minor_units' => $cmu,
                        'original_amount' => (string) $original->toScale($cmu, RoundingMode::HALF_UP),
                        'original_amount_exact' => (string) $original->toScale(6),
                        'allocated_amount' => (string) $allocated->toScale($cmu, RoundingMode::HALF_UP),
                        'allocated_amount_exact' => (string) $allocated->toScale(6),
                        'remaining_amount' => (string) $remaining->toScale($cmu, RoundingMode::HALF_UP),
                        'remaining_amount_exact' => (string) $remaining->toScale(6),
                    ];
                }

                if (count($openTargets) === $limit + 1) {
                    break;
                }
            }
        }

        $hasMore = false;
        $nextCursor = null;

        if (count($openTargets) > $limit) {
            $hasMore = true;
            $pageTargets = array_slice($openTargets, 0, $limit);
            $lastReturned = $pageTargets[$limit - 1];
            $nextCursor = SettlementTargetsCursorCodec::encode(
                (string) $company->public_id,
                (string) $vendor->public_id,
                $currencyCode,
                (string) $lastReturned['business_date'],
                (string) $lastReturned['target_type'],
                (string) $lastReturned['target_id']
            );
        } else {
            $pageTargets = $openTargets;

            // Ensure we know true exhaustion state of streams if buffers are empty
            if (empty($openingBuffer) && ! $openingExhausted) {
                $openingBuffer = $fetchOpeningChunk();
            }
            if (empty($purchaseBuffer) && ! $purchaseExhausted) {
                $purchaseBuffer = $fetchPurchaseChunk();
            }

            $streamsExhausted = $openingExhausted && $purchaseExhausted && empty($openingBuffer) && empty($purchaseBuffer);

            if ($candidatesScanned >= self::MAX_CANDIDATES_SCAN && ! $streamsExhausted) {
                $hasMore = true;
                if ($lastEvaluatedCandidate !== null) {
                    $nextCursor = SettlementTargetsCursorCodec::encode(
                        (string) $company->public_id,
                        (string) $vendor->public_id,
                        $currencyCode,
                        (string) $lastEvaluatedCandidate['date'],
                        (string) $lastEvaluatedCandidate['type'],
                        (string) $lastEvaluatedCandidate['id']
                    );
                }
            }
        }

        // Truthful page totals
        $totalRemainingByCurrency = [];
        $totalOriginalByCurrency = [];
        foreach ($pageTargets as $target) {
            $curr = $target['currency'];
            $rem = BigDecimal::of($target['remaining_amount_exact']);
            $orig = BigDecimal::of($target['original_amount_exact']);
            $totalRemainingByCurrency[$curr] = ($totalRemainingByCurrency[$curr] ?? BigDecimal::zero())->plus($rem);
            $totalOriginalByCurrency[$curr] = ($totalOriginalByCurrency[$curr] ?? BigDecimal::zero())->plus($orig);
        }

        $formattedRemainingTotals = [];
        $formattedOriginalTotals = [];
        foreach ($totalRemainingByCurrency as $curr => $total) {
            $cmu = $minorUnitsResolver->resolveForCompany((int) $company->id, $curr);
            $formattedRemainingTotals[$curr] = [
                'amount' => (string) $total->toScale($cmu, RoundingMode::HALF_UP),
                'amount_exact' => (string) $total->toScale(6),
                'minor_units' => $cmu,
            ];
            $origTotal = $totalOriginalByCurrency[$curr] ?? BigDecimal::zero();
            $formattedOriginalTotals[$curr] = [
                'amount' => (string) $origTotal->toScale($cmu, RoundingMode::HALF_UP),
                'amount_exact' => (string) $origTotal->toScale(6),
                'minor_units' => $cmu,
            ];
        }

        $baseCurr = (string) $company->base_currency_code;
        $baseCmu = $minorUnitsResolver->resolveForCompany((int) $company->id, $baseCurr);

        return response()->json([
            'supplier_id' => (string) $vendor->public_id,
            'supplier_name' => (string) $vendor->displayName(),
            'currency' => $currencyCode ?? $baseCurr,
            'currency_minor_units' => $currencyCode ? $minorUnitsResolver->resolveForCompany((int) $company->id, $currencyCode) : $baseCmu,
            'total_targets' => count($pageTargets),
            'targets' => $pageTargets,
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
            'totals' => [
                'remaining_by_currency' => $formattedRemainingTotals,
                'original_by_currency' => $formattedOriginalTotals,
            ],
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    private function parseLimit(Request $request): int|JsonResponse
    {
        if (! $request->has('limit')) {
            return self::DEFAULT_LIMIT;
        }

        $rawLimit = $request->query('limit');
        if (! is_string($rawLimit)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Parameter [limit] must be an integer between 1 and '.self::MAX_LIMIT.'.',
                status: 422
            );
        }

        $rawLimitStr = trim((string) $rawLimit);
        if (! ctype_digit($rawLimitStr) || (int) $rawLimitStr < 1 || (int) $rawLimitStr > self::MAX_LIMIT) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Parameter [limit] must be an integer between 1 and '.self::MAX_LIMIT.'.',
                status: 422
            );
        }

        return (int) $rawLimitStr;
    }

    private function compareTargets(
        string $dateA,
        string $typeA,
        string $idA,
        string $dateB,
        string $typeB,
        string $idB
    ): int {
        $cmpDate = strcmp($dateA, $dateB);
        if ($cmpDate !== 0) {
            return $cmpDate;
        }

        $cmpType = strcmp($typeA, $typeB);
        if ($cmpType !== 0) {
            return $cmpType;
        }

        return strcmp($idA, $idB);
    }
}
