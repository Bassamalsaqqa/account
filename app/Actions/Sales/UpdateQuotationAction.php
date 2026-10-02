<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class UpdateQuotationAction
{
    public function __construct(
        protected SalesLineCalculator $lineCalculator,
        protected SalesDocumentTotalsCalculator $totalsCalculator,
    ) {}

    /**
     * @param  array{
     *     customer_id?: int,
     *     currency_code?: string,
     *     exchange_rate?: string|BigDecimal,
     *     issue_date?: string,
     *     expiry_date?: ?string,
     *     status?: string,
     *     document_locale?: string,
     *     include_product_images?: mixed,
     *     notes?: ?string,
     *     terms?: ?string,
     *     lines?: list<array{
     *         product_id?: ?int,
     *         product_unit_id?: ?int,
     *         item_description: string,
     *         quantity: string|BigDecimal,
     *         unit_price: string|BigDecimal,
     *         discount_type?: ?string,
     *         discount_value?: string|BigDecimal,
     *         tax_rate_id?: ?int,
     *     }>
     * }  $data
     */
    public function execute(Quotation $quotation, User $user, array $data): Quotation
    {
        return DB::transaction(function () use ($quotation, $user, $data): Quotation {
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

            if (! $user->hasPermissionTo('sales.quote.edit')) {
                throw new AuthorizationException('User does not have permission to edit quotations.');
            }

            // 1. Lock company FOR UPDATE first
            /** @var Company $company */
            $company = Company::where('id', $quotation->company_id)->lockForUpdate()->firstOrFail();
            app(SalesActorGuard::class)->lockAndAuthorize((int) $quotation->company_id, $user, 'sales.quote.edit');

            // 2. Lock quotation FOR UPDATE second
            /** @var Quotation $lockedQuote */
            $lockedQuote = Quotation::where('id', $quotation->id)->lockForUpdate()->firstOrFail();

            if (! $lockedQuote->isDraft()) {
                throw new InvalidArgumentException('Cannot edit a converted quotation.');
            }

            $customerId = $data['customer_id'] ?? $lockedQuote->customer_id;
            /** @var Customer $customer */
            $customer = Customer::where('company_id', $company->id)->where('id', $customerId)->lockForUpdate()->firstOrFail();

            if (! $customer->active) {
                throw new InvalidArgumentException('Cannot edit a quotation for an inactive customer.');
            }
            $currency = $data['currency_code'] ?? $lockedQuote->currency_code;
            $fx = isset($data['exchange_rate']) ? ExchangeRate::from($data['exchange_rate'])->getValue() : BigDecimal::of((string) $lockedQuote->exchange_rate);
            $issueDate = $data['issue_date'] ?? (string) $lockedQuote->issue_date->format('Y-m-d');
            $expiryDate = array_key_exists('expiry_date', $data) ? $data['expiry_date'] : ($lockedQuote->expiry_date ? (string) $lockedQuote->expiry_date->format('Y-m-d') : null);

            app(SalesDocumentRules::class)->header($company, $currency, $fx, $issueDate,
                $data['document_locale'] ?? $lockedQuote->document_locale);
            if ($expiryDate !== null) {
                app(SalesDocumentRules::class)->date($expiryDate);
            }
            if (! isset($data['lines']) && ($currency !== $lockedQuote->currency_code
                || ! $fx->isEqualTo(BigDecimal::of($lockedQuote->exchange_rate)))) {
                throw new InvalidArgumentException('Currency or rate changes require recalculating the quotation lines.');
            }
            if (isset($data['lines'])) {
                if (empty($data['lines'])) {
                    throw new InvalidArgumentException('Quotation must contain at least one line.');
                }

                app(SalesDocumentRules::class)->header($company, $currency, $fx, $issueDate, $data['document_locale'] ?? $customer->preferred_locale ?? $company->default_locale);
                $lineResults = [];
                $preparedLines = [];
                $minorUnits = in_array($currency, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;
                $baseMinorUnits = in_array($company->base_currency_code, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;

                foreach ($data['lines'] as $idx => $lineData) {
                    $taxRate = null;
                    $taxRateModel = null;
                    if (! empty($lineData['tax_rate_id'])) {
                        /** @var TaxRate $taxRateModel */
                        $taxRateModel = TaxRate::where('company_id', $company->id)
                            ->where('id', $lineData['tax_rate_id'])
                            ->where('active', true)
                            ->firstOrFail();
                        $taxRate = BigDecimal::of((string) $taxRateModel->rate);
                    }

                    $taxInclusive = $taxRateModel ? $taxRateModel->calculation === TaxRate::CALC_INCLUSIVE : false;

                    $calcInput = new SalesLineCalculationInput(
                        quantity: $lineData['quantity'],
                        unitPrice: $lineData['unit_price'],
                        discountType: $lineData['discount_type'] ?? null,
                        discountValue: $lineData['discount_value'] ?? 0,
                        taxRate: $taxRate,
                        taxInclusive: $taxInclusive,
                        currencyMinorUnits: $minorUnits,
                        exchangeRate: $fx,
                        baseCurrencyMinorUnits: $baseMinorUnits,
                    );

                    $calcResult = $this->lineCalculator->calculate($calcInput);
                    $lineResults[] = $calcResult;

                    $product = null;
                    $productUnit = null;
                    $conversionRatio = BigDecimal::one();
                    if (! empty($lineData['product_id'])) {
                        $product = Product::where('company_id', $company->id)->whereKey($lineData['product_id'])->lockForUpdate()->firstOrFail();
                        $productUnit = app(SalesDocumentRules::class)->selectedUnit($product,
                            ! empty($lineData['product_unit_id']) ? (int) $lineData['product_unit_id'] : null,
                            app(SalesDocumentRules::class)->quantity($lineData['quantity']));
                        $conversionRatio = BigDecimal::of((string) $productUnit->conversion_to_base);
                    } else {
                        app(SalesDocumentRules::class)->quantity($lineData['quantity']);
                    }
                    $qtyDecimal = BigDecimal::of((string) $lineData['quantity']);
                    $qtyBase = $qtyDecimal->multipliedBy($conversionRatio)->toScale(6);

                    $preparedLines[] = [
                        'line_number' => $idx + 1,
                        'product_id' => $product?->id,
                        'product_unit_id' => $productUnit?->id,
                        'item_description' => $lineData['item_description'],
                        'quantity' => (string) $qtyDecimal->toScale(6),
                        'unit_price' => (string) MoneyAmount::from($lineData['unit_price'])->getAmount()->toScale(6),
                        'discount_type' => $lineData['discount_type'] ?? null,
                        'discount_value' => (string) MoneyAmount::from($lineData['discount_value'] ?? '0')->getAmount()->toScale(6),
                        'tax_rate_id' => $taxRateModel?->id,
                        'tax_rate_snapshot' => $taxRate ? (string) $taxRate->toScale(6) : null,
                        'tax_inclusive' => $taxInclusive,
                        'line_subtotal' => (string) $calcResult->subtotal,
                        'line_discount' => (string) $calcResult->discount,
                        'line_tax' => (string) $calcResult->tax,
                        'line_total' => (string) $calcResult->total,
                        'line_total_base' => (string) $calcResult->totalBase,
                        'unit_conversion_ratio' => (string) $conversionRatio->toScale(6),
                        'quantity_base' => (string) $qtyBase,
                        'unit_name_ar' => $productUnit?->unit?->name_ar,
                        'unit_name_en' => $productUnit?->unit?->name_en,
                        'product_sku' => $product?->sku,
                        'product_name_ar' => $product?->name_ar,
                        'product_name_en' => $product?->name_en,
                    ];
                }

                $totals = $this->totalsCalculator->calculate($lineResults, $fx);

                // Replace quotation lines
                QuotationLine::where('quotation_id', $lockedQuote->id)->delete();
                foreach ($preparedLines as $lineAttrs) {
                    QuotationLine::create(array_merge($lineAttrs, [
                        'public_id' => (string) Str::ulid(),
                        'company_id' => $company->id,
                        'quotation_id' => $lockedQuote->id,
                    ]));
                }

                $lockedQuote->subtotal_base = (string) $totals->subtotalBase;
                $lockedQuote->discount_total_base = (string) $totals->discountTotalBase;
                $lockedQuote->tax_total_base = (string) $totals->taxTotalBase;
                $lockedQuote->grand_total_base = (string) $totals->grandTotalBase;
                $lockedQuote->subtotal_currency = (string) $totals->subtotalCurrency;
                $lockedQuote->discount_total_currency = (string) $totals->discountTotalCurrency;
                $lockedQuote->tax_total_currency = (string) $totals->taxTotalCurrency;
                $lockedQuote->grand_total_currency = (string) $totals->grandTotalCurrency;
            }

            if (isset($data['status']) && $data['status'] !== $lockedQuote->status) {
                throw new InvalidArgumentException('Use the canonical quotation transition workflow.');
            }
            if (isset($data['notes'])) {
                $lockedQuote->notes = $data['notes'];
            }
            if (isset($data['terms'])) {
                $lockedQuote->terms = $data['terms'];
            }
            $lockedQuote->customer_id = $customerId;
            // A draft can change customer. Refresh its printable identity before it becomes historical.
            $lockedQuote->customer_snapshot = $customer->only(['name_ar', 'name_en', 'business_name_ar', 'business_name_en', 'phone', 'email', 'tax_number', 'address_ar', 'address_en']);
            $lockedQuote->company_snapshot = $company->only(['name_ar', 'name_en', 'phone', 'email', 'tax_number', 'address_ar', 'address_en']);
            $lockedQuote->currency_code = $currency;
            $lockedQuote->exchange_rate = (string) $fx->toScale(10);
            $lockedQuote->issue_date = $issueDate;
            $lockedQuote->expiry_date = $expiryDate;
            $lockedQuote->document_locale = $data['document_locale'] ?? $lockedQuote->document_locale;
            if (array_key_exists('include_product_images', $data)) {
                if (! is_bool($data['include_product_images'])) {
                    throw new InvalidArgumentException('Product image option must be boolean.');
                }
                $lockedQuote->include_product_images = $data['include_product_images'];
            }
            $lockedQuote->updated_by = $user->id;
            $lockedQuote->save();

            return $lockedQuote->fresh(['lines', 'customer']);
        });
    }
}
