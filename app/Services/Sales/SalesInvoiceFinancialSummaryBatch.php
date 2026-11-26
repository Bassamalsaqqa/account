<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Exceptions\NoActiveCompanyException;
use App\Models\CustomerPaymentAllocation;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Ephemeral request-local batch financial summary projection for SalesInvoice records.
 * Canonical single-invoice calculations live on SalesInvoice::calculateFinancialSummary().
 */
final class SalesInvoiceFinancialSummaryBatch
{
    /**
     * @param  iterable<mixed>  $invoices
     * @return array<int, array{
     *     paid: BigDecimal,
     *     returned: BigDecimal,
     *     outstanding: BigDecimal,
     *     payment_status: string
     * }>
     */
    public function summarize(iterable $invoices, ?int $expectedCompanyId = null): array
    {
        $items = [];
        foreach ($invoices as $invoice) {
            if (! $invoice instanceof SalesInvoice) {
                throw new InvalidArgumentException('Invoice relief batch expects SalesInvoice instances.');
            }
            $items[] = $invoice;
        }

        if ($items === []) {
            return [];
        }

        $context = app(CompanyContext::class);
        if (! $context->hasCompany()) {
            throw new NoActiveCompanyException('An active company context is required to summarize invoice relief.');
        }

        $activeCompanyId = (int) $context->companyId();
        if ($expectedCompanyId !== null && $expectedCompanyId !== $activeCompanyId) {
            throw new InvalidArgumentException('Expected company context mismatch for invoice relief batch.');
        }

        $resolvedCompanyId = null;
        $postedIds = [];
        foreach ($items as $invoice) {
            $itemCompanyId = (int) $invoice->company_id;
            if ($itemCompanyId <= 0) {
                throw new InvalidArgumentException('Invoice relief batch requires valid positive company_id on every invoice.');
            }
            if ($resolvedCompanyId === null) {
                $resolvedCompanyId = $itemCompanyId;
            } elseif ($itemCompanyId !== $resolvedCompanyId) {
                throw new InvalidArgumentException('Invoice relief batch cannot mix invoices from multiple companies.');
            }
            if ($itemCompanyId !== $activeCompanyId) {
                throw new InvalidArgumentException('Invoice relief batch does not match active company context.');
            }
            if ($invoice->isPosted()) {
                $postedIds[(int) $invoice->id] = (int) $invoice->id;
            }
        }

        $postedIds = array_values($postedIds);

        /** @var array<int, string> $paidByInvoice */
        $paidByInvoice = [];
        /** @var array<int, string> $returnedByInvoice */
        $returnedByInvoice = [];

        if ($postedIds !== []) {
            $paidRows = CustomerPaymentAllocation::query()
                ->where('company_id', $resolvedCompanyId)
                ->whereIn('sales_invoice_id', $postedIds)
                ->active()
                ->selectRaw('sales_invoice_id, SUM(allocated_amount) AS total_allocated')
                ->groupBy('sales_invoice_id')
                ->get();

            foreach ($paidRows as $row) {
                $total = $row->getAttribute('total_allocated');
                if ($total !== null) {
                    $paidByInvoice[(int) $row->getAttribute('sales_invoice_id')] = (string) $total;
                }
            }

            $returnRows = SalesReturn::query()
                ->where('company_id', $resolvedCompanyId)
                ->whereIn('sales_invoice_id', $postedIds)
                ->where('status', SalesReturn::STATUS_POSTED)
                ->selectRaw('sales_invoice_id, SUM(grand_total_currency) AS total_returned')
                ->groupBy('sales_invoice_id')
                ->get();

            foreach ($returnRows as $row) {
                $total = $row->getAttribute('total_returned');
                if ($total !== null) {
                    $returnedByInvoice[(int) $row->getAttribute('sales_invoice_id')] = (string) $total;
                }
            }
        }

        $summaries = [];
        foreach ($items as $invoice) {
            $id = (int) $invoice->id;
            if (! $invoice->isPosted()) {
                $summaries[$id] = [
                    'paid' => BigDecimal::zero(),
                    'returned' => BigDecimal::zero(),
                    'outstanding' => BigDecimal::zero(),
                    'payment_status' => SalesInvoice::PAYMENT_STATUS_UNPAID,
                ];

                continue;
            }

            $grandTotal = BigDecimal::of((string) $invoice->grand_total_currency);
            $paidTotal = isset($paidByInvoice[$id]) ? BigDecimal::of($paidByInvoice[$id]) : BigDecimal::zero();
            $returnedTotal = isset($returnedByInvoice[$id]) ? BigDecimal::of($returnedByInvoice[$id]) : BigDecimal::zero();
            $outstanding = $grandTotal->minus($paidTotal)->minus($returnedTotal);

            if ($outstanding->isLessThan(0)) {
                $paymentStatus = SalesInvoice::PAYMENT_STATUS_CREDIT;
            } elseif ($outstanding->isZero()) {
                $paymentStatus = SalesInvoice::PAYMENT_STATUS_PAID;
            } elseif ($outstanding->isEqualTo($grandTotal)) {
                $paymentStatus = SalesInvoice::PAYMENT_STATUS_UNPAID;
            } else {
                $paymentStatus = SalesInvoice::PAYMENT_STATUS_PARTIALLY_PAID;
            }

            $summaries[$id] = [
                'paid' => $paidTotal,
                'returned' => $returnedTotal,
                'outstanding' => $outstanding,
                'payment_status' => $paymentStatus,
            ];
        }

        return $summaries;
    }
}
