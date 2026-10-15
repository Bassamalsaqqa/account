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
use App\Domain\Sales\Queries\CustomerLastPriceQuery;
use App\Livewire\Concerns\QuickPartyCreate;
use App\Livewire\Concerns\QuickProductCreate;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Quotation;
use App\Models\TaxRate;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesProductSelection;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
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
    use QuickPartyCreate;
    use QuickProductCreate;

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

            $this->lines = [];
            foreach ($this->quotation->lines as $line) {
                $this->lines[] = [
                    'id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_unit_id' => $line->product_unit_id,
                    'item_description' => $line->item_description,
                    'quantity' => (string) $line->quantity,
                    'unit_price' => (string) $line->unit_price,
                    'discount_type' => $line->discount_type ?? 'none',
                    'discount_value' => (string) ($line->discount_value ?? '0'),
                    'tax_rate_id' => $line->tax_rate_id,
                    'subtotal' => (string) $line->line_subtotal,
                    'discount_amount' => (string) $line->line_discount,
                    'tax_amount' => (string) $line->line_tax,
                    'line_total' => (string) $line->line_total,
                ];
            }
        } else {
            if (! $user->hasPermissionTo('sales.quote.create')) {
                abort(403, 'Unauthorized.');
            }

            $this->currency_code = $company->base_currency_code;
            $this->issue_date = Carbon::now($company->timezone)->toDateString();
            $this->document_locale = $company->documentSettings->default_document_locale ?? $company->default_locale ?? 'ar';
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

    public function hydrate(CompanyContext $context): void
    {
        $this->authorizeQuoteAction();
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
                $this->currency_code = $customer->default_currency_code ?? app(CompanyContext::class)->company()->base_currency_code;
                $this->document_locale = $customer->preferred_locale ?? app(CompanyContext::class)->company()->default_locale;
                $this->recalculate();
            } else {
                $this->customer_id = null;
            }
        }
    }

    public function updatedCurrencyCode(): void
    {
        $this->authorizeQuoteAction();
        $company = app(CompanyContext::class)->company();
        if ($this->currency_code === $company->base_currency_code) {
            $this->exchange_rate = '1.0000000000';
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
                $this->currency_code,
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
                && empty($this->lines[0]['product_id'])
                && trim((string) ($this->lines[0]['item_description'])) === ''
                && in_array((string) ($this->lines[0]['quantity']), ['1', '1.000000', '1.00', ''], true)
                && in_array((string) ($this->lines[0]['unit_price']), ['0', '0.00', '0.000000', ''], true)
                && (($this->lines[0]['discount_type']) === 'none')
                && empty($this->lines[0]['tax_rate_id']);

            $newLine = [
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

    public function addLine(): void
    {
        $this->authorizeQuoteAction();
        $this->lines[] = [
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
    }

    public function removeLine(int $index): void
    {
        $this->authorizeQuoteAction();
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        $this->recalculate();
    }

    public function selectProduct(int $index, int $productId): void
    {
        $this->authorizeQuoteAction();
        $selected = app(SalesProductSelection::class)->select($productId, null, $this->currency_code, $this->exchange_rate, null);
        $this->lines[$index] = array_merge($this->lines[$index], $selected);
        $this->recalculate();
    }

    public function changeUnit(int $index, int $productUnitId): void
    {
        $this->authorizeQuoteAction();
        $productId = $this->lines[$index]['product_id'] ?? null;
        if ($productId === null) {
            return;
        }
        $selected = app(SalesProductSelection::class)->select($productId, $productUnitId, $this->currency_code, $this->exchange_rate, null);
        $this->lines[$index] = array_merge($this->lines[$index], $selected);
        $this->recalculate();
    }

    public function recalculate(): void
    {
        $company = app(CompanyContext::class)->company();
        foreach ($this->lines as $index => $line) {
            foreach (['quantity', 'unit_price', 'discount_value'] as $field) {
                if (! is_string($line[$field])) {
                    $this->addError("lines.{$index}.{$field}", __('commands.exact_string_required'));

                    return;
                }
            }
        }
        $lineCalculator = app(SalesLineCalculator::class);
        $totalsCalculator = app(SalesDocumentTotalsCalculator::class);

        $minorUnits = in_array($this->currency_code, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;
        $baseMinorUnits = in_array($company->base_currency_code, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;
        $fx = BigDecimal::of($this->exchange_rate ?: '1.0');

        $calcResults = [];

        foreach ($this->lines as $idx => $line) {
            $taxRate = null;
            $taxInclusive = false;
            if (! empty($line['tax_rate_id'])) {
                $tr = TaxRate::find($line['tax_rate_id']);
                if ($tr !== null) {
                    $taxRate = BigDecimal::of((string) $tr->rate);
                    $taxInclusive = $tr->calculation === TaxRate::CALC_INCLUSIVE;
                }
            }

            $input = new SalesLineCalculationInput(
                quantity: $line['quantity'] ?: '0',
                unitPrice: $line['unit_price'] ?: '0',
                discountType: $line['discount_type'] !== 'none' ? $line['discount_type'] : null,
                discountValue: $line['discount_value'] ?: '0',
                taxRate: $taxRate,
                taxInclusive: $taxInclusive,
                currencyMinorUnits: $minorUnits,
                exchangeRate: $fx,
                baseCurrencyMinorUnits: $baseMinorUnits,
            );

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
        $this->previewGrandTotal = (string) $totals->grandTotalCurrency;
    }

    public function save(CreateQuotationAction $createAction, UpdateQuotationAction $updateAction): mixed
    {
        $this->authorizeQuoteAction();
        $company = app(CompanyContext::class)->company();
        $user = auth()->user();

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

        $dtoLines = [];
        foreach ($this->lines as $line) {
            $dtoLines[] = [
                'product_id' => ! empty($line['product_id']) ? (int) $line['product_id'] : null,
                'product_unit_id' => ! empty($line['product_unit_id']) ? (int) $line['product_unit_id'] : null,
                'item_description' => $line['item_description'],
                'quantity' => (string) $line['quantity'],
                'unit_price' => (string) $line['unit_price'],
                'discount_type' => $line['discount_type'] !== 'none' ? $line['discount_type'] : null,
                'discount_value' => (string) $line['discount_value'],
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
        $this->authorizeQuoteAction();
        $user = auth()->user();
        $company = $context->company();

        $cTerm = mb_substr(trim($this->customerSearch), 0, 100);
        $customersQuery = Customer::where('company_id', $company->id)->where('status', 'active');
        if ($cTerm !== '') {
            $customersQuery->where(function ($q) use ($cTerm) {
                $q->where('name_ar', 'like', "%{$cTerm}%")
                    ->orWhere('name_en', 'like', "%{$cTerm}%")
                    ->orWhere('code', 'like', "%{$cTerm}%")
                    ->orWhere('phone', 'like', "%{$cTerm}%");
            });
        }
        $matchingCustomers = $customersQuery->orderBy('name_ar')->limit(50)->get();
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
        $productsQuery = Product::where('company_id', $company->id)->where('active', true);
        if ($term !== '') {
            $productsQuery->where(fn ($query) => $query->where('name_ar', 'like', "%{$term}%")
                ->orWhere('name_en', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%")
                ->orWhereHas('barcodes', fn ($barcodes) => $barcodes->where('company_id', $company->id)->where('barcode', $term)));
        }
        $selectedProductIds = collect($this->lines)->pluck('product_id')->filter()->unique()->values()->all();
        $selectedProducts = Product::with(['productUnits.unit', 'baseUnit'])->where('company_id', $company->id)
            ->whereIn('id', $selectedProductIds)->get();
        abort_unless($selectedProducts->count() === count($selectedProductIds), 404);
        $matchingProducts = $productsQuery->with(['productUnits.unit', 'baseUnit'])->orderBy('name_ar')->limit(50)->get();
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
        ]);
    }
}
