<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\User;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesReturnAmounts;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateSalesReturnDraftAction
{
    public function __construct(
        protected SalesLineCalculator $lineCalculator,
        protected SalesDocumentTotalsCalculator $totalsCalculator,
    ) {}

    /**
     * @param  array{
     *     sales_invoice_id: int,
     *     issue_date: string,
     *     reason?: ?string,
     *     notes?: ?string,
     *     lines: list<array{
     *         sales_invoice_line_id: int,
     *         quantity: string|BigDecimal,
     *     }>
     * }  $data
     */
    public function execute(Company $company, User $user, array $data): SalesReturn
    {
        return DB::transaction(function () use ($company, $user, $data): SalesReturn {
            // Lock company FOR UPDATE
            Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $user, 'sales.return.create');

            $context = app(CompanyContext::class);
            if (! $context->hasCompany() || (int) $context->companyId() !== (int) $company->id) {
                throw new NoActiveCompanyException("Active company context does not match company [{$company->id}].");
            }

            if (! auth()->check() || (int) auth()->id() !== (int) $user->id) {
                throw new AuthorizationException('Actor must be authenticated and match user.');
            }

            if (! $user->belongsToCompany($company->id)) {
                throw new AuthorizationException("User does not belong to company [{$company->id}].");
            }

            setPermissionsTeamId($company->id);

            if (! $user->hasPermissionTo('sales.return.create')) {
                throw new AuthorizationException('User does not have permission to create sales return drafts.');
            }

            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::where('company_id', $company->id)->where('id', $data['sales_invoice_id'])->firstOrFail();
            if (! $invoice->isPosted()) {
                throw new InvalidArgumentException("Cannot create return for unposted sales invoice [{$invoice->id}].");
            }

            if (empty($data['lines'])) {
                throw new InvalidArgumentException('Sales return must contain at least one line.');
            }

            $customer = $invoice->customer;
            $currency = $invoice->currency_code;
            $fx = BigDecimal::of((string) $invoice->exchange_rate);
            $minorUnits = in_array($currency, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;
            $baseMinorUnits = in_array($company->base_currency_code, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;

            $lineResults = [];
            $preparedLines = [];

            $groupedLines = [];
            foreach ($data['lines'] as $input) {
                $id = $input['sales_invoice_line_id'];
                $quantity = Quantity::of($input['quantity']);
                if (! $quantity->isPositive()) {
                    throw new InvalidArgumentException('Return quantities must be positive.');
                }
                $groupedLines[$id] = ($groupedLines[$id] ?? BigDecimal::zero())->plus((string) $quantity);
            }
            $data['lines'] = [];
            foreach ($groupedLines as $id => $quantity) {
                $data['lines'][] = ['sales_invoice_line_id' => $id, 'quantity' => (string) $quantity];
            }
            foreach ($data['lines'] as $idx => $lineInput) {
                /** @var SalesInvoiceLine $invLine */
                $invLine = SalesInvoiceLine::where('sales_invoice_id', $invoice->id)
                    ->where('id', $lineInput['sales_invoice_line_id'])
                    ->firstOrFail();

                $returnQty = BigDecimal::of((string) $lineInput['quantity']);
                if ($returnQty->isLessThanOrEqualTo(0)) {
                    throw new InvalidArgumentException('Return line quantity must be strictly positive.');
                }

                // Check cumulative returns against original invoice line quantity
                $alreadyReturned = BigDecimal::zero();
                $priorReturnLines = SalesReturnLine::query()
                    ->where('sales_invoice_line_id', $invLine->id)
                    ->whereHas('salesReturn', fn ($q) => $q->where('status', SalesReturn::STATUS_POSTED))
                    ->get();

                foreach ($priorReturnLines as $prl) {
                    $alreadyReturned = $alreadyReturned->plus(BigDecimal::of((string) $prl->quantity));
                }

                $maxReturnable = BigDecimal::of((string) $invLine->quantity)->minus($alreadyReturned);
                if ($returnQty->isGreaterThan($maxReturnable)) {
                    throw new InvalidArgumentException("Requested return quantity [{$returnQty}] exceeds maximum returnable quantity [{$maxReturnable}] for line [{$invLine->id}].");
                }

                $taxRate = $invLine->tax_rate_snapshot !== null ? BigDecimal::of((string) $invLine->tax_rate_snapshot) : null;
                $taxInclusive = (bool) $invLine->tax_inclusive;

                // Price is original line unit price, with proportional discount from original invoice line
                $invLineQty = BigDecimal::of((string) $invLine->quantity);
                $invLineDiscount = BigDecimal::of((string) $invLine->line_discount);
                $returnDiscount = $invLineQty->isPositive() && $invLineDiscount->isPositive()
                    ? $invLineDiscount->multipliedBy($returnQty)->dividedBy($invLineQty, $minorUnits, RoundingMode::HALF_UP)
                    : BigDecimal::zero();

                $calcInput = new SalesLineCalculationInput(
                    quantity: $returnQty,
                    unitPrice: (string) $invLine->unit_price,
                    discountType: $returnDiscount->isPositive() ? 'fixed' : null,
                    discountValue: $returnDiscount,
                    taxRate: $taxRate,
                    taxInclusive: $taxInclusive,
                    currencyMinorUnits: $minorUnits,
                    exchangeRate: $fx,
                    baseCurrencyMinorUnits: $baseMinorUnits,
                );

                $calcResult = $this->lineCalculator->calculate($calcInput);
                $lineResults[] = $calcResult;

                $conversionRatio = BigDecimal::of((string) $invLine->unit_conversion_ratio);
                $qtyBase = $returnQty->multipliedBy($conversionRatio)->toScale(6);

                $preparedLines[] = [
                    'sales_invoice_line_id' => $invLine->id,
                    'line_number' => $idx + 1,
                    'product_id' => $invLine->product_id,
                    'product_unit_id' => $invLine->product_unit_id,
                    'item_description' => $invLine->item_description,
                    'quantity' => (string) $returnQty->toScale(6),
                    'unit_price' => (string) $invLine->unit_price,
                    'tax_rate_id' => $invLine->tax_rate_id,
                    'tax_rate_snapshot' => $invLine->tax_rate_snapshot,
                    'tax_inclusive' => $taxInclusive,
                    'line_subtotal' => (string) $calcResult->subtotal,
                    'line_discount' => (string) $calcResult->discount,
                    'line_tax' => (string) $calcResult->tax,
                    'line_total' => (string) $calcResult->total,
                    'line_subtotal_base' => (string) $calcResult->subtotalBase,
                    'line_discount_base' => (string) $calcResult->discountBase,
                    'line_tax_base' => (string) $calcResult->taxBase,
                    'line_total_base' => (string) $calcResult->totalBase,
                    'cogs_total_base' => '0.000000',
                    'cogs_unit_base' => '0.000000',
                    'unit_conversion_ratio' => (string) $conversionRatio,
                    'quantity_base' => (string) $qtyBase,
                    'unit_name_ar' => $invLine->unit_name_ar,
                    'unit_name_en' => $invLine->unit_name_en,
                    'product_sku' => $invLine->product_sku,
                    'product_name_ar' => $invLine->product_name_ar,
                    'product_name_en' => $invLine->product_name_en,
                ];
            }

            $totals = $this->totalsCalculator->calculate($lineResults);

            $salesReturn = SalesReturn::create([
                'public_id' => (string) Str::ulid(),
                'company_id' => $company->id,
                'return_number' => 'DRAFT-'.Str::random(8),
                'sales_invoice_id' => $invoice->id,
                'customer_id' => $customer->id,
                'warehouse_id' => $invoice->warehouse_id,
                'currency_code' => $currency,
                'exchange_rate' => (string) $fx->toScale(10),
                'issue_date' => $data['issue_date'],
                'status' => SalesReturn::STATUS_DRAFT,
                'subtotal_base' => (string) $totals->subtotalBase,
                'discount_total_base' => '0.000000',
                'tax_total_base' => (string) $totals->taxTotalBase,
                'grand_total_base' => (string) $totals->grandTotalBase,
                'cogs_total_base' => '0.000000',
                'subtotal_currency' => (string) $totals->subtotalCurrency,
                'discount_total_currency' => '0.000000',
                'tax_total_currency' => (string) $totals->taxTotalCurrency,
                'grand_total_currency' => (string) $totals->grandTotalCurrency,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'document_locale' => $invoice->document_locale,
                'customer_snapshot' => $invoice->customer_snapshot,
                'company_snapshot' => $invoice->company_snapshot,
                'created_by' => $user->id,
            ]);

            foreach ($preparedLines as $lineAttrs) {
                SalesReturnLine::create(array_merge($lineAttrs, [
                    'public_id' => (string) Str::ulid(),
                    'company_id' => $company->id,
                    'sales_return_id' => $salesReturn->id,
                ]));
            }

            app(SalesReturnAmounts::class)->refresh($salesReturn);

            return $salesReturn->load(['lines', 'customer', 'salesInvoice']);
        });
    }
}
