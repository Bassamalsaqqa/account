<?php

declare(strict_types=1);

namespace App\Domain\Sales\Queries;

use App\Domain\Money\Queries\CrossCurrencySettlementQuery;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PostingBatch;
use App\Models\PublicShare;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Services\Money\ReceivablePositionAsOf;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CustomerStatementQuery
{
    /**
     * Generate chronological statement grouped by currency with aging breakdown.
     *
     * @return array{
     *     customer: Customer,
     *     from_date: ?string,
     *     to_date: ?string,
     *     currencies: array<string, array{
     *         currency: string,
     *         opening_balance: string,
     *         closing_balance: string,
     *         total_debits: string,
     *         total_credits: string,
     *         entries: list<array{
     *             date: string,
     *             type: string,
     *             number: string,
     *             reference: ?string,
     *             description: string,
     *             debit: string,
     *             credit: string,
     *             balance: string,
     *         }>,
     *         aging: array{
     *             current: string,
     *             days_1_30: string,
     *             days_31_60: string,
     *             days_61_90: string,
     *             days_90_plus: string,
     *             total: string,
     *         }
     *     }>
     * }
     */
    public function execute(Customer $customer, ?string $fromDate = null, ?string $toDate = null): array
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $customer->company_id) {
            throw new NoActiveCompanyException('Customer statements require matching company context.');
        }

        return $this->read($customer, $fromDate, $toDate);
    }

    /** @return array<string, mixed> */
    public function executeForShare(PublicShare $share, Customer $customer): array
    {
        if ($share->subject_type !== PublicShare::SUBJECT_CUSTOMER_STATEMENT || (int) $share->company_id !== (int) $customer->company_id
            || (int) $share->subject_id !== (int) $customer->id || ! $share->is_active || $share->isExpired()) {
            throw new InvalidArgumentException('Statement share does not authorize this customer.');
        }

        return $this->read($customer);
    }

    /** @return array<string, mixed> */
    private function read(Customer $customer, ?string $fromDate = null, ?string $toDate = null): array
    {
        $companyId = $customer->company_id;

        // Historical lifecycle follows financial business dates, not today's status.
        $invoices = $this->documentsAtCutoff(SalesInvoice::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id), $toDate);

        $returns = $this->documentsAtCutoff(SalesReturn::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id), $toDate);

        $payments = CustomerPayment::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->get();

        // Group by currency
        $currencies = [];

        // Build raw ledger rows
        $rawRows = [];

        foreach ($invoices as $inv) {
            $curr = $inv->currency_code;
            $currencies[$curr] = true;
            $rawRows[] = [
                'date' => (string) $inv->issue_date->format('Y-m-d'),
                'due_date' => (string) $inv->due_date?->format('Y-m-d'),
                'type' => 'invoice',
                'number' => $inv->invoice_number,
                'reference' => null,
                'description' => __('sales.sales_invoice', [], $customer->preferred_locale ?: 'ar').' #'.$inv->invoice_number,
                'currency' => $curr,
                'debit' => BigDecimal::of((string) $inv->grand_total_currency),
                'credit' => BigDecimal::zero(),
                'created_at' => $inv->created_at,
                'invoice' => $inv,
            ];
        }

        foreach ($returns as $ret) {
            $curr = $ret->currency_code;
            $currencies[$curr] = true;
            $rawRows[] = [
                'date' => (string) $ret->issue_date->format('Y-m-d'),
                'due_date' => null,
                'type' => 'return',
                'number' => $ret->return_number,
                'reference' => $ret->salesInvoice?->invoice_number,
                'description' => __('sales.sales_return', [], $customer->preferred_locale ?: 'ar').' #'.$ret->return_number,
                'currency' => $curr,
                'debit' => BigDecimal::zero(),
                'credit' => BigDecimal::of((string) $ret->grand_total_currency),
                'created_at' => $ret->created_at,
                'invoice' => null,
            ];
        }

        foreach ($payments as $pay) {
            $curr = $pay->currency_code;
            $currencies[$curr] = true;

            $rawRows[] = [
                'date' => (string) $pay->payment_date->format('Y-m-d'),
                'due_date' => null,
                'type' => 'payment',
                'number' => $pay->payment_number,
                'reference' => $pay->reference_number,
                'description' => __('sales.customer_payment', [], $customer->preferred_locale ?: 'ar').' #'.$pay->payment_number,
                'currency' => $curr,
                'debit' => BigDecimal::zero(),
                'credit' => BigDecimal::of((string) $pay->amount),
                'created_at' => $pay->created_at,
                'invoice' => null,
            ];

            // If reversed, also record the reversal debit
            if ($pay->is_reversed && $pay->reversed_at !== null) {
                $rawRows[] = [
                    'date' => $pay->reversalPostingBatch->posting_date->toDateString(),
                    'due_date' => null,
                    'type' => 'payment_reversal',
                    'number' => "REV-{$pay->payment_number}",
                    'reference' => $pay->payment_number,
                    'description' => __('sales.customer_payment', [], $customer->preferred_locale ?: 'ar').' #'.$pay->payment_number,
                    'currency' => $curr,
                    'debit' => BigDecimal::of((string) $pay->amount),
                    'credit' => BigDecimal::zero(),
                    'created_at' => $pay->reversed_at,
                    'invoice' => null,
                ];
            }
        }

        // Sort all rows chronologically: date ASC, created_at ASC
        foreach (app(CrossCurrencySettlementQuery::class)->legs((int) $companyId, [(int) $customer->id], 'customer') as $leg) {
            $currencies[$leg['currency']] = true;
            $effect = BigDecimal::of($leg['amount']);
            $rawRows[] = ['id' => $leg['id'], 'date' => $leg['date'], 'due_date' => null, 'type' => 'currency_allocation', 'number' => $leg['number'],
                'reference' => $leg['number'], 'description' => __('money.currency_allocation'), 'currency' => $leg['currency'],
                'debit' => $effect->isPositive() ? $effect : BigDecimal::zero(),
                'credit' => $effect->isNegative() ? $effect->abs() : BigDecimal::zero(),
                'created_at' => $leg['created_at'], 'invoice' => null];
        }

        usort($rawRows, function ($a, $b) {
            $cmp = strcmp($a['date'], $b['date']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['created_at'], (string) $b['created_at']);
        });

        $resultCurrencies = [];

        foreach (array_keys($currencies) as $curr) {
            $runningBalance = BigDecimal::zero();
            $openingBalance = BigDecimal::zero();
            $totalDebits = BigDecimal::zero();
            $totalCredits = BigDecimal::zero();

            $entries = [];

            $minorUnits = in_array($curr, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;

            foreach ($rawRows as $row) {
                if ($row['currency'] !== $curr) {
                    continue;
                }

                $date = $row['date'];
                $isBefore = $fromDate !== null && $date < $fromDate;
                $isAfter = $toDate !== null && $date > $toDate;

                if ($isBefore) {
                    $openingBalance = $openingBalance->plus($row['debit'])->minus($row['credit']);
                    $runningBalance = $runningBalance->plus($row['debit'])->minus($row['credit']);

                    continue;
                }

                if ($isAfter) {
                    continue;
                }

                $runningBalance = $runningBalance->plus($row['debit'])->minus($row['credit']);
                $totalDebits = $totalDebits->plus($row['debit']);
                $totalCredits = $totalCredits->plus($row['credit']);

                $entries[] = [
                    'date' => $date,
                    'type' => $row['type'],
                    'number' => $row['number'],
                    'reference' => $row['reference'],
                    'description' => $row['description'],
                    'debit' => (string) $row['debit']->toScale($minorUnits),
                    'credit' => (string) $row['credit']->toScale($minorUnits),
                    'balance' => (string) $runningBalance->toScale($minorUnits),
                ];
            }

            // Calculate aging for invoices in this currency
            $aging = $this->calculateAging($customer, $curr, $toDate);

            $resultCurrencies[$curr] = [
                'currency' => $curr,
                'opening_balance' => (string) $openingBalance->toScale($minorUnits),
                'closing_balance' => (string) $runningBalance->toScale($minorUnits),
                'total_debits' => (string) $totalDebits->toScale($minorUnits),
                'total_credits' => (string) $totalCredits->toScale($minorUnits),
                'entries' => $entries,
                'aging' => $aging,
            ];
        }

        return [
            'customer' => $customer,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'currencies' => $resultCurrencies,
        ];
    }

    /**
     * Aging breakdown by due date: current, 1-30, 31-60, 61-90, 90+
     *
     * @return array{
     *     current: string,
     *     days_1_30: string,
     *     days_31_60: string,
     *     days_61_90: string,
     *     days_90_plus: string,
     *     total: string,
     * }
     */
    protected function calculateAging(Customer $customer, string $currency, ?string $asOfDate = null): array
    {
        $companyTz = $customer->company->timezone ?? config('app.timezone', 'UTC');
        $today = $asOfDate === null ? Carbon::now($companyTz)->startOfDay() : Carbon::parse($asOfDate, $companyTz)->startOfDay();
        $minorUnits = in_array($currency, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;

        $invoices = $this->documentsAtCutoff(SalesInvoice::query()
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->where('currency_code', $currency)
            ->where('issue_date', '<=', $today->toDateString()), $asOfDate);

        $unspecified = BigDecimal::zero();
        $current = BigDecimal::zero();
        $days1_30 = BigDecimal::zero();
        $days31_60 = BigDecimal::zero();
        $days61_90 = BigDecimal::zero();
        $days90Plus = BigDecimal::zero();

        foreach ($invoices as $inv) {
            $outstanding = app(ReceivablePositionAsOf::class)->outstanding($inv, $today->toDateString());
            if ($outstanding->isLessThanOrEqualTo(0)) {
                continue;
            }

            if ($inv->due_date === null) {
                $unspecified = $unspecified->plus($outstanding);

                continue;
            }
            $dueDate = Carbon::parse($inv->due_date, $companyTz)->startOfDay();
            $diffDays = $dueDate->diffInDays($today, false); // positive if overdue

            if ($diffDays <= 0) {
                // Not yet due
                $current = $current->plus($outstanding);
            } elseif ($diffDays <= 30) {
                $days1_30 = $days1_30->plus($outstanding);
            } elseif ($diffDays <= 60) {
                $days31_60 = $days31_60->plus($outstanding);
            } elseif ($diffDays <= 90) {
                $days61_90 = $days61_90->plus($outstanding);
            } else {
                $days90Plus = $days90Plus->plus($outstanding);
            }
        }

        $total = $unspecified->plus($current)->plus($days1_30)->plus($days31_60)->plus($days61_90)->plus($days90Plus);

        return [
            'unspecified' => (string) $unspecified->toScale($minorUnits),
            'current' => (string) $current->toScale($minorUnits),
            'days_1_30' => (string) $days1_30->toScale($minorUnits),
            'days_31_60' => (string) $days31_60->toScale($minorUnits),
            'days_61_90' => (string) $days61_90->toScale($minorUnits),
            'days_90_plus' => (string) $days90Plus->toScale($minorUnits),
            'total' => (string) $total->toScale($minorUnits),
        ];
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Collection<int|string, T>
     */
    private function documentsAtCutoff(Builder $query, ?string $date): Collection
    {
        if ($date === null) {
            return $query->where('status', 'posted')->get();
        }

        $documents = $query->whereIn('status', ['posted', 'void'])->where('issue_date', '<=', $date)
            ->with(['postingBatch', 'voidPostingBatch'])->get();
        foreach ($documents as $key => $document) {
            if (! $document instanceof SalesInvoice && ! $document instanceof SalesReturn) {
                throw new InvalidArgumentException('Statement lifecycle selection requires a Sales document.');
            }
            if (! $document->isVoid()) {
                continue;
            }

            $original = $document->postingBatch;
            $inverse = $document->voidPostingBatch;
            $sourceType = $document instanceof SalesInvoice ? 'sales_invoice' : 'sales_return';
            if ($original === null || $inverse === null
                || (int) $original->company_id !== (int) $document->company_id
                || (int) $inverse->company_id !== (int) $document->company_id
                || $original->source_type !== $sourceType || (int) $original->source_id !== (int) $document->id
                || $original->status !== PostingBatch::STATUS_REVERSED
                || (int) $original->reversed_by_batch_id !== (int) $inverse->id
                || $inverse->source_type !== 'reversal' || $inverse->status !== PostingBatch::STATUS_POSTED
                || (int) $inverse->source_id !== (int) $original->id
                || (int) $inverse->reversal_of_id !== (int) $original->id
                || $inverse->getRawOriginal('posting_date') === null || $original->getRawOriginal('posting_date') === null
                || $inverse->posting_date->lt($original->posting_date)) {
                throw new InvalidArgumentException('Historical statement requires coherent canonical void provenance.');
            }

            if ($inverse->posting_date->toDateString() <= $date) {
                $documents->forget($key);
            }
        }

        return $documents;
    }
}
