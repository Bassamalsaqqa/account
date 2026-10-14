<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Sales\DocumentDataBuilder;
use Carbon\CarbonImmutable;

/**
 * Bounded read-only projection for current sales invoice context.
 *
 * Policy Decision:
 * To project the on-screen current sales invoice without expanding global report tools
 * or catalog allowlists, we reuse the existing company policy capability 'sales.last-invoice'
 * as the policy gate, combined with fresh 'sales.invoice.view' permission check and active
 * company tenancy.
 *
 * Never calculates financial history; reads canonical DocumentDataBuilder & SalesInvoice.
 * No floats for money, exact string decimals only, strictly bounds lines and redacts internal costs.
 */
final class AssistantInvoiceAnswer
{
    public function answer(Company $company, User $actor, string $publicId): AssistantReportDto
    {
        $catalog = app(AssistantReportCatalog::class);
        $catalog->authorize($company, $actor, 'sales.last-invoice');

        // 3. Fresh tenant-isolated invoice read
        $invoice = SalesInvoice::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->first();
        if ($invoice === null) {
            throw new AssistantUnavailable('proposal_stale');
        }

        // 4. Canonical document data projection (no new calculations, no writes)
        $locale = in_array(app()->getLocale(), ['ar', 'en'], true) ? app()->getLocale() : 'ar';
        $documentData = app(DocumentDataBuilder::class)->build($invoice, false, $locale);
        $doc = $documentData->document;

        // 5. Exact canonical decimal strings only (no PHP floats)
        $number = (string) ($doc['number'] ?: $invoice->public_id);
        $status = (string) $doc['status'];
        $paymentStatus = (string) ($doc['payment_status'] ?? $invoice->derivedPaymentStatus());
        $issueDate = (string) $doc['issue_date'];
        $dueDate = $doc['due_date'] ?? null;
        $currencyCode = (string) $doc['currency_code'];
        $subtotal = (string) $doc['subtotal'];
        $discount = (string) $doc['discount_total'];
        $tax = (string) $doc['tax_total'];
        $grandTotal = (string) $doc['grand_total'];
        $outstanding = $invoice->isPosted() ? (string) $invoice->calculateOutstanding() : null;

        // 6. Optional bounded line items (at most 5 lines, sanitized of cost/profit fields)
        $boundedLines = [];
        foreach (array_slice($documentData->lines, 0, 5) as $line) {
            foreach (['line_number', 'item_description', 'quantity', 'unit_price', 'discount', 'tax', 'total'] as $field) {
                if (! isset($line[$field])) {
                    throw new AssistantUnavailable('question_invalid');
                }
            }
            $boundedLines[] = [
                'line_number' => $line['line_number'],
                'item_description' => mb_substr($line['item_description'], 0, AssistantReportExecutor::MAX_STRING_LENGTH),
                'unit_name' => isset($line['unit_name']) ? mb_substr((string) $line['unit_name'], 0, AssistantReportExecutor::MAX_STRING_LENGTH) : null,
                'sku' => isset($line['sku']) ? mb_substr((string) $line['sku'], 0, AssistantReportExecutor::MAX_STRING_LENGTH) : null,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'discount' => $line['discount'],
                'tax' => $line['tax'],
                'total' => $line['total'],
            ];
        }

        // 7. Structured row and totals
        $row = [
            'invoice_number' => mb_substr($number, 0, AssistantReportExecutor::MAX_STRING_LENGTH),
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'currency_code' => $currencyCode,
            'subtotal_currency' => $subtotal,
            'discount_total_currency' => $discount,
            'tax_total_currency' => $tax,
            'grand_total_currency' => $grandTotal,
            'outstanding_balance' => $outstanding,
        ];

        $totals = [
            'subtotal_currency' => $subtotal,
            'discount_total_currency' => $discount,
            'tax_total_currency' => $tax,
            'grand_total_currency' => $grandTotal,
            'outstanding_balance' => $outstanding,
        ];

        $title = __('assistant_invoice.title');
        $generatedAt = CarbonImmutable::now($company->timezone)->toIso8601String();

        $dto = new AssistantReportDto(
            reportKey: 'sales.last-invoice',
            title: $title,
            periodLabel: $title,
            timezone: (string) $company->timezone,
            generatedAt: $generatedAt,
            currency: [
                'base_currency_code' => (string) $company->base_currency_code,
                'filter_currency_code' => $currencyCode,
            ],
            totals: $totals,
            rows: [$row],
            totalRows: 1,
            sourceUrl: route('invoices.show', ['publicId' => $invoice->public_id]),
            isCurrent: true,
            asOf: $generatedAt,
            scope: [
                'basis' => 'current_invoice',
                'invoice_public_id' => $invoice->public_id,
                'payment_status' => $paymentStatus,
                'lines' => $boundedLines,
                'total_lines' => count($documentData->lines),
            ],
        );

        if (strlen(json_encode($dto->toArray(), JSON_THROW_ON_ERROR)) > AssistantReportExecutor::MAX_OUTPUT_BYTES) {
            throw new AssistantUnavailable('output_limit_exceeded');
        }

        // 8. Re-check permissions before returning
        $catalog->authorize($company, $actor, 'sales.last-invoice');

        return $dto;
    }
}
