<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\UpdatePurchaseDraftAction;
use App\Application\AI\AiDraftProvenance;
use App\Application\AI\PurchaseDraftCommands;
use App\Domain\Inventory\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Domain\Money\Exceptions\InvalidMoneyException;
use App\Domain\Purchasing\PurchaseCalculator;
use App\Domain\Purchasing\Queries\VendorProductPriceHistoryQuery;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Livewire\Concerns\AiUnsavedForm;
use App\Livewire\Concerns\QuickPartyCreate;
use App\Livewire\Concerns\QuickProductCreate;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\TaxRate;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Purchasing\DuplicateVendorInvoice;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Services\Purchasing\PurchaseDraftBuilder;
use App\Services\Purchasing\PurchaseProductSelection;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseForm extends Component
{
    use AiUnsavedForm;
    use AuthorizesPurchasingPages;
    use QuickPartyCreate;
    use QuickProductCreate;

    protected function quickProductFormPermission(): string
    {
        return $this->quickPartyFormPermission();
    }

    protected function assertQuickProductDraft(): void
    {
        $this->authorizeForm();
    }

    protected function quickPartyKind(): string
    {
        return 'vendor';
    }

    protected function quickPartyFormPermission(): string
    {
        return $this->publicId === null ? 'purchasing.purchase.create' : 'purchasing.purchase.edit_draft';
    }

    protected function authorizeQuickPartyForm(): Company
    {
        return $this->authorizeForm();
    }

    protected function selectQuickParty(int $id): void
    {
        $this->vendor_id = $id;
        $this->updatedVendorId();
    }

    #[Locked]
    public ?string $publicId = null;

    #[Locked]
    public ?string $savedDraftPublicId = null;

    #[Locked]
    public ?string $savedDraftPayloadHash = null;

    public ?int $vendor_id = null;

    public ?int $warehouse_id = null;

    public ?string $vendor_invoice_number = null;

    public string $purchase_date = '';

    public ?string $due_date = null;

    public string $currency_code = 'ILS';

    #[Locked]
    public bool $currencyManuallySelected = false;

    #[Locked]
    public string $previousCurrencyCode = 'ILS';

    public string $exchange_rate = '1.0000000000';

    public string $document_locale = 'ar';

    public ?string $notes = null;

    #[Locked]
    public bool $receivingStock = false;

    public string $barcodeSearch = '';

    public string $vendorSearch = '';

    public string $productSearch = '';

    /** @var list<array<string, mixed>> */
    public array $lines = [];

    /** @var array<string, string> */
    public array $totals = [];

    protected function authorizeForm(): Company
    {
        $company = $this->authorizePurchasing($this->publicId === null ? 'purchasing.purchase.create' : 'purchasing.purchase.edit_draft');
        $this->authorizePurchasing('purchasing.cost.view');
        if ($this->publicId !== null) {
            Purchase::where('company_id', $company->id)->where('public_id', $this->publicId)->firstOrFail()->assertMutableDraft();
        }

        return $company;
    }

    public function mount(CompanyContext $context, ?string $publicId = null, ?string $receiveProduct = null): void
    {
        $this->pageCompanyId = $context->companyId();
        $this->publicId = $publicId;
        $receiveProduct ??= request()->query('receiveProduct');
        abort_unless($receiveProduct === null || is_string($receiveProduct), 422);
        $company = $this->authorizeForm();
        if ($publicId !== null) {
            $purchase = Purchase::where('company_id', $company->id)->where('public_id', $publicId)->firstOrFail();
            foreach (app(PurchaseDraftBuilder::class)->editableData($purchase) as $key => $value) {
                $this->{$key} = $value;
            }
            $this->currencyManuallySelected = true;
        } else {
            $this->purchase_date = now($company->timezone)->format('Y-m-d');
            $this->due_date = app(PurchaseDocumentRules::class)->defaultDueDate($company, $this->purchase_date);
            $this->currency_code = $company->base_currency_code;
            $this->document_locale = $company->default_locale;
            try {
                $this->warehouse_id = app(PurchaseDocumentRules::class)->warehouse($company, null)->id;
            } catch (\InvalidArgumentException) {
                $this->warehouse_id = null;
            }
            $this->addLine();
            if ($receiveProduct !== null) {
                $product = Product::where('company_id', $company->id)->where('public_id', $receiveProduct)
                    ->where('active', true)->where('track_stock', true)->firstOrFail();
                $this->selectProduct(0, $product->id);
                $this->receivingStock = true;
            }
        }
        if ($publicId === null) {
            $this->mountAiInput('purchase');
        }
        $this->previousCurrencyCode = $this->currency_code;
        $this->recalculate();
    }

    public function hydrate(): void
    {
        $this->authorizeForm();
    }

    public function updatedVendorId(): void
    {
        $company = $this->authorizeForm();
        if ($this->vendor_id !== null) {
            $vendor = app(PurchaseDocumentRules::class)->vendor($company, $this->vendor_id);
            $this->document_locale = app(PurchaseDocumentRules::class)->defaultLocale($company, $vendor);
            if (! $this->currencyManuallySelected) {
                $this->initializeCurrency($company, app(PurchaseDocumentRules::class)->defaultCurrency($company, $vendor));
            }
        }
    }

    public function updatedCurrencyCode(): void
    {
        $company = $this->authorizeForm();
        $this->currencyManuallySelected = true;
        $this->initializeCurrency($company, $this->currency_code);
    }

    private function initializeCurrency(Company $company, string $currency): void
    {
        $changed = $currency !== $this->previousCurrencyCode;
        $this->currency_code = $currency;
        if ($currency === $company->base_currency_code) {
            $this->exchange_rate = '1.0000000000';
        } elseif ($changed) {
            // No authoritative quote exists; require a rate for the new currency.
            $this->exchange_rate = '';
        }
        if ($changed) {
            foreach ($this->lines as &$line) {
                if (! empty($line['product_id'])) {
                    // Supplier costs and discounts cannot be reinterpreted in another currency.
                    $line['unit_cost'] = '';
                    $line['discount_type'] = 'none';
                    $line['discount_value'] = '0';
                    $this->addError('currency', __('purchasing.currency_changed_reenter_costs'));
                }
            }
        }
        $this->previousCurrencyCode = $currency;
        $this->recalculate();
    }

    public function updatedExchangeRate(): void
    {
        $this->recalculate();
    }

    public function updatedLines(): void
    {
        $this->recalculate();
    }

    public function saveDraft(): mixed
    {
        if ($this->showQuickVendorModal || $this->showQuickProductModal) {
            return null;
        }

        if ($this->savedDraftPublicId !== null) {
            $this->authorizeForm();
            if (! hash_equals((string) $this->savedDraftPayloadHash, hash('sha256', json_encode($this->draftData(), JSON_THROW_ON_ERROR)))) {
                $this->addError('draft', __('workflow.saved_draft_changed'));

                return null;
            }

            return redirect()->route('purchases.show', $this->savedDraftPublicId);
        }

        return $this->save();
    }

    public function scanBarcode(): void
    {
        $this->authorizeForm();
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
        $barcode = ProductBarcode::with(['product.productUnits.unit', 'unit'])
            ->where('company_id', $company->id)
            ->whereHas('product', fn ($q) => $q->where('company_id', $company->id)
                ->where('active', true)
                ->where('product_type', Product::TYPE_STOCK)
                ->where('track_stock', true))
            ->where('barcode', $term)
            ->first();

        $product = null;
        $productUnit = null;

        if ($barcode !== null) {
            $product = $barcode->product;
            if ($barcode->unit_id !== null) {
                // Must strictly find active unit in same company for this product
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
            $product = Product::with(['productUnits.unit'])
                ->where('company_id', $company->id)
                ->where('active', true)
                ->where('product_type', Product::TYPE_STOCK)
                ->where('track_stock', true)
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
            $selected = app(PurchaseProductSelection::class)->select(
                (int) $product->id,
                $productUnit?->id,
                $this->currency_code,
                $this->exchange_rate
            );
        } catch (\InvalidArgumentException|ModelNotFoundException|InvalidMoneyException|InvalidQuantityException|InvalidUnitConversionException $e) {
            $this->addError('barcodeSearch', __('purchasing.invalid_draft'));

            return;
        }

        $selectedUnitId = (int) $selected['product_unit_id'];
        $selectedCost = (string) $selected['unit_cost'];
        $selectedDescription = (string) $selected['item_description'];

        // Preserve lot/line data; safest append or fill truly empty row, do not silently merge differing unit/lot/cost/tax/discount/description lines.
        $existingIndex = null;
        foreach ($this->lines as $idx => $line) {
            $linePid = ! empty($line['product_id']) ? (int) $line['product_id'] : null;
            $lineUnitId = ! empty($line['product_unit_id']) ? (int) $line['product_unit_id'] : null;
            $lineCost = (string) ($line['unit_cost'] ?? '');
            $lineDesc = (string) ($line['item_description'] ?? '');
            $hasLots = ! empty($line['lots']);
            $discountType = $line['discount_type'] ?? 'none';
            $taxRateId = $line['tax_rate_id'] ?? null;

            if ($linePid === (int) $product->id
                && $lineUnitId === $selectedUnitId
                && ! $hasLots
                && $lineCost === $selectedCost
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
                && trim((string) ($this->lines[0]['item_description'] ?? '')) === ''
                && empty($this->lines[0]['lots'])
                && in_array((string) ($this->lines[0]['quantity'] ?? ''), ['1', '1.000000', '1.00', ''], true)
                && in_array((string) ($this->lines[0]['unit_cost'] ?? ''), ['0', '0.00', '0.000000', ''], true)
                && (($this->lines[0]['discount_type'] ?? 'none') === 'none')
                && empty($this->lines[0]['tax_rate_id']);

            $newLine = array_replace([
                'product_id' => $product->id,
                'product_unit_id' => $selectedUnitId,
                'item_description' => $selectedDescription,
                'quantity' => '1',
                'unit_cost' => $selectedCost,
                'discount_type' => 'none',
                'discount_value' => '0',
                'tax_rate_id' => null,
                'lots' => [],
            ], $selected);

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
        $this->authorizeForm();
        $this->lines[] = ['product_id' => null, 'product_unit_id' => null, 'item_description' => '',
            'quantity' => '1', 'unit_cost' => '0', 'discount_type' => 'none', 'discount_value' => '0', 'tax_rate_id' => null, 'lots' => []];
    }

    public function removeLine(int $index): void
    {
        $this->authorizeForm();
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        $this->recalculate();
    }

    public function selectProduct(int $index, mixed $productId): void
    {
        $this->authorizeForm();
        abort_unless(isset($this->lines[$index]), 422);
        if ($productId === '') {
            $this->lines[$index] = ['product_id' => null, 'product_unit_id' => null, 'item_description' => '',
                'quantity' => '1', 'unit_cost' => '0', 'discount_type' => 'none', 'discount_value' => '0', 'tax_rate_id' => null, 'lots' => []];
            $this->recalculate();

            return;
        }
        try {
            $selected = app(PurchaseProductSelection::class)->select((int) $productId, null, $this->currency_code, $this->exchange_rate);
        } catch (\InvalidArgumentException|InvalidMoneyException|InvalidUnitConversionException $exception) {
            $this->addError('draft', __('purchasing.invalid_draft'));

            return;
        }
        unset($this->lines[$index]['public_id']);
        $this->lines[$index] = array_replace($this->lines[$index], $selected, ['lots' => []]);
        $this->recalculate();
    }

    public function changeUnit(int $index, mixed $unitId): void
    {
        $this->authorizeForm();
        abort_unless(isset($this->lines[$index]['product_id']), 422);
        if ($unitId === '') {
            $this->lines[$index]['product_unit_id'] = null;
            $this->lines[$index]['lots'] = [];

            return;
        }
        try {
            $selected = app(PurchaseProductSelection::class)->select($this->lines[$index]['product_id'], (int) $unitId, $this->currency_code, $this->exchange_rate);
        } catch (\InvalidArgumentException|InvalidMoneyException|InvalidUnitConversionException $exception) {
            $this->addError('draft', __('purchasing.invalid_draft'));

            return;
        }
        $this->lines[$index] = array_replace($this->lines[$index], $selected, ['lots' => []]);
        $this->recalculate();
    }

    public function addLot(int $index): void
    {
        $company = $this->authorizeForm();
        $line = $this->lines[$index] ?? [];
        $product = Product::where('company_id', $company->id)->findOrFail($line['product_id'] ?? null);
        abort_unless($product->track_expiry, 422);
        $this->lines[$index]['lots'][] = ['product_unit_id' => $line['product_unit_id'], 'lot_number' => '', 'expiry_date' => null, 'quantity' => '1'];
    }

    public function removeLot(int $index, int $lot): void
    {
        $this->authorizeForm();
        unset($this->lines[$index]['lots'][$lot]);
        $this->lines[$index]['lots'] = array_values($this->lines[$index]['lots']);
    }

    public function recalculate(): void
    {
        $this->authorizeForm();
        $this->resetErrorBag('calculation');
        $this->resetErrorBag('exchange_rate');
        $results = [];
        try {
            foreach ($this->lines as $index => $line) {
                $tax = ! empty($line['tax_rate_id']) ? TaxRate::where('company_id', $this->pageCompanyId)->where('active', true)->findOrFail($line['tax_rate_id']) : null;
                $result = app(PurchaseCalculator::class)->line($line['quantity'], $line['unit_cost'], $line['discount_type'] ?? null,
                    $line['discount_value'], $tax?->rate, $tax?->calculation === TaxRate::CALC_INCLUSIVE, $this->currency_code, $this->exchange_rate);
                $this->lines[$index]['preview_total'] = (string) $result->total;
                $results[] = $result;
            }
            $total = app(SalesDocumentTotalsCalculator::class)->calculate($results);
            $this->totals = ['subtotal' => (string) $total->subtotalCurrency, 'discount' => (string) $total->discountTotalCurrency,
                'tax' => (string) $total->taxTotalCurrency, 'total' => (string) $total->grandTotalCurrency];
            $this->resetErrorBag('currency');
        } catch (\InvalidArgumentException|MathException|ModelNotFoundException|InvalidMoneyException|InvalidQuantityException|InvalidUnitConversionException $exception) {
            $this->totals = [];
            foreach ($this->lines as &$line) {
                unset($line['preview_total']);
            }
            $this->addError('calculation', __('purchasing.invalid_line_amounts'));
            if ($this->exchange_rate === '') {
                $this->addError('exchange_rate', __('purchasing.exchange_rate_required'));
            }
        }
    }

    public function save(): mixed
    {
        $company = $this->authorizeForm();
        $data = $this->draftData();
        try {
            $purchase = $this->publicId === null
                ? ($this->aiFormToken !== null && $this->aiFormKey !== null
                    ? DB::transaction(function () use ($company): Purchase {
                        app(AiDraftProvenance::class)->source($company, auth()->user(), $this->aiFormToken, session()->getId(), $this->aiFormKey, 'purchase', $this->aiFormRevision);

                        return app(PurchaseDraftCommands::class)->create($company, auth()->user(), $this->aiEditablePayload(), $this->aiFormKey, $this->aiDraftSourceId);
                    })
                    : app(CreatePurchaseDraftAction::class)->execute($company, auth()->user(), $data))
                : app(UpdatePurchaseDraftAction::class)->execute(Purchase::where('company_id', $company->id)->where('public_id', $this->publicId)->firstOrFail(), auth()->user(), $data);
        } catch (\InvalidArgumentException|MathException|InvalidMoneyException|InvalidQuantityException|InvalidUnitConversionException $exception) {
            $this->addError('draft', __('purchasing.invalid_draft'));

            return null;
        }
        $this->savedDraftPublicId = $purchase->public_id;
        $this->savedDraftPayloadHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $this->discardAiInput();
        session()->flash('success', __('purchasing.draft_saved'));

        return redirect()->route('purchases.show', $purchase->public_id);
    }

    /** @return array<string, mixed> */
    private function draftData(): array
    {
        $data = [];
        foreach (['vendor_id', 'vendor_invoice_number', 'warehouse_id', 'purchase_date', 'due_date', 'currency_code', 'exchange_rate', 'document_locale', 'notes', 'lines'] as $field) {
            $data[$field] = $this->{$field};
        }

        return $data;
    }

    public function render(): View
    {
        $company = $this->authorizeForm();
        $term = mb_substr(trim($this->productSearch), 0, 160);
        $products = Product::where('company_id', $company->id)->where('active', true)->where('product_type', Product::TYPE_STOCK)->where('track_stock', true);
        if ($term !== '') {
            $products->where(fn ($q) => $q->where('name_ar', 'like', "%{$term}%")->orWhere('name_en', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")->orWhereHas('barcodes', fn ($b) => $b->where('company_id', $company->id)->where('barcode', $term)));
        }
        $unitOptions = [];
        $expiryLines = [];
        $selectedProductIds = collect($this->lines)->pluck('product_id')->filter()->unique()->values()->all();
        $selectedProducts = Product::where('company_id', $company->id)->whereIn('id', $selectedProductIds)
            ->with(['productUnits' => fn ($units) => $units->where('company_id', $company->id)->where('active', true)
                ->whereHas('unit', fn ($unit) => $unit->where('company_id', $company->id)->where('active', true))->with('unit')])->get()->keyBy('id');
        abort_unless($selectedProducts->count() === count($selectedProductIds), 404);
        foreach ($this->lines as $index => $line) {
            if (! empty($line['product_id'])) {
                $product = $selectedProducts->get($line['product_id']);
                $expiryLines[$index] = $product->track_expiry;
                $unitOptions[$index] = $product->productUnits;
            }
        }
        $except = $this->publicId === null ? null : Purchase::where('company_id', $company->id)->where('public_id', $this->publicId)->value('id');

        $lastPriceHints = [];
        if ($this->vendor_id !== null && auth()->check() && auth()->user()->can('purchasing.cost.view')) {
            $productIds = collect($this->lines)
                ->pluck('product_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if (! empty($productIds)) {
                $hintsByProduct = app(VendorProductPriceHistoryQuery::class)
                    ->latestForProducts($this->vendor_id, $productIds, $company->id);

                foreach ($this->lines as $index => $line) {
                    $pid = (int) ($line['product_id'] ?? 0);
                    if ($pid > 0 && isset($hintsByProduct[$pid])) {
                        $item = $hintsByProduct[$pid];
                        $lastPriceHints[$index] = [
                            'unit_cost' => $item->unit_cost,
                            'currency_code' => $item->currency_code,
                            'unit_name' => $item->unit_name,
                            'purchase_date' => $item->purchase_date,
                            'purchase_number' => $item->purchase_number,
                        ];
                    }
                }
            }
        }

        $vTerm = mb_substr(trim($this->vendorSearch), 0, 100);
        $vendorsQuery = Vendor::where('company_id', $company->id)->where('status', 'active');
        if ($vTerm !== '') {
            $vendorsQuery->where(function ($q) use ($vTerm) {
                $q->where('name_ar', 'like', "%{$vTerm}%")
                    ->orWhere('name_en', 'like', "%{$vTerm}%")
                    ->orWhere('code', 'like', "%{$vTerm}%")
                    ->orWhere('phone', 'like', "%{$vTerm}%");
            });
        }
        $matchingVendors = $vendorsQuery->orderBy('name_ar')->limit(50)->get();
        if ($this->vendor_id) {
            $selectedVendor = Vendor::where('company_id', $company->id)
                ->where('id', $this->vendor_id)
                ->where('status', 'active')
                ->first();
            $vendors = $selectedVendor !== null ? $matchingVendors->push($selectedVendor)->unique('id') : $matchingVendors;
        } else {
            $vendors = $matchingVendors;
        }

        return view('livewire.pages.purchasing.purchase-form', [
            'products' => $products->orderBy('name_ar')->limit(50)->get(['id', 'name_ar', 'name_en', 'sku']),
            'vendors' => $vendors,
            'warehouses' => Warehouse::where('company_id', $company->id)->where('active', true)->get(),
            'currencies' => $company->currencies()->where('enabled', true)->get(),
            'languages' => $company->languages()->where('enabled', true)->get(),
            'taxes' => TaxRate::where('company_id', $company->id)->where('active', true)->get(),
            'unitOptions' => $unitOptions, 'expiryLines' => $expiryLines,
            'duplicateWarning' => app(DuplicateVendorInvoice::class)->exists($company->id, $this->vendor_id, $this->vendor_invoice_number, $except),
            'lastPriceHints' => $lastPriceHints,
            'canManageVendors' => auth()->user()->can('vendors.manage'),
        ]);
    }
}
