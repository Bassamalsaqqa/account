<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CompanyInventorySettings;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ConvertQuotationToInvoiceAction
{
    /**
     * Idempotently convert an accepted quotation into a sales invoice draft.
     */
    public function execute(Quotation $quotation, User $user, ?int $warehouseId = null): SalesInvoice
    {
        return DB::transaction(function () use ($quotation, $user, $warehouseId): SalesInvoice {
            $context = app(CompanyContext::class);
            if (! $context->hasCompany() || (int) $context->companyId() !== (int) $quotation->company_id) {
                throw new NoActiveCompanyException("Active company context does not match quotation company [{$quotation->company_id}].");
            }

            if (! auth()->check() || (int) auth()->id() !== (int) $user->id) {
                throw new AuthorizationException('Actor must be authenticated and match user.');
            }

            if (! $user->belongsToCompany($quotation->company_id)) {
                throw new AuthorizationException("User does not belong to company [{$quotation->company_id}].");
            }

            setPermissionsTeamId($quotation->company_id);

            if (! $user->hasPermissionTo('sales.quote.convert')) {
                throw new AuthorizationException('User does not have permission to convert quotations.');
            }

            // 1. Lock company FOR UPDATE first
            /** @var Company $company */
            $company = Company::where('id', $quotation->company_id)->lockForUpdate()->firstOrFail();
            app(SalesActorGuard::class)->lockAndAuthorize((int) $quotation->company_id, $user, 'sales.quote.convert');

            // 2. Lock quotation FOR UPDATE second
            /** @var Quotation $lockedQuote */
            $lockedQuote = Quotation::where('id', $quotation->id)->lockForUpdate()->firstOrFail();

            // Idempotent retry: if already converted and invoice exists, return that invoice
            if ($lockedQuote->isConverted() && $lockedQuote->converted_to_invoice_id !== null) {
                $existingInvoice = SalesInvoice::find($lockedQuote->converted_to_invoice_id);
                if ($existingInvoice !== null && (int) $existingInvoice->quotation_id === (int) $lockedQuote->id && (int) $existingInvoice->company_id === (int) $lockedQuote->company_id && (int) $existingInvoice->customer_id === (int) $lockedQuote->customer_id) {
                    return $existingInvoice->load('lines');
                }
            }

            if (! $lockedQuote->isAccepted()) {
                throw new InvalidArgumentException('Only accepted quotations may be converted.');
            }

            /** @var Customer $customer */
            $customer = Customer::where('company_id', $company->id)->where('id', $lockedQuote->customer_id)->lockForUpdate()->firstOrFail();
            if (! $customer->active) {
                throw new InvalidArgumentException("Cannot convert quotation for inactive customer [{$customer->id}].");
            }

            // Determine warehouse
            $resolvedWarehouseId = $warehouseId;
            if ($resolvedWarehouseId === null) {
                $invSettings = CompanyInventorySettings::where('company_id', $company->id)->first();
                $resolvedWarehouseId = $invSettings?->default_warehouse_id;

                if ($resolvedWarehouseId === null) {
                    $firstWh = Warehouse::where('company_id', $company->id)->where('active', true)->first();
                    $resolvedWarehouseId = $firstWh?->id;
                }
            }

            if ($resolvedWarehouseId !== null) {
                $wh = Warehouse::where('company_id', $company->id)->where('id', $resolvedWarehouseId)->first();
                if ($wh === null || ! $wh->active) {
                    $resolvedWarehouseId = null;
                }
            }

            $issueDate = Carbon::today($company->timezone)->toDateString();
            $dueDate = Carbon::today($company->timezone)->addDays(30)->toDateString();

            // Create SalesInvoice draft
            $invoice = SalesInvoice::createFromAcceptedQuotation($lockedQuote, $user, [
                'public_id' => (string) Str::ulid(),
                'company_id' => $company->id,
                'invoice_number' => null, // Draft has NO final number
                'customer_id' => $customer->id,
                'warehouse_id' => $resolvedWarehouseId,
                'quotation_id' => $lockedQuote->id,
                'currency_code' => $lockedQuote->currency_code,
                'exchange_rate' => $lockedQuote->exchange_rate,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'status' => SalesInvoice::STATUS_DRAFT,
                'subtotal_base' => $lockedQuote->subtotal_base,
                'discount_total_base' => $lockedQuote->discount_total_base,
                'tax_total_base' => $lockedQuote->tax_total_base,
                'grand_total_base' => $lockedQuote->grand_total_base,
                'cogs_total_base' => '0.000000',
                'subtotal_currency' => $lockedQuote->subtotal_currency,
                'discount_total_currency' => $lockedQuote->discount_total_currency,
                'tax_total_currency' => $lockedQuote->tax_total_currency,
                'grand_total_currency' => $lockedQuote->grand_total_currency,
                'notes' => $lockedQuote->notes,
                'terms' => $lockedQuote->terms,
                'document_locale' => $lockedQuote->document_locale,
                'customer_snapshot' => $lockedQuote->customer_snapshot,
                'company_snapshot' => $lockedQuote->company_snapshot,
                'created_by' => $user->id,
            ]);

            // Copy lines
            $quoteLines = $lockedQuote->lines;
            foreach ($quoteLines as $qLine) {
                SalesInvoiceLine::create([
                    'public_id' => (string) Str::ulid(),
                    'company_id' => $company->id,
                    'sales_invoice_id' => $invoice->id,
                    'line_number' => $qLine->line_number,
                    'product_id' => $qLine->product_id,
                    'product_unit_id' => $qLine->product_unit_id,
                    'item_description' => $qLine->item_description,
                    'quantity' => $qLine->quantity,
                    'unit_price' => $qLine->unit_price,
                    'discount_type' => $qLine->discount_type,
                    'discount_value' => $qLine->discount_value,
                    'tax_rate_id' => $qLine->tax_rate_id,
                    'tax_rate_snapshot' => $qLine->tax_rate_snapshot,
                    'tax_inclusive' => $qLine->tax_inclusive,
                    'line_subtotal' => $qLine->line_subtotal,
                    'line_discount' => $qLine->line_discount,
                    'line_tax' => $qLine->line_tax,
                    'line_total' => $qLine->line_total,
                    'line_subtotal_base' => (string) BigDecimal::of($qLine->line_subtotal)->multipliedBy($lockedQuote->exchange_rate)->toScale(6, RoundingMode::HALF_UP),
                    'line_discount_base' => (string) BigDecimal::of($qLine->line_discount)->multipliedBy($lockedQuote->exchange_rate)->toScale(6, RoundingMode::HALF_UP),
                    'line_tax_base' => (string) BigDecimal::of($qLine->line_tax)->multipliedBy($lockedQuote->exchange_rate)->toScale(6, RoundingMode::HALF_UP),
                    'line_total_base' => $qLine->line_total_base,
                    'cogs_total_base' => '0.000000',
                    'cogs_unit_base' => '0.000000',
                    'unit_conversion_ratio' => $qLine->unit_conversion_ratio,
                    'quantity_base' => $qLine->quantity_base,
                    'unit_name_ar' => $qLine->unit_name_ar,
                    'unit_name_en' => $qLine->unit_name_en,
                    'product_sku' => $qLine->product_sku,
                    'product_name_ar' => $qLine->product_name_ar,
                    'product_name_en' => $qLine->product_name_en,
                ]);
            }

            // Update quotation to converted
            $lockedQuote->transition(Quotation::STATUS_CONVERTED, $user, $invoice);

            return $invoice->load(['lines', 'customer']);
        });
    }
}
