<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\UpdateSalesInvoiceDraftAction;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use App\Domain\Sales\Queries\CustomerCreditLimitQuery;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\Sales\SalesProductSelection;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class InvoiceForm extends Component
{
    public ?SalesInvoice $invoice = null;

    public bool $isEditing = false;

    public ?int $customer_id = null;

    public ?int $warehouse_id = null;

    public string $currency_code = 'ILS';

    public string $exchange_rate = '1.0000000000';

    public string $issue_date = '';

    public string $due_date = '';

    public ?string $notes = null;

    public ?string $terms = null;

    public string $document_locale = 'ar';

    public string $barcodeSearch = '';

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
     * }>
     */
    public array $lines = [];

    // Preview totals
    public string $previewSubtotal = '0.00';

    public string $previewDiscountTotal = '0.00';

    public string $previewTaxTotal = '0.00';

    public string $previewGrandTotal = '0.00';

    public function mount(CompanyContext $context, ?string $publicId = null, ?int $customer_id = null): void
    {
        $company = $context->company();
        $user = auth()->user();

        if ($publicId !== null) {
            if (! $user->hasPermissionTo('sales.invoice.edit_draft')) {
                abort(403, 'Unauthorized.');
            }

            $this->invoice = SalesInvoice::with('lines')
                ->where('company_id', $company->id)
                ->where('public_id', $publicId)
                ->firstOrFail();

            if (! $this->invoice->isDraft()) {
                abort(400, 'Cannot edit invoice in non-draft status.');
            }

            $this->isEditing = true;
            $this->customer_id = $this->invoice->customer_id;
            $this->warehouse_id = $this->invoice->warehouse_id;
            $this->currency_code = $this->invoice->currency_code;
            $this->exchange_rate = (string) $this->invoice->exchange_rate;
            $this->issue_date = $this->invoice->issue_date->toDateString();
            $this->due_date = $this->invoice->due_date ? $this->invoice->due_date->toDateString() : Carbon::now()->addDays(30)->toDateString();
            $this->notes = $this->invoice->notes;
            $this->terms = $this->invoice->terms;
            $this->document_locale = $this->invoice->document_locale ?? 'ar';

            $this->lines = [];
            foreach ($this->invoice->lines as $line) {
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
            if (! $user->hasPermissionTo('sales.invoice.create')) {
                abort(403, 'Unauthorized.');
            }

            $this->customer_id = $customer_id;
            $this->currency_code = $company->base_currency_code;
            $this->issue_date = Carbon::now()->toDateString();
            $this->due_date = Carbon::now()->addDays(30)->toDateString();
            $this->document_locale = $company->default_locale ?? 'ar';
            $this->warehouse_id = $company->inventorySettings?->default_warehouse_id;

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

        $this->updatedWarehouseId();
        $this->recalculate();
    }

    public function updatedCustomerId(): void
    {
        if ($this->customer_id !== null) {
            $customer = Customer::find($this->customer_id);
            if ($customer !== null) {
                $this->currency_code = $customer->default_currency_code ?? app(CompanyContext::class)->company()->base_currency_code;
                $this->document_locale = $customer->preferred_locale ?? app(CompanyContext::class)->company()->default_locale;
                $this->recalculate();
            }
        }
    }

    public function updatedCurrencyCode(): void
    {
        $company = app(CompanyContext::class)->company();
        if ($this->currency_code === $company->base_currency_code) {
            $this->exchange_rate = '1.0000000000';
        }
        $this->recalculate();
    }

    public function scanBarcode(): void
    {
        $term = trim($this->barcodeSearch);
        if ($term === '') {
            return;
        }

        $company = app(CompanyContext::class)->company();

        // 1. Try exact barcode
        $barcode = ProductBarcode::with(['product.productUnits', 'unit'])
            ->whereHas('product', fn ($q) => $q->where('company_id', $company->id)->where('active', true))
            ->where('barcode', $term)
            ->first();

        $product = null;
        $productUnit = null;

        if ($barcode !== null) {
            $product = $barcode->product;
            $productUnit = $barcode->unit_id
                ? ProductUnit::where('product_id', $product->id)->where('unit_id', $barcode->unit_id)->first()
                : null;
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

        if ($product !== null) {
            // Check if product already exists in lines
            $existingIndex = null;
            foreach ($this->lines as $idx => $line) {
                if ($line['product_id'] === $product->id && ($productUnit === null || $line['product_unit_id'] === $productUnit->id)) {
                    $existingIndex = $idx;
                    break;
                }
            }

            if ($existingIndex !== null) {
                $curQty = BigDecimal::of((string) ($this->lines[$existingIndex]['quantity'] ?: 0));
                $this->lines[$existingIndex]['quantity'] = (string) $curQty->plus(1);
            } else {
                // If first line is empty, replace it
                $targetIndex = (count($this->lines) === 1 && empty($this->lines[0]['product_id']) && empty($this->lines[0]['item_description']))
                    ? 0
                    : count($this->lines);

                $selected = app(SalesProductSelection::class)->select($product->id, $productUnit?->id, $this->currency_code, $this->exchange_rate, $this->warehouse_id);

                $newLine = [
                    'product_id' => $product->id,
                    'product_unit_id' => $productUnit?->id,
                    'item_description' => $product->displayName(),
                    'quantity' => '1',
                    'unit_price' => $selected['unit_price'],
                    'discount_type' => 'none',
                    'discount_value' => '0',
                    'tax_rate_id' => null,
                    'subtotal' => '0.00',
                    'discount_amount' => '0.00',
                    'tax_amount' => '0.00',
                    'line_total' => '0.00',
                ];

                $newLine = array_merge($newLine, $selected);
                if ($targetIndex === 0 && count($this->lines) === 1 && empty($this->lines[0]['product_id'])) {
                    $this->lines[0] = $newLine;
                } else {
                    $this->lines[] = $newLine;
                }
            }

            $this->barcodeSearch = '';
            $this->recalculate();
        }
    }

    public function addLine(): void
    {
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
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        $this->recalculate();
    }

    public function selectProduct(int $index, int $productId): void
    {
        $selected = app(SalesProductSelection::class)->select($productId, null, $this->currency_code, $this->exchange_rate, $this->warehouse_id);
        $this->lines[$index] = array_merge($this->lines[$index], $selected);
        $this->recalculate();
    }

    public function changeUnit(int $index, int $productUnitId): void
    {
        $productId = $this->lines[$index]['product_id'] ?? null;
        if ($productId === null) {
            return;
        }
        $selected = app(SalesProductSelection::class)->select($productId, $productUnitId, $this->currency_code, $this->exchange_rate, $this->warehouse_id);
        $this->lines[$index] = array_merge($this->lines[$index], $selected);
        $this->recalculate();
    }

    public function updatedWarehouseId(): void
    {
        foreach ($this->lines as $idx => $line) {
            if (! empty($line['product_id'])) {
                $selected = app(SalesProductSelection::class)->select(
                    (int) $line['product_id'], ! empty($line['product_unit_id']) ? (int) $line['product_unit_id'] : null,
                    $this->currency_code, $this->exchange_rate, $this->warehouse_id);
                $this->lines[$idx]['available_quantity'] = $selected['available_quantity'];
            }
        }
    }

    public function recalculate(): void
    {
        $company = app(CompanyContext::class)->company();
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

    public function save(
        bool $andPost,
        CreateSalesInvoiceDraftAction $createAction,
        UpdateSalesInvoiceDraftAction $updateAction,
        PostSalesInvoiceAction $postAction
    ): mixed {
        $company = app(CompanyContext::class)->company();
        $user = auth()->user();

        if ($this->isEditing) {
            if (! $user->hasPermissionTo('sales.invoice.edit_draft')) {
                abort(403, 'Unauthorized.');
            }
        } else {
            if (! $user->hasPermissionTo('sales.invoice.create')) {
                abort(403, 'Unauthorized.');
            }
        }

        if ($andPost && ! $user->hasPermissionTo('sales.invoice.post')) {
            abort(403, 'Unauthorized to post invoices.');
        }

        $this->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'currency_code' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
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
            'warehouse_id' => $this->warehouse_id ? (int) $this->warehouse_id : null,
            'currency_code' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate,
            'issue_date' => $this->issue_date,
            'due_date' => $this->due_date,
            'notes' => $this->notes,
            'terms' => $this->terms,
            'document_locale' => $this->document_locale,
            'lines' => $dtoLines,
        ];

        if ($this->isEditing && $this->invoice !== null) {
            $invoice = $updateAction->execute($this->invoice, $user, $payload);
        } else {
            $invoice = $createAction->execute($company, $user, $payload);
        }

        if ($andPost) {
            $invoice = $postAction->execute($invoice, $user);
            session()->flash('success', __('sales.posted_successfully'));
        } else {
            session()->flash('success', __('sales.created_successfully'));
        }

        return redirect()->route('invoices.show', $invoice->public_id);
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();
        $user = auth()->user();

        $canChangePrice = $user->hasPermissionTo('sales.invoice.change_price');
        $canChangeDiscount = $user->hasPermissionTo('sales.invoice.change_discount');
        $canPost = $user->hasPermissionTo('sales.invoice.post');

        $customers = Customer::where('company_id', $company->id)->where('status', 'active')->orderBy('name_ar')->get();
        $currencies = $company->currencies()->where('enabled', true)->get();
        $warehouses = Warehouse::where('company_id', $company->id)->where('active', true)->get();
        $products = Product::with(['productUnits.unit', 'baseUnit'])->where('company_id', $company->id)->where('active', true)->orderBy('name_ar')->get();
        $taxes = TaxRate::where('company_id', $company->id)->where('active', true)->get();
        $selectedCustomer = $customers->firstWhere('id', $this->customer_id);
        $creditLimitWarning = false;
        if ($selectedCustomer !== null && preg_match('/\A\d+(\.\d+)?\z/', $this->exchange_rate)) {
            $projectedBase = BigDecimal::of($this->previewGrandTotal)->multipliedBy($this->exchange_rate);
            $creditLimitWarning = app(CustomerCreditLimitQuery::class)->exceeds($selectedCustomer, $projectedBase);
        }

        return view('livewire.pages.sales.invoice-form', [
            'customers' => $customers,
            'currencies' => $currencies,
            'warehouses' => $warehouses,
            'products' => $products,
            'taxes' => $taxes,
            'canChangePrice' => $canChangePrice,
            'canChangeDiscount' => $canChangeDiscount,
            'canPost' => $canPost,
            'creditLimitWarning' => $creditLimitWarning,
        ]);
    }
}
