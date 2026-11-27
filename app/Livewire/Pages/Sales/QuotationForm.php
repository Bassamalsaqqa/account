<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Actions\Sales\CreateQuotationAction;
use App\Actions\Sales\UpdateQuotationAction;
use App\Application\Reporting\Security\ReportingGuard;
use App\Domain\Inventory\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Domain\Money\Exceptions\InvalidMoneyException;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use App\Domain\Sales\DocumentDiscountIntent;
use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use App\Domain\Sales\Queries\CustomerLastPriceQuery;
use App\Livewire\Concerns\AdjustsDocumentQuantity;
use App\Livewire\Concerns\CameraBarcodeScan;
use App\Livewire\Concerns\FindsEmptyDocumentLine;
use App\Livewire\Concerns\HandlesDocumentLineIdentity;
use App\Livewire\Concerns\HandlesSearchableSelectors;
use App\Livewire\Concerns\QuickPartyCreate;
use App\Livewire\Concerns\QuickProductCreate;
use App\Livewire\Concerns\ValidatesSalesHeaderDiscount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Quotation;
use App\Models\TaxRate;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesProductSelection;
use App\Services\Search\ArabicSearchNormalizer;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class QuotationForm extends Component
{
    use AdjustsDocumentQuantity;
    use CameraBarcodeScan;
    use FindsEmptyDocumentLine;
    use HandlesDocumentLineIdentity;
    use HandlesSearchableSelectors;
    use QuickPartyCreate;
    use QuickProductCreate;
    use ValidatesSalesHeaderDiscount;

    protected function salesDiscountDocumentCurrencyMinorUnits(): ?int
    {
        return $this->quotation !== null && $this->quotation->currency_code === $this->currency_code
            ? $this->quotation->resolvedCurrencyMinorUnits()
            : null;
    }

    protected function quickProductFormPermission(): string
    {
        return $this->quickProductDocumentId === null ? 'sales.quote.create' : 'sales.quote.edit';
    }

    protected function assertQuickProductDraft(): void
    {
        if ($this->quickProductDocumentId !== null) {
            $document = Quotation::where('company_id', $this->pageCompanyId)->findOrFail($this->quickProductDocumentId);
            abort_unless($document->isDraft(), 403);
        }
    }

    protected function quickPartyKind(): string
    {
        return 'customer';
    }

    protected function quickPartyFormPermission(): string
    {
        return $this->isEditing ? 'sales.quote.edit' : 'sales.quote.create';
    }

    protected function authorizeQuickPartyForm(): Company
    {
        $this->authorizeQuoteAction();

        return app(CompanyContext::class)->company();
    }

    protected function selectQuickParty(int $id): void
    {
        $this->customer_id = $id;
        $this->updatedCustomerId();
    }

    #[Locked]
    public int $pageCompanyId;

    public ?Quotation $quotation = null;

    public bool $isEditing = false;

    public ?int $customer_id = null;

    public string $customerSearch = '';

    public string $productSearch = '';

    public string $barcodeSearch = '';

    public string $currency_code = 'ILS';

    public string $exchange_rate = '1.0000000000';

    public string $issue_date = '';

    public ?string $expiry_date = null;

    public ?string $notes = null;

    public ?string $terms = null;

    public string $document_locale = 'ar';

    public bool $include_product_images = false;

    /**
     * @var list<array{
     *     id?: ?int,
     *     row_key?: string,
     *     product_id?: ?int,
     *     product_unit_id?: ?int,
     *     item_description: string,
     *     quantity: string|numeric,
     *     unit_price: string|numeric,
     *     discount_type: string,
     *     discount_value: string|numeric,
     *     tax_rate_id?: ?int,
     *     subtotal?: string,
     *     discount_amount?: string,
     *     tax_amount?: string,
     *     line_total?: string,
     *     available_quantity?: ?string,
     * }>
     */
    public array $lines = [];

    // Preview totals
    public string $previewSubtotal = '0.00';

    public string $previewDiscountTotal = '0.00';

    public string $previewTaxTotal = '0.00';

    public string $previewGrandTotal = '0.00';

    #[Locked]
    public string $discount_scope = 'document';

    #[Locked]
    public int $discount_calculation_version = 2;

    #[Locked]
    public ?int $currency_minor_units = null;

    /** @var array<int|string, array{discount_type: string, discount_value: string}> */
    #[Locked]
    public array $persistedLineDiscounts = [];

    public string $discount_type = 'none';

    public mixed $discount_value = '0';

    public bool $showDiscountControl = false;

    #[Locked]
    public ?string $previousCurrencyCode = null;

    public function mount(CompanyContext $context, ?string $publicId = null): void
    {
        $company = $context->company();
        $this->pageCompanyId = (int) $company->id;
        $user = auth()->user();

        if ($publicId !== null) {
            if (! $user->hasPermissionTo('sales.quote.edit')) {
                abort(403, 'Unauthorized.');
            }

            $this->quotation = Quotation::with('lines')
                ->where('company_id', $company->id)
                ->where('public_id', $publicId)
                ->firstOrFail();

            if (! in_array($this->quotation->status, [Quotation::STATUS_DRAFT], true)) {
                abort(400, 'Cannot edit quotation in status: '.$this->quotation->status);
            }

            $this->quickProductDocumentId = (int) $this->quotation->id;
            $this->isEditing = true;
            $this->customer_id = $this->quotation->customer_id;
            $this->currency_code = $this->quotation->currency_code;
            $this->exchange_rate = (string) $this->quotation->exchange_rate;
            $this->issue_date = $this->quotation->issue_date->toDateString();
            $this->expiry_date = $this->quotation->expiry_date ? $this->quotation->expiry_date->toDateString() : null;
            $this->notes = $this->quotation->notes;
            $this->terms = $this->quotation->terms;
            $this->document_locale = $this->quotation->document_locale ?? 'ar';
            $this->include_product_images = $this->quotation->include_product_images;
            $this->discount_type = $this->quotation->discount_type ?? 'none';
            $this->discount_value = (string) ($this->quotation->discount_value ?? '0');
            $this->discount_scope = $this->quotation->discount_scope ?? 'document';
            $this->discount_calculation_version = (int) ($this->quotation->discount_calculation_version ?? 2);
            $this->currency_minor_units = $this->quotation->resolvedCurrencyMinorUnits();
            $this->previousCurrencyCode = $this->currency_code;
            if ($this->discount_type !== 'none' || ($this->discount_scope === 'document' && ! BigDecimal::of((string) ($this->discount_value ?: '0'))->isZero())) {
                $this->showDiscountControl = true;
            }

            $this->lines = [];
            $this->persistedLineDiscounts = [];
            foreach ($this->quotation->lines as $line) {
                $dType = $this->quotationDiscountType($line->discount_type) ?? 'none';
                $this->persistedLineDiscounts[$line->id] = [
                    'discount_type' => $dType,
                    'discount_value' => (string) ($line->discount_value ?? '0'),
                ];
                $this->lines[] = [
                    'id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_unit_id' => $line->product_unit_id,
                    'item_description' => $line->item_description,
                    'quantity' => (string) $line->quantity,
                    'unit_price' => (string) $line->unit_price,
                    'discount_type' => $dType,
                    'discount_value' => (string) ($line->discount_value ?? '0'),
                    'tax_rate_id' => $line->tax_rate_id,
                    'subtotal' => (string) $line->line_subtotal,
                    'discount_amount' => (string) $line->line_discount,
                    'tax_amount' => (string) $line->line_tax,
                    'line_total' => (string) $line->line_total,
                ];
            }
            if ($this->discount_scope === 'line'
                && ! collect($this->persistedLineDiscounts)->contains(fn (array $discount): bool => in_array($discount['discount_type'], ['fixed', 'percent'], true)
                    && BigDecimal::of($discount['discount_value'])->isPositive())) {
                $this->discount_scope = 'document';
                $this->discount_calculation_version = DocumentDiscountIntent::VERSION_CANONICAL;
            }
        } else {
            if (! $user->hasPermissionTo('sales.quote.create')) {
                abort(403, 'Unauthorized.');
            }

            $this->currency_code = $company->base_currency_code;
            $this->previousCurrencyCode = $this->currency_code;
            $this->currency_minor_units = app(CurrencyMinorUnitsResolver::class)->resolveForCompany($company, $this->currency_code);
            $this->discount_scope = 'document';
            $this->discount_calculation_version = 2;
            $this->discount_type = 'none';
            $this->discount_value = '0';
            $this->showDiscountControl = false;
            $this->issue_date = Carbon::now($company->timezone)->toDateString();
            $currentLocale = app()->getLocale();
            $this->document_locale = $company->isLanguageEnabled($currentLocale) ? $currentLocale : ($company->documentSettings->default_document_locale ?? $company->default_locale ?? 'ar');
            $this->terms = $company->documentSettings?->getAttribute('quotation_terms_'.$this->document_locale);
            $this->include_product_images = (bool) $company->documentSettings?->show_product_images_on_quotes;

            $this->lines = [
                [
                    'product_id' => null,
                    'product_unit_id' => null,
                    'item_description' => '',
                    'quantity' => '1',
                    'unit_price' => '0.00',
                    'discount_type' => 'none',
                    'discount_value' => '0',
                    'tax_rate_id' => null,
                    'subtotal' => '0.00',
                    'discount_amount' => '0.00',
                    'tax_amount' => '0.00',
                    'line_total' => '0.00',
                ],
            ];
        }

        $this->recalculate();
    }

    public function toggleDiscountControl(): void
    {
        $this->showDiscountControl = ! $this->showDiscountControl;
        if (! $this->showDiscountControl) {
            $this->discount_type = 'none';
            $this->discount_value = '0';
            $this->resetErrorBag('discount_value');
            $this->recalculate();
        }
    }

    public function hydrate(CompanyContext $context): void
    {
        $this->authorizeQuoteReadOnly();
        $this->ensureLineIdentities();
    }

    protected function authorizeQuoteReadOnly(): void
    {
        $user = auth()->user();
        $context = app(CompanyContext::class);
        abort_unless($user !== null && $context->hasCompany() && (int) $context->companyId() === (int) $this->pageCompanyId, 403);

        try {
            app(SalesActorGuard::class)->authorizeReadOnly(
                $this->pageCompanyId,
                $user,
                $this->isEditing ? 'sales.quote.edit' : 'sales.quote.create'
            );
        } catch (AuthorizationException) {
            abort(403);
        }
        if ($this->isEditing) {
            $quote = Quotation::where('company_id', $this->pageCompanyId)->whereKey($this->quotation?->id)->firstOrFail();
            abort_unless($quote->status === Quotation::STATUS_DRAFT, 403);
        }
    }

    protected function authorizeQuoteAction(): void
    {
        $user = auth()->user();
        $context = app(CompanyContext::class);
        abort_unless($user !== null && $context->hasCompany() && (int) $context->companyId() === (int) $this->pageCompanyId, 403);

        DB::transaction(fn () => app(SalesActorGuard::class)->lockAndAuthorize($this->pageCompanyId, $user,
            $this->isEditing ? 'sales.quote.edit' : 'sales.quote.create'));
        if ($this->isEditing) {
            $quote = Quotation::where('company_id', $this->pageCompanyId)->whereKey($this->quotation?->id)->firstOrFail();
            abort_unless($quote->status === Quotation::STATUS_DRAFT, 403);
        }
    }

    public function updatedCustomerId(): void
    {
        $this->authorizeQuoteAction();
        if ($this->customer_id !== null) {
            $customer = Customer::where('company_id', $this->pageCompanyId)
                ->where('status', 'active')
                ->find($this->customer_id);
            if ($customer !== null) {
                $previousCurrency = $this->currency_code;
                $newCurrency = $customer->default_currency_code ?? app(CompanyContext::class)->company()->base_currency_code;
                if ($newCurrency !== $previousCurrency && $this->discount_scope === 'line'
                    && collect($this->persistedLineDiscounts)->contains(fn ($discount) => ! BigDecimal::of((string) $discount['discount_value'])->isZero())) {
                    $this->addError('currency_code', __('sales.currency_change_legacy_discount'));
                } else {
                    $this->currency_code = $newCurrency;
                    if ($newCurrency !== $previousCurrency && $this->discount_type === 'fixed') {
                        $this->discount_value = '0';
                    }
                    $this->previousCurrencyCode = $this->currency_code;
                    $company = app(CompanyContext::class)->company();
                    if ($this->currency_code === $company->base_currency_code) {
                        $this->exchange_rate = '1.0000000000';
                    } elseif ($previousCurrency !== $this->currency_code) {
                        $this->exchange_rate = '';
                    }
                    $this->recalculate();
                }
            } else {
                $this->customer_id = null;
            }
        }
    }

    public function updatedCurrencyCode(): void
    {
        $this->authorizeQuoteAction();
        $changed = $this->currency_code !== $this->previousCurrencyCode;
        if ($changed && $this->discount_scope === 'line'
            && collect($this->persistedLineDiscounts)->contains(fn ($discount) => ! BigDecimal::of((string) $discount['discount_value'])->isZero())) {
            $this->currency_code = $this->previousCurrencyCode;
            $this->recalculate();
            $this->addError('currency_code', __('sales.currency_change_legacy_discount'));

            return;
        }

        if ($changed && $this->discount_type === 'fixed') {
            $this->discount_value = '0';
        }

        $this->previousCurrencyCode = $this->currency_code;
        $company = app(CompanyContext::class)->company();
        if ($this->currency_code === $company->base_currency_code) {
            $this->exchange_rate = '1.0000000000';
        } else {
            $this->exchange_rate = '';
        }
        $this->recalculate();
    }

    public function updatedLines(): void
    {
        $this->authorizeQuoteAction();
        $this->recalculate();
    }

    public function scanBarcode(): void
    {
        $this->authorizeQuoteAction();

        $raw = trim((string) $this->barcodeSearch);
        if ($raw === '') {
            return;
        }

        // Reject oversized barcode instead of truncating to a different valid barcode
        if (mb_strlen($raw) > 128) {
            $this->addError('barcodeSearch', __('workflow.barcode_too_long'));

            return;
        }

        $term = $raw;

        $company = Company::where('id', $this->pageCompanyId)->firstOrFail();

        // 1. Try exact barcode
        $barcode = ProductBarcode::with(['product.productUnits', 'unit'])
            ->where('company_id', $company->id)
            ->whereHas('product', fn ($q) => $q->where('company_id', $company->id)->where('active', true))
            ->where('barcode', $term)
            ->first();

        $product = null;
        $productUnit = null;

        if ($barcode !== null) {
            $product = $barcode->product;
            if ($barcode->unit_id !== null) {
                $productUnit = ProductUnit::where('company_id', $company->id)
                    ->where('product_id', $product->id)
                    ->where('unit_id', $barcode->unit_id)
                    ->where('active', true)
                    ->whereHas('unit', fn ($u) => $u->where('company_id', $company->id)->where('active', true))
                    ->first();

                // Reject missing, inactive or foreign unit rather than fallback!
                if ($productUnit === null) {
                    $this->addError('barcodeSearch', __('workflow.barcode_unit_mismatch'));

                    return;
                }
            } else {
                $productUnit = null;
            }
        } else {
            // 2. Try SKU exact match
            $product = Product::with('productUnits')
                ->where('company_id', $company->id)
                ->where('active', true)
                ->where('sku', $term)
                ->first();

            if ($product !== null) {
                $productUnit = null;
            }
        }

        if ($product === null) {
            $this->addError('barcodeSearch', __('workflow.product_not_found'));

            return;
        }

        // Canonical product selection BEFORE matching existing row to resolve concrete effective product_unit_id
        try {
            $selected = app(SalesProductSelection::class)->select(
                (int) $product->id,
                $productUnit?->id,
                $this->exchange_rate,
                null
            );
        } catch (\InvalidArgumentException|MathException|ModelNotFoundException|InvalidMoneyException|InvalidQuantityException|InvalidUnitConversionException $e) {
            $this->addError('barcodeSearch', __('commands.code_validation_failed'));

            return;
        }

        $selectedUnitId = (int) $selected['product_unit_id'];
        $selectedPrice = (string) $selected['unit_price'];
        $selectedDescription = (string) $selected['item_description'];

        // Preserve lot/line data; safest append or fill truly empty row, do not silently merge differing unit/lot/cost/price/discount/tax/description lines.
        $existingIndex = null;
        foreach ($this->lines as $idx => $line) {
            $linePid = ! empty($line['product_id']) ? (int) $line['product_id'] : null;
            $lineUnitId = ! empty($line['product_unit_id']) ? (int) $line['product_unit_id'] : null;
            $linePrice = (string) ($line['unit_price']);
            $lineDesc = (string) ($line['item_description']);
            $discountType = $line['discount_type'];
            $taxRateId = $line['tax_rate_id'] ?? null;

            if ($linePid === (int) $product->id
                && $lineUnitId === $selectedUnitId
                && $linePrice === $selectedPrice
                && $lineDesc === $selectedDescription
                && $discountType === 'none'
                && empty($taxRateId)
            ) {
                $existingIndex = $idx;
                break;
            }
        }

        if ($existingIndex !== null) {
            if (! is_string($this->lines[$existingIndex]['quantity']) || preg_match('/^\d+(?:\.\d{1,6})?$/D', $this->lines[$existingIndex]['quantity']) !== 1) {
                $this->addError("lines.{$existingIndex}.quantity", __('commands.exact_string_required'));

                return;
            }
            $curQty = BigDecimal::of((string) ($this->lines[$existingIndex]['quantity'] ?: '0'));
            $this->lines[$existingIndex]['quantity'] = (string) $curQty->plus(1);
        } else {
            $isTrulyEmptyFirstRow = count($this->lines) === 1
                && $this->firstTrulyEmptyLineIndex($this->lines, 'unit_price') === 0;

            $newLine = [
                'row_key' => $isTrulyEmptyFirstRow ? $this->lineRowKey(0) : $this->newUiIdentityKey(),
                'product_id' => (int) $product->id,
                'product_unit_id' => $selectedUnitId,
                'item_description' => $selectedDescription,
                'quantity' => '1',
                'unit_price' => $selectedPrice,
                'discount_type' => 'none',
                'discount_value' => '0',
                'tax_rate_id' => null,
                'available_quantity' => $selected['available_quantity'] ?? null,
                'subtotal' => '0.00',
                'discount_amount' => '0.00',
                'tax_amount' => '0.00',
                'line_total' => '0.00',
            ];

            if ($isTrulyEmptyFirstRow) {
                $this->lines[0] = $newLine;
            } else {
                $this->lines[] = $newLine;
            }
        }

        $this->barcodeSearch = '';
        $this->resetErrorBag('barcodeSearch');
        $this->recalculate();
        $this->dispatch('barcode-scanned');
    }

    public function quickAddProduct(?int $productId = null, ?int $productUnitId = null): bool
    {
        $this->authorizeQuoteAction();
        if ($productId === null || $productId <= 0) {
            return false;
        }

        $company = app(CompanyContext::class)->company();
        $product = Product::where('company_id', $company->id)
            ->where('active', true)
            ->find($productId);

        if ($product === null) {
            $this->addError('quickProduct', __('workflow.product_not_found'));

            return false;
        }

        try {
            $selected = app(SalesProductSelection::class)->select(
                (int) $product->id,
                $productUnitId,
                $this->exchange_rate,
                null
            );
        } catch (\InvalidArgumentException|MathException|ModelNotFoundException|InvalidMoneyException|InvalidQuantityException|InvalidUnitConversionException) {
            $this->addError('quickProduct', __('commands.code_validation_failed'));

            return false;
        }

        $emptyIndex = $this->firstTrulyEmptyLineIndex($this->lines, 'unit_price');
        $rowKey = $emptyIndex !== null
            ? $this->lineRowKey($emptyIndex)
            : $this->newUiIdentityKey();

        $newLine = [
            'row_key' => $rowKey,
            'product_id' => (int) $product->id,
            'product_unit_id' => (int) $selected['product_unit_id'],
            'item_description' => (string) $selected['item_description'],
            'quantity' => '1',
            'unit_price' => (string) $selected['unit_price'],
            'discount_type' => 'none',
            'discount_value' => '0',
            'tax_rate_id' => null,
            'available_quantity' => $selected['available_quantity'] ?? null,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'line_total' => '0.00',
        ];

        if ($emptyIndex !== null) {
            $this->lines[$emptyIndex] = $newLine;
        } else {
            $this->lines[] = $newLine;
        }

        $this->resetErrorBag('quickProduct');
        $this->recalculate();

        return true;
    }

    public function addLine(): void
    {
        $this->authorizeQuoteAction();
        $this->lines[] = [
            'row_key' => $this->newUiIdentityKey(),
            'product_id' => null,
            'product_unit_id' => null,
            'item_description' => '',
            'quantity' => '1',
            'unit_price' => '0.00',
            'discount_type' => 'none',
            'discount_value' => '0',
            'tax_rate_id' => null,
            'subtotal' => '0.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'line_total' => '0.00',
        ];
        $this->invalidatePreviewTotals();
    }

    public function removeLine(int|string $lineRef): void
    {
        $this->authorizeQuoteAction();
        $index = $this->resolveLineIndex($lineRef);
        if ($index === null) {
            return;
        }
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        $this->recalculate();
    }

    public function selectProduct(int|string $lineRef, ?int $productId = null, ?int $productUnitId = null): bool
    {
        $this->authorizeQuoteAction();
        $index = $this->resolveLineIndex($lineRef);
        if ($index === null) {
            if (is_int($lineRef)) {
                abort(422);
            }

            return false;
        }

        if ($productId === null || $productId === 0) {
            $this->lines[$index] = $this->preserveLineRowKey($index, [
                'product_id' => null,
                'product_unit_id' => null,
                'item_description' => '',
                'quantity' => '1',
                'unit_price' => '0',
                'discount_type' => 'none',
                'discount_value' => '0',
                'tax_rate_id' => null,
            ]);
            $this->recalculate();

            return true;
        }

        $product = Product::where('company_id', $this->pageCompanyId)
            ->where('id', $productId)
            ->where('active', true)
            ->first();

        if (! $product) {
            return false;
        }

        $selected = app(SalesProductSelection::class)->select($productId, $productUnitId, $this->exchange_rate, null);
        $this->lines[$index] = $this->preserveLineRowKey($index, array_merge($this->lines[$index], $selected));
        $this->recalculate();

        return true;
    }

    public function changeUnit(int|string $lineRef, int $productUnitId): void
    {
        $this->authorizeQuoteAction();
        $index = $this->resolveLineIndex($lineRef);
        if ($index === null) {
            return;
        }
        $productId = $this->lines[$index]['product_id'] ?? null;
        if ($productId === null) {
            return;
        }
        $selected = app(SalesProductSelection::class)->select($productId, $productUnitId, $this->exchange_rate, null);
        $this->lines[$index] = $this->preserveLineRowKey($index, array_merge($this->lines[$index], $selected));
        $this->recalculate();
    }

    #[Locked]
    public bool $documentTotalsValid = false;

    public function recalculate(): void
    {
        $this->ensureLineIdentities();
        $this->documentTotalsValid = false;
        $company = app(CompanyContext::class)->company();
        foreach ($this->lines as $index => $line) {
            foreach (['quantity', 'unit_price'] as $field) {
                if (! is_string($line[$field]) || preg_match('/^\d{1,14}(?:\.\d{1,6})?$/D', $line[$field]) !== 1) {
                    $this->addError("lines.{$index}.{$field}", __('commands.exact_string_required'));

                    return;
                }
            }
            if (! is_string($line['discount_value']) || preg_match('/^\d{1,14}(?:\.\d{1,6})?$/D', $line['discount_value']) !== 1) {
                $this->addError("lines.{$index}.discount_value", __('commands.exact_string_required'));

                return;
            }
        }
        $lineCalculator = app(SalesLineCalculator::class);
        $totalsCalculator = app(SalesDocumentTotalsCalculator::class);

        try {
            $document = $this->quotation;
            $minorUnits = $document !== null && $document->currency_code === $this->currency_code
                ? $document->resolvedCurrencyMinorUnits()
                : app(CurrencyMinorUnitsResolver::class)->resolveForCompany($company, $this->currency_code);
            $this->currency_minor_units = $minorUnits;
        } catch (\InvalidArgumentException) {
            $this->currency_minor_units = null;
            $this->addError('currency_code', __('commands.code_validation_failed'));

            return;
        }
        $baseMinorUnits = app(CurrencyMinorUnitsResolver::class)->resolve($company->base_currency_code);
        $fx = BigDecimal::of($this->exchange_rate ?: '1.0');

        $lineTaxIds = array_map(static fn (array $line): int|false => filter_var($line['tax_rate_id'] ?? null, FILTER_VALIDATE_INT), $this->lines);
        $taxIds = array_values(array_unique(array_filter($lineTaxIds)));
        $taxes = $taxIds === [] ? collect() : TaxRate::query()
            ->where('company_id', $company->id)
            ->whereKey($taxIds)
            ->get()
            ->keyBy('id');

        $discountScope = $this->discount_scope;
        $allocationResult = null;
        $this->resetErrorBag('discount_value');
        $headerDiscountIntent = $this->resolveHeaderDiscountIntent();
        if ($headerDiscountIntent === null) {
            $this->invalidatePreviewTotals();

            return;
        }

        if ($this->hasIncompleteDocumentLine()) {
            $this->invalidatePreviewTotals();

            return;
        }

        if ($discountScope === 'document' && ! $headerDiscountIntent->isNone()) {
            $allocationResult = $this->allocateSalesHeaderDiscount($headerDiscountIntent, $taxes, $lineTaxIds, $minorUnits);
            if ($allocationResult === null) {
                $this->invalidatePreviewTotals();

                return;
            }
        }

        $calcResults = [];

        foreach ($this->lines as $idx => $line) {
            $tax = $taxes->get($lineTaxIds[$idx] ?: 0);
            $taxRate = $tax === null ? null : BigDecimal::of((string) $tax->rate);
            $taxInclusive = $tax?->calculation === TaxRate::CALC_INCLUSIVE;

            if ($discountScope === 'document' && $allocationResult !== null) {
                $allocatedDiscount = $allocationResult->forLine($idx + 1);
                $input = new SalesLineCalculationInput(
                    quantity: $line['quantity'] ?: '0',
                    unitPrice: $line['unit_price'] ?: '0',
                    discountType: 'fixed',
                    discountValue: (string) $allocatedDiscount,
                    taxRate: $taxRate,
                    taxInclusive: $taxInclusive,
                    currencyMinorUnits: $minorUnits,
                    exchangeRate: $fx,
                    baseCurrencyMinorUnits: $baseMinorUnits,
                );
            } else {
                $input = new SalesLineCalculationInput(
                    quantity: $line['quantity'] ?: '0',
                    unitPrice: $line['unit_price'] ?: '0',
                    discountType: $this->quotationDiscountType((string) $line['discount_type']),
                    discountValue: (string) $line['discount_value'],
                    taxRate: $taxRate,
                    taxInclusive: $taxInclusive,
                    currencyMinorUnits: $minorUnits,
                    exchangeRate: $fx,
                    baseCurrencyMinorUnits: $baseMinorUnits,
                );
            }

            $res = $lineCalculator->calculate($input);
            $calcResults[] = $res;

            $this->lines[$idx]['subtotal'] = (string) $res->subtotal;
            $this->lines[$idx]['discount_amount'] = (string) $res->discount;
            $this->lines[$idx]['tax_amount'] = (string) $res->tax;
            $this->lines[$idx]['line_total'] = (string) $res->total;
        }

        $totals = $totalsCalculator->calculate($calcResults);
        $this->previewSubtotal = (string) $totals->subtotalCurrency;
        $this->previewDiscountTotal = (string) $totals->discountTotalCurrency;
        $this->previewTaxTotal = (string) $totals->taxTotalCurrency;
        $this->previewGrandTotal = SalesMoneyFormatter::formatMoneyInput($totals->grandTotalCurrency);
        $this->documentTotalsValid = true;
    }

    private function quotationDiscountType(?string $type): ?string
    {
        return match ($type) {
            'percentage' => 'percent', // Previously saved by the quotation form.
            'none' => null,
            default => $type,
        };
    }

    public function save(CreateQuotationAction $createAction, UpdateQuotationAction $updateAction): mixed
    {
        $this->authorizeQuoteAction();
        $company = app(CompanyContext::class)->company();
        $user = auth()->user();

        if ($this->currency_code === $company->base_currency_code) {
            $this->exchange_rate = '1.0000000000';
        }

        $this->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('company_id', $company->id)],
            'currency_code' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'issue_date' => ['required', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'gte:0'],
        ]);

        $headerDiscountIntent = $this->resolveAndValidateHeaderDiscountForSave($company);
        if ($headerDiscountIntent === null) {
            return null;
        }

        $dtoLines = [];
        foreach ($this->lines as $line) {
            $lineId = $line['id'] ?? null;
            if ($lineId !== null && isset($this->persistedLineDiscounts[$lineId])) {
                $discountType = $this->quotationDiscountType($this->persistedLineDiscounts[$lineId]['discount_type']);
                $discountVal = (string) $this->persistedLineDiscounts[$lineId]['discount_value'];
            } else {
                $discountType = $this->quotationDiscountType((string) $line['discount_type']);
                $discountVal = (string) $line['discount_value'];
            }
            $dtoLines[] = [
                'product_id' => ! empty($line['product_id']) ? (int) $line['product_id'] : null,
                'product_unit_id' => ! empty($line['product_unit_id']) ? (int) $line['product_unit_id'] : null,
                'item_description' => $line['item_description'],
                'quantity' => (string) $line['quantity'],
                'unit_price' => (string) $line['unit_price'],
                'discount_type' => $discountType,
                'discount_value' => $discountVal,
                'tax_rate_id' => ! empty($line['tax_rate_id']) ? (int) $line['tax_rate_id'] : null,
            ];
        }

        $payload = [
            'customer_id' => (int) $this->customer_id,
            'currency_code' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate,
            'issue_date' => $this->issue_date,
            'expiry_date' => $this->expiry_date ?: null,
            'notes' => $this->notes,
            'terms' => $this->terms,
            'document_locale' => $this->document_locale,
            'include_product_images' => $this->include_product_images,
            'lines' => $dtoLines,
        ];

        $normalizedHeaderType = $this->discount_type === 'percentage' ? 'percent' : $this->discount_type;
        if ($this->discount_scope === 'document' && ($normalizedHeaderType !== 'none' || $this->quotation?->discount_type !== null)) {
            if ($normalizedHeaderType === 'none' || $headerDiscountIntent->isNone()) {
                if ($this->quotation?->discount_type !== null) {
                    $payload['discount_type'] = null;
                    $payload['discount_value'] = '0';
                    $payload['discount_scope'] = 'document';
                }
            } else {
                $payload['discount_type'] = $normalizedHeaderType;
                $payload['discount_value'] = trim((string) $this->discount_value);
                $payload['discount_scope'] = 'document';
            }
        }

        if ($this->isEditing && $this->quotation !== null) {
            $quotation = $updateAction->execute($this->quotation, $user, $payload);
            session()->flash('success', __('sales.updated_successfully'));

            return redirect()->route('quotations.show', $quotation->public_id);
        }

        $quotation = $createAction->execute($company, $user, $payload);
        session()->flash('success', __('sales.created_successfully'));

        return redirect()->route('quotations.show', $quotation->public_id);
    }

    public function saveDraft(CreateQuotationAction $createAction, UpdateQuotationAction $updateAction): mixed
    {
        if ($this->showQuickCustomerModal || $this->showQuickProductModal) {
            return null;
        }

        return $this->save($createAction, $updateAction);
    }

    public function render(CompanyContext $context): View
    {
        $this->authorizeQuoteReadOnly();
        $this->ensureLineIdentities();
        $user = auth()->user();
        $company = $context->company();

        $cTerm = mb_substr(trim($this->customerSearch), 0, 100);
        if ($cTerm !== '') {
            $customersQuery = Customer::where('company_id', $company->id)->where('status', 'active');
            ArabicSearchNormalizer::applySearchTerms(
                $customersQuery,
                $cTerm,
                ['name_ar', 'name_en', 'business_name_ar', 'business_name_en', 'code', 'phone'],
                ['name_ar', 'business_name_ar']
            );
            $matchingCustomers = $customersQuery->orderByRaw('COALESCE(NULLIF(name_ar, ""), name_en) ASC')->limit(50)->get();
        } else {
            $matchingCustomers = collect();
        }
        if ($this->customer_id) {
            $selectedCustomer = Customer::where('company_id', $company->id)
                ->where('id', $this->customer_id)
                ->where('status', 'active')
                ->first();
            $customers = $selectedCustomer !== null ? $matchingCustomers->push($selectedCustomer)->unique('id') : $matchingCustomers;
        } else {
            $customers = $matchingCustomers;
        }

        $currencies = $company->currencies()->where('enabled', true)->get();

        $term = mb_substr(trim($this->productSearch), 0, 160);
        $selectedProductIds = collect($this->lines)->pluck('product_id')->filter()->unique()->values()->all();
        $selectedProducts = $selectedProductIds !== []
            ? Product::with(['productUnits.unit', 'baseUnit'])->where('company_id', $company->id)
                ->whereIn('id', $selectedProductIds)->get()
            : collect();
        abort_unless($selectedProducts->count() === count($selectedProductIds), 404);
        $matchingProducts = collect();
        if ($term !== '') {
            $productsQuery = Product::where('company_id', $company->id)->where('active', true);
            ArabicSearchNormalizer::applySearchTerms(
                $productsQuery,
                $term,
                ['name_ar', 'name_en', 'sku'],
                ['name_ar'],
                fn ($q) => $q->orWhereHas('barcodes', fn ($barcodes) => $barcodes->where('company_id', $company->id)->where('barcode', $term))
            );
            $matchingProducts = $productsQuery->with(['productUnits.unit', 'baseUnit'])->orderByRaw('COALESCE(name_ar, name_en) ASC')->orderBy('id')->limit(50)->get();
        }
        $products = $matchingProducts->merge($selectedProducts)->unique('id');
        $lastCustomerPrices = $this->customer_id !== null && count($selectedProductIds) <= 100
            && app(ReportingGuard::class)->allows($company, ['reports.sales.view', 'sales.invoice.view'], $user)
            ? app(CustomerLastPriceQuery::class)->execute($company, $user, $this->customer_id, array_map('intval', $selectedProductIds)) : [];

        $taxes = TaxRate::where('company_id', $company->id)->where('active', true)->get();

        return view('livewire.pages.sales.quotation-form', [
            'customers' => $customers,
            'currencies' => $currencies,
            'products' => $products,
            'productMatchCount' => $matchingProducts->count(),
            'taxes' => $taxes,
            'canManageCustomers' => $user->hasPermissionTo('customers.manage'),
            'lastCustomerPrices' => $lastCustomerPrices,
            'baseCurrencyCode' => $company->base_currency_code,
        ]);
    }
}
