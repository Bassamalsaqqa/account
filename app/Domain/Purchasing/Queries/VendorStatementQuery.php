<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Queries;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\Purchasing\PurchasePayableAsOf;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;

class VendorStatementQuery
{
    /**
     * Generate chronological vendor statement grouped by currency with aging breakdown.
     * In AP:
     * - Purchases are CREDITS (increase liability)
     * - Returns are DEBITS (decrease liability)
     * - Payments are DEBITS (decrease liability)
     * - Payment reversals are CREDITS (restore liability on reversal date)
     * Running balance = credits - debits (positive = payable, negative = credit/advance).
     *
     * @return array{
     *     vendor: Vendor,
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
     *             unspecified: string,
     *             current: string,
     *             days_1_30: string,
     *             days_31_60: string,
     *             days_61_90: string,
     *             days_90_plus: string,
     *             gross_open_purchases: string,
     *             signed_vendor_balance: string,
     *             unapplied_credit_position: string,
     *             net_payable: string,
     *             net_vendor_credit: string,
     *         }
     *     }>
     * }
     */
    public function execute(Vendor $vendor, ?string $fromDate = null, ?string $toDate = null): array
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $vendor->company_id) {
            throw new NoActiveCompanyException('Vendor statements require matching company context.');
        }

        $user = auth()->user();
        if ($user === null || ! $user->fresh()?->belongsToCompany((int) $vendor->company_id)) {
            throw new AuthorizationException('User does not have an active membership in this company.');
        }

        if (! $user->hasPermissionTo('vendors.statement.view') || ! $user->hasPermissionTo('purchasing.cost.view')) {
            throw new AuthorizationException('User does not have permission to view vendor statement.');
        }

        return $this->read($vendor, $fromDate, $toDate);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(Vendor $vendor, ?string $fromDate = null, ?string $toDate = null): array
    {
        $companyId = (int) $vendor->company_id;
        $companyTz = Company::findOrFail($companyId)->timezone;
        $asOf = Carbon::parse($toDate ?? Carbon::now($companyTz)->toDateString(), $companyTz)->startOfDay();
        $cutoffDate = $asOf->toDateString();

        // 1. Gather all posted transactions for this vendor
        $purchases = Purchase::query()
            ->where('company_id', $companyId)
            ->where('vendor_id', $vendor->id)
            ->where('status', Purchase::STATUS_POSTED)
            ->get();

        $returns = PurchaseReturn::query()
            ->where('company_id', $companyId)
            ->where('vendor_id', $vendor->id)
            ->where('status', Purchase::STATUS_POSTED)
            ->get();

        $payments = VendorPayment::query()->with('reversalPostingBatch')
            ->where('company_id', $companyId)
            ->where('vendor_id', $vendor->id)
            ->whereNotNull('posting_batch_id')
            ->get();

        $currencies = [];
        $rawRows = [];

        foreach ($purchases as $pur) {
            $curr = $pur->currency_code;
            $currencies[$curr] = true;
            $rawRows[] = [
                'id' => (int) $pur->id,
                'date' => (string) $pur->purchase_date->format('Y-m-d'),
                'due_date' => (string) $pur->due_date?->format('Y-m-d'),
                'type' => 'purchase',
                'number' => $pur->purchase_number,
                'reference' => $pur->vendor_invoice_number,
                'description' => __('purchasing.purchase').' #'.$pur->purchase_number,
                'currency' => $curr,
                'debit' => BigDecimal::zero(),
                'credit' => BigDecimal::of((string) $pur->grand_total_currency),
                'created_at' => $pur->created_at,
            ];
        }

        foreach ($returns as $ret) {
            $curr = $ret->currency_code;
            $currencies[$curr] = true;
            $rawRows[] = [
                'id' => (int) $ret->id,
                'date' => (string) $ret->return_date->format('Y-m-d'),
                'due_date' => null,
                'type' => 'purchase_return',
                'number' => $ret->return_number,
                'reference' => $ret->purchase?->purchase_number,
                'description' => __('purchasing.purchase_return').' #'.$ret->return_number,
                'currency' => $curr,
                'debit' => BigDecimal::of((string) $ret->grand_total_currency),
                'credit' => BigDecimal::zero(),
                'created_at' => $ret->created_at,
            ];
        }

        foreach ($payments as $pay) {
            $curr = $pay->currency_code;
            $currencies[$curr] = true;

            $payDate = $pay->payment_date instanceof Carbon ? $pay->payment_date->format('Y-m-d') : (string) $pay->payment_date;
            $rawRows[] = [
                'id' => (int) $pay->id,
                'date' => $payDate,
                'due_date' => null,
                'type' => 'vendor_payment',
                'number' => $pay->payment_number,
                'reference' => $pay->reference_number,
                'description' => __('purchasing.vendor_payment').' #'.$pay->payment_number,
                'currency' => $curr,
                'debit' => BigDecimal::of((string) $pay->amount),
                'credit' => BigDecimal::zero(),
                'created_at' => $pay->created_at,
            ];

            // If reversed, record reversal credit on the reversal date
            if ($pay->is_reversed && $pay->reversed_at !== null) {
                // The immutable business date survives later Company timezone changes.
                $reversal = $pay->reversalPostingBatch;
                if ($reversal === null || (int) $reversal->company_id !== $companyId || (int) $reversal->reversal_of_id !== (int) $pay->posting_batch_id) {
                    throw new ImmutableRecordException('Vendor statement reversal provenance is incoherent.');
                }
                $revDate = $reversal->posting_date->toDateString();
                $rawRows[] = [
                    'id' => (int) $pay->id,
                    'date' => $revDate,
                    'due_date' => null,
                    'type' => 'payment_reversal',
                    'number' => "REV-{$pay->payment_number}",
                    'reference' => $pay->payment_number,
                    'description' => __('purchasing.vendor_payment_reversal').' #'.$pay->payment_number,
                    'currency' => $curr,
                    'debit' => BigDecimal::zero(),
                    'credit' => BigDecimal::of((string) $pay->amount),
                    'created_at' => $pay->reversed_at,
                ];
            }
        }

        // Sort all rows deterministically: date ASC, created_at ASC, id ASC
        usort($rawRows, function (array $a, array $b): int {
            $cmp = strcmp($a['date'], $b['date']);
            if ($cmp !== 0) {
                return $cmp;
            }

            $createdCmp = strcmp((string) $a['created_at'], (string) $b['created_at']);
            if ($createdCmp !== 0) {
                return $createdCmp;
            }

            return $a['id'] <=> $b['id'];
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
                $isAfter = $date > $cutoffDate;
                if ($isAfter) {
                    continue;
                }

                // Running balance in AP = credits - debits
                $netEffect = $row['credit']->minus($row['debit']);

                if ($isBefore) {
                    $openingBalance = $openingBalance->plus($netEffect);
                    $runningBalance = $runningBalance->plus($netEffect);

                    continue;
                }

                $runningBalance = $runningBalance->plus($netEffect);
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

            $aging = $this->calculateAging($vendor, $curr, $asOf, $runningBalance);

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
            'vendor' => $vendor,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'currencies' => $resultCurrencies,
        ];
    }

    /**
     * Aging breakdown by due date: current, 1-30, 31-60, 61-90, 90+, unspecified.
     * Also computes:
     * - gross_open_purchases = sum positive Purchase outstanding
     * - signed_vendor_balance = Purchases - Returns - active Payments
     * - unapplied_credit_position = gross_open_purchases - signed_vendor_balance (>= 0)
     * - net_payable = max(signed_vendor_balance, 0)
     * - net_vendor_credit = max(-signed_vendor_balance, 0)
     *
     * @return array{
     *     unspecified: string,
     *     current: string,
     *     days_1_30: string,
     *     days_31_60: string,
     *     days_61_90: string,
     *     days_90_plus: string,
     *     gross_open_purchases: string,
     *     signed_vendor_balance: string,
     *     unapplied_credit_position: string,
     *     net_payable: string,
     *     net_vendor_credit: string,
     * }
     */
    protected function calculateAging(Vendor $vendor, string $currency, Carbon $asOf, BigDecimal $signedBalance): array
    {
        $companyTz = $asOf->getTimezone()->getName();
        $minorUnits = in_array($currency, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;

        $purchases = Purchase::query()
            ->where('company_id', $vendor->company_id)
            ->where('vendor_id', $vendor->id)
            ->where('currency_code', $currency)
            ->where('purchase_date', '<=', $asOf->toDateString())
            ->where('status', Purchase::STATUS_POSTED)
            ->get();

        $unspecified = BigDecimal::zero();
        $current = BigDecimal::zero();
        $days1_30 = BigDecimal::zero();
        $days31_60 = BigDecimal::zero();
        $days61_90 = BigDecimal::zero();
        $days90Plus = BigDecimal::zero();
        $grossOpen = BigDecimal::zero();

        $positions = app(PurchasePayableAsOf::class)->forPurchases($purchases, $asOf);
        foreach ($purchases as $pur) {
            $outstanding = $positions[(int) $pur->id];
            if ($outstanding->isLessThanOrEqualTo(0)) {
                continue;
            }

            $grossOpen = $grossOpen->plus($outstanding);

            if ($pur->due_date === null) {
                $unspecified = $unspecified->plus($outstanding);

                continue;
            }

            $dueDate = Carbon::parse($pur->due_date, $companyTz)->startOfDay();
            $diffDays = $dueDate->diffInDays($asOf, false); // positive if overdue

            if ($diffDays <= 0) {
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

        // Reuse the statement closing principal balance: identical business-date cutoff,
        // including reversals in company-local time; applications add no principal.
        $unappliedCredit = $grossOpen->minus($signedBalance);
        if ($unappliedCredit->isNegative()) {
            $unappliedCredit = BigDecimal::zero();
        }

        $netPayable = $signedBalance->isPositive() ? $signedBalance : BigDecimal::zero();
        $netVendorCredit = $signedBalance->isNegative() ? $signedBalance->negated() : BigDecimal::zero();

        return [
            'unspecified' => (string) $unspecified->toScale($minorUnits),
            'current' => (string) $current->toScale($minorUnits),
            'days_1_30' => (string) $days1_30->toScale($minorUnits),
            'days_31_60' => (string) $days31_60->toScale($minorUnits),
            'days_61_90' => (string) $days61_90->toScale($minorUnits),
            'days_90_plus' => (string) $days90Plus->toScale($minorUnits),
            'gross_open_purchases' => (string) $grossOpen->toScale($minorUnits),
            'signed_vendor_balance' => (string) $signedBalance->toScale($minorUnits),
            'unapplied_credit_position' => (string) $unappliedCredit->toScale($minorUnits),
            'net_payable' => (string) $netPayable->toScale($minorUnits),
            'net_vendor_credit' => (string) $netVendorCredit->toScale($minorUnits),
        ];
    }
}
