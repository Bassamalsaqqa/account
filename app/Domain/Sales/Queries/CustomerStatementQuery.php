<?php

declare(strict_types=1);

namespace App\Domain\Sales\Queries;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PublicShare;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;

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
            throw new \InvalidArgumentException('Statement share does not authorize this customer.');
        }

        return $this->read($customer);
    }

    /** @return array<string, mixed> */
    private function read(Customer $customer, ?string $fromDate = null, ?string $toDate = null): array
    {
        $companyId = $customer->company_id;

        // 1. Gather all posted transactions for this customer
        $invoices = SalesInvoice::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('status', SalesInvoice::STATUS_POSTED)
            ->get();

        $returns = SalesReturn::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->where('status', SalesReturn::STATUS_POSTED)
            ->get();

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
                    'date' => (string) $pay->reversed_at->format('Y-m-d'),
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
            $aging = $this->calculateAging($customer, $curr);

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
    protected function calculateAging(Customer $customer, string $currency): array
    {
        $companyTz = $customer->company->timezone ?? config('app.timezone', 'UTC');
        $today = Carbon::now($companyTz)->startOfDay();
        $minorUnits = in_array($currency, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;

        $invoices = SalesInvoice::query()
            ->where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->where('currency_code', $currency)
            ->where('status', SalesInvoice::STATUS_POSTED)
            ->get();

        $unspecified = BigDecimal::zero();
        $current = BigDecimal::zero();
        $days1_30 = BigDecimal::zero();
        $days31_60 = BigDecimal::zero();
        $days61_90 = BigDecimal::zero();
        $days90Plus = BigDecimal::zero();

        foreach ($invoices as $inv) {
            $outstanding = $inv->calculateOutstanding();
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
}
