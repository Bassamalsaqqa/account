<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\TradeProvenance;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Narrow source read for issued invoices; does not calculate financial history. */
final class AssistantReadTools
{
    public function latestSalesInvoice(Company $company, User $actor, AssistantReportPlan $plan): AssistantReportDto
    {
        if ($plan->reportKey !== 'sales.last-invoice') {
            throw new AssistantUnavailable('question_unsupported');
        }
        $catalog = app(AssistantReportCatalog::class);
        // The catalog requires explicit persisted company allowed_reports activation
        // and fresh active-company/source permissions, including on direct calls.
        $catalog->authorize($company, $actor, $plan->reportKey);
        $company = app(ReportingGuard::class)->company($company, $actor);
        $filters = AssistantReportEntityResolver::resolve($company, $actor, $plan);
        $customerId = $filters['customer_id'] ?? null;
        if (! is_int($customerId) || array_diff(array_keys($filters), ['customer_id', 'page', 'per_page']) !== []) {
            throw new AssistantUnavailable('question_invalid');
        }
        TradeProvenance::sales($company);

        // Same ordering as the invoice source screen. Fully paid invoices and
        // issued void documents remain eligible; drafts are never historical facts.
        $invoice = SalesInvoice::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('customer_id', $customerId)
            ->whereIn('status', [SalesInvoice::STATUS_POSTED, SalesInvoice::STATUS_VOID])
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->first(['public_id', 'invoice_number', 'issue_date', 'due_date', 'status', 'currency_code',
                'subtotal_currency', 'discount_total_currency', 'tax_total_currency', 'grand_total_currency', 'grand_total_base']);
        $rows = [];
        if ($invoice !== null) {
            $rows[] = [
                'invoice_number' => mb_substr((string) $invoice->invoice_number, 0, AssistantReportExecutor::MAX_STRING_LENGTH),
                'issue_date' => $invoice->issue_date->format('Y-m-d'),
                'due_date' => $invoice->due_date?->format('Y-m-d'),
                'status' => $invoice->status,
                'currency_code' => $invoice->currency_code,
                'subtotal_currency' => (string) $invoice->subtotal_currency,
                'discount_total_currency' => (string) $invoice->discount_total_currency,
                'tax_total_currency' => (string) $invoice->tax_total_currency,
                'grand_total_currency' => (string) $invoice->grand_total_currency,
                'grand_total_base' => (string) $invoice->grand_total_base,
            ];
        }
        $definition = $catalog->definition($plan->reportKey);
        $title = $definition[app()->getLocale() === 'ar' ? 'name_ar' : 'name_en'];
        $generatedAt = CarbonImmutable::now($company->timezone)->toIso8601String();
        $dto = new AssistantReportDto(
            reportKey: $plan->reportKey, title: $title, periodLabel: $title,
            timezone: (string) $company->timezone, generatedAt: $generatedAt,
            currency: ['base_currency_code' => (string) $company->base_currency_code, 'filter_currency_code' => null],
            totals: [], rows: $rows, totalRows: count($rows),
            sourceUrl: $invoice === null
                ? route('invoices.index', ['customer' => $customerId])
                : route('invoices.show', ['publicId' => $invoice->public_id]),
            isCurrent: true, asOf: $generatedAt,
            scope: ['basis' => 'latest_issued_invoice', 'ordering' => 'issue_date_desc_id_desc',
                'filters' => ['customer_name' => $plan->filters['customer_name']]],
        );
        if (strlen(json_encode($dto->toArray(), JSON_THROW_ON_ERROR)) > AssistantReportExecutor::MAX_OUTPUT_BYTES) {
            throw new AssistantUnavailable('output_limit_exceeded');
        }
        $catalog->authorize($company, $actor, $plan->reportKey);

        return $dto;
    }
}
