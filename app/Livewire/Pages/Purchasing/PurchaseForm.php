<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\UpdatePurchaseDraftAction;
use App\Domain\Inventory\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Domain\Money\Exceptions\InvalidMoneyException;
use App\Domain\Purchasing\PurchaseCalculator;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Company;
use App\Models\Product;
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
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseForm extends Component
{
    use AuthorizesPurchasingPages;

    #[Locked]
    public ?string $publicId = null;

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

    public function mount(CompanyContext $context, ?string $publicId = null): void
    {
        $this->pageCompanyId = $context->companyId();
        $this->publicId = $publicId;
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
        $data = [];
        foreach (['vendor_id', 'vendor_invoice_number', 'warehouse_id', 'purchase_date', 'due_date', 'currency_code', 'exchange_rate', 'document_locale', 'notes', 'lines'] as $field) {
            $data[$field] = $this->{$field};
        }
        try {
            $purchase = $this->publicId === null
                ? app(CreatePurchaseDraftAction::class)->execute($company, auth()->user(), $data)
                : app(UpdatePurchaseDraftAction::class)->execute(Purchase::where('company_id', $company->id)->where('public_id', $this->publicId)->firstOrFail(), auth()->user(), $data);
        } catch (\InvalidArgumentException|MathException|InvalidMoneyException|InvalidQuantityException|InvalidUnitConversionException $exception) {
            $this->addError('draft', __('purchasing.invalid_draft'));

            return null;
        }
        session()->flash('success', __('purchasing.draft_saved'));

        return redirect()->route('purchases.show', $purchase->public_id);
    }

    public function render(): View
    {
        $company = $this->authorizeForm();
        $term = trim($this->productSearch);
        $products = Product::where('company_id', $company->id)->where('active', true)->where('product_type', Product::TYPE_STOCK)->where('track_stock', true);
        if ($term !== '') {
            $products->where(fn ($q) => $q->where('name_ar', 'like', "%{$term}%")->orWhere('name_en', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")->orWhereHas('barcodes', fn ($b) => $b->where('company_id', $company->id)->where('barcode', $term)));
        }
        $unitOptions = [];
        $expiryLines = [];
        foreach ($this->lines as $index => $line) {
            if (! empty($line['product_id'])) {
                $product = Product::where('company_id', $company->id)->findOrFail($line['product_id']);
                $expiryLines[$index] = $product->track_expiry;
                $unitOptions[$index] = ProductUnit::where('company_id', $company->id)->where('product_id', $product->id)
                    ->where('active', true)->whereHas('unit', fn ($q) => $q->where('company_id', $company->id)->where('active', true))
                    ->with('unit')->get();
            }
        }
        $except = $this->publicId === null ? null : Purchase::where('company_id', $company->id)->where('public_id', $this->publicId)->value('id');

        return view('livewire.pages.purchasing.purchase-form', [
            'products' => $products->orderBy('name_ar')->limit(50)->get(['id', 'name_ar', 'name_en', 'sku']),
            'vendors' => Vendor::where('company_id', $company->id)->where('status', 'active')->orderBy('name_ar')->get(),
            'warehouses' => Warehouse::where('company_id', $company->id)->where('active', true)->get(),
            'currencies' => $company->currencies()->where('enabled', true)->get(),
            'languages' => $company->languages()->where('enabled', true)->get(),
            'taxes' => TaxRate::where('company_id', $company->id)->where('active', true)->get(),
            'unitOptions' => $unitOptions, 'expiryLines' => $expiryLines,
            'duplicateWarning' => app(DuplicateVendorInvoice::class)->exists($company->id, $this->vendor_id, $this->vendor_invoice_number, $except),
        ]);
    }
}
