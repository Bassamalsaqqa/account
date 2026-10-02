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
use App\Models\CompanyDocumentSettings;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateQuotationAction
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected SalesLineCalculator $lineCalculator,
        protected SalesDocumentTotalsCalculator $totalsCalculator,
    ) {}

    /**
     * @param  array{
     *     customer_id: int,
     *     currency_code: string,
     *     exchange_rate: string|BigDecimal,
     *     issue_date: string,
     *     expiry_date?: ?string,
     *     pricing_tier?: string,
     *     notes?: ?string,
     *     terms?: ?string,
     *     document_locale?: string,
     *     include_product_images?: mixed,
     *     lines: list<array{
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
    public function execute(Company $company, User $user, array $data): Quotation
    {
        return DB::transaction(function () use ($company, $user, $data): Quotation {
            if (array_key_exists('include_product_images', $data) && ! is_bool($data['include_product_images'])) {
                throw new InvalidArgumentException('The quotation product-image option must be a boolean.');
            }
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

            if (! $user->hasPermissionTo('sales.quote.create')) {
                throw new AuthorizationException('User does not have permission to create quotations.');
            }

            // Lock company FOR UPDATE
            Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $user, 'sales.quote.create');

            /** @var Customer $customer */
            $customer = Customer::where('company_id', $company->id)
                ->where('id', $data['customer_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $customer->active) {
                throw new InvalidArgumentException("Cannot create quotation for inactive customer [{$customer->id}].");
            }

            if (empty($data['lines'])) {
                throw new InvalidArgumentException('Quotation must contain at least one line.');
            }

            $currency = strtoupper(trim($data['currency_code']));
            $fx = ExchangeRate::from($data['exchange_rate'])->getValue();
            $issueDate = $data['issue_date'];
            $expiryDate = $data['expiry_date'] ?? null;
            $documentLocale = $data['document_locale'] ?? $customer->preferred_locale ?? 'ar';
            $year = (int) substr($issueDate, 0, 4);

            // Generate permanent sequence number on save
            $quotationNumber = $this->sequenceService->generateNextNumber(
                $company->id,
                DocumentSequence::TYPE_QUOTATION,
                $year
            );

            // Process line calculations
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

                // Lookup product and unit metadata if present
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

            // Aggregate totals
            $totals = $this->totalsCalculator->calculate($lineResults, $fx);

            // Snapshots
            $customerSnapshot = [
                'name_ar' => $customer->name_ar,
                'name_en' => $customer->name_en,
                'business_name_ar' => $customer->business_name_ar,
                'business_name_en' => $customer->business_name_en,
                'address_ar' => $customer->address_ar,
                'address_en' => $customer->address_en,
                'business_name' => $customer->business_name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'tax_number' => $customer->tax_number,
                'address' => $customer->address_line_1_ar,
                'city' => $customer->city_ar,
            ];

            $companySnapshot = [
                'name_ar' => $company->name_ar,
                'name_en' => $company->name_en,
                'base_currency' => $company->base_currency_code,
                'phone' => $company->phone,
                'email' => $company->email,
                'tax_number' => $company->tax_number,
            ];

            $quotation = Quotation::create([
                'public_id' => (string) Str::ulid(),
                'company_id' => $company->id,
                'quotation_number' => $quotationNumber,
                'customer_id' => $customer->id,
                'currency_code' => $currency,
                'exchange_rate' => (string) $fx->toScale(10),
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate,
                'status' => Quotation::STATUS_DRAFT,
                'include_product_images' => $data['include_product_images'] ?? (bool) CompanyDocumentSettings::where('company_id', $company->id)->value('show_product_images_on_quotes'),
                'pricing_tier' => $data['pricing_tier'] ?? 'regular',
                'subtotal_base' => (string) $totals->subtotalBase,
                'discount_total_base' => (string) $totals->discountTotalBase,
                'tax_total_base' => (string) $totals->taxTotalBase,
                'grand_total_base' => (string) $totals->grandTotalBase,
                'subtotal_currency' => (string) $totals->subtotalCurrency,
                'discount_total_currency' => (string) $totals->discountTotalCurrency,
                'tax_total_currency' => (string) $totals->taxTotalCurrency,
                'grand_total_currency' => (string) $totals->grandTotalCurrency,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'document_locale' => $documentLocale,
                'customer_snapshot' => $customerSnapshot,
                'company_snapshot' => $companySnapshot,
                'created_by' => $user->id,
            ]);

            foreach ($preparedLines as $lineAttrs) {
                QuotationLine::create(array_merge($lineAttrs, [
                    'public_id' => (string) Str::ulid(),
                    'company_id' => $company->id,
                    'quotation_id' => $quotation->id,
                ]));
            }

            return $quotation->load(['lines', 'customer']);
        });
    }
}
