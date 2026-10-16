<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Application\AI\AiDraftProvenance;
use App\Application\Commands\SalesInvoiceCommands;
use App\Application\Reporting\Security\ReportingGuard;
use App\Domain\Inventory\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Domain\Money\Exceptions\InvalidMoneyException;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use App\Domain\Sales\Queries\CustomerCreditLimitQuery;
use App\Domain\Sales\Queries\CustomerLastPriceQuery;
use App\Exceptions\NoActiveCompanyException;
use App\Livewire\Concerns\AdjustsDocumentQuantity;
use App\Livewire\Concerns\AiUnsavedForm;
use App\Livewire\Concerns\CameraBarcodeScan;
use App\Livewire\Concerns\QuickPartyCreate;
use App\Livewire\Concerns\QuickProductCreate;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesProductSelection;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[Layout('layouts.app')]
class InvoiceForm extends Component
{
    use AdjustsDocumentQuantity;
    use AiUnsavedForm;
    use CameraBarcodeScan;
    use QuickPartyCreate;
    use QuickProductCreate;

    protected function quickProductFormPermission(): string
    {
        return $this->quickProductDocumentId === null ? 'sales.invoice.create' : 'sales.invoice.edit_draft';
    }

    protected function assertQuickProductDraft(): void
    {
        if ($this->quickProductDocumentId !== null) {
            $document = SalesInvoice::where('company_id', $this->pageCompanyId)->findOrFail($this->quickProductDocumentId);
            abort_unless($document->isDraft(), 403);
        }
    }

    protected function quickPartyKind(): string
    {
        return 'customer';
    }

    protected function quickPartyFormPermission(): string
    {
        return $this->isEditing ? 'sales.invoice.edit_draft' : 'sales.invoice.create';
    }

    protected function authorizeQuickPartyForm(): Company
    {
        return DB::transaction(fn () => app(SalesActorGuard::class)->lockAndAuthorize($this->pageCompanyId, auth()->user(), $this->quickPartyFormPermission()));
    }

    protected function selectQuickParty(int $id): void
    {
        $this->customer_id = $id;
        $this->updatedCustomerId();
    }

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

    public string $customerSearch = '';

    public string $productSearch = '';

    #[Locked]
    public int $pageCompanyId;

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
     *     available_quantity?: string,
     * }>
     */
    public array $lines = [];

    // Preview totals
    public string $previewSubtotal = '0.00';

    public string $previewDiscountTotal = '0.00';

    public string $previewTaxTotal = '0.00';

    public string $previewGrandTotal = '0.00';

    // Phase 11 Command Parity & State Guarding
    #[Locked]
    public ?string $createRequestKey = null;

    #[Locked]
    public ?string $updateRequestKey = null;

    #[Locked]
    public ?string $lastSavedPayloadDigest = null;

    #[Locked]
    public ?string $confirmationToken = null;

    #[Locked]
    public ?string $executeKey = null;

    #[Locked]
    public ?string $previewDigest = null;

    public bool $showPreview = false;

    /**
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $previewData = null;

    public ?string $previewError = null;

    public function mount(CompanyContext $context, ?string $publicId = null, ?int $customer_id = null): void
    {
        $customer_id ??= request()->integer('customer_id') ?: null;
        $company = $context->company();
        $this->pageCompanyId = (int) $company->id;
        $user = auth()->user();

        $this->createRequestKey = (string) Str::uuid();
        $this->updateRequestKey = (string) Str::uuid();

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

            $this->quickProductDocumentId = (int) $this->invoice->id;
            $this->isEditing = true;
            $this->customer_id = $this->invoice->customer_id;
            $this->warehouse_id = $this->invoice->warehouse_id;
            $this->currency_code = $this->invoice->currency_code;
            $this->exchange_rate = (string) $this->invoice->exchange_rate;
            $this->issue_date = $this->invoice->issue_date->toDateString();
            $this->due_date = $this->invoice->due_date ? $this->invoice->due_date->toDateString() : Carbon::now($company->timezone)->addDays(30)->toDateString();
            $this->notes = $this->invoice->notes;
            $this->terms = $this->invoice->terms;
            $this->document_locale = $this->invoice->document_locale ?? 'ar';

            $this->lines = [];
            foreach ($this->invoice->lines as $line) {
                $discountType = $line->discount_type ?? 'none';
                if ($discountType === 'percentage') {
                    $discountType = 'percent';
                }
                $this->lines[] = [
                    'id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_unit_id' => $line->product_unit_id,
                    'item_description' => $line->item_description,
                    'quantity' => (string) $line->quantity,
                    'unit_price' => (string) $line->unit_price,
                    'discount_type' => $discountType,
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
            $this->issue_date = Carbon::now($company->timezone)->toDateString();
            $this->due_date = Carbon::now($company->timezone)->addDays(30)->toDateString();
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

        if (! $this->isEditing) {
            $this->mountAiInput('sales');
            if ($this->customer_id !== null) {
                $this->updatedCustomerId();
            }
        }
        $this->updatedWarehouseId();
        $this->recalculate();
    }

    public function updated(?string $property = null): void
    {
        if ($this->showPreview && ! in_array($property, ['showPreview', 'confirmationToken', 'executeKey', 'previewDigest', 'previewData', 'previewError', 'productSearch'], true)) {
            $this->cancelPreview();
        }
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

    public function saveDraft(): mixed
    {
        if ($this->showPreview || $this->showQuickCustomerModal || $this->showQuickProductModal) {
            return null;
        }

        return $this->save(false, app(SalesInvoiceCommands::class));
    }

    public function scanBarcode(): void
    {
        $context = app(CompanyContext::class);
        abort_unless(auth()->check() && $context->hasCompany() && (int) $context->companyId() === (int) $this->pageCompanyId, 403);
        $user = auth()->user();
        abort_unless($user->hasPermissionTo($this->isEditing ? 'sales.invoice.edit_draft' : 'sales.invoice.create'), 403);

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

        if ($this->showPreview) {
            $this->cancelPreview();
        }

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
                $this->warehouse_id
            );
        } catch (\InvalidArgumentException|ModelNotFoundException|InvalidMoneyException|InvalidQuantityException|InvalidUnitConversionException $e) {
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
        if ($this->showPreview) {
            $this->cancelPreview();
        }

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
        if ($this->showPreview) {
            $this->cancelPreview();
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        $this->recalculate();
    }

    public function selectProduct(int $index, int $productId): bool
    {
        if ($this->showPreview) {
            $this->cancelPreview();
        }

        $selected = app(SalesProductSelection::class)->select($productId, null, $this->currency_code, $this->exchange_rate, $this->warehouse_id);
        $this->lines[$index] = array_merge($this->lines[$index], $selected);
        $this->recalculate();

        return true;
    }

    public function changeUnit(int $index, int $productUnitId): void
    {
        if ($this->showPreview) {
            $this->cancelPreview();
        }

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
        if ($this->showPreview) {
            $this->cancelPreview();
        }

        foreach ($this->lines as $idx => $line) {
            if (! empty($line['product_id'])) {
                $selected = app(SalesProductSelection::class)->select(
                    (int) $line['product_id'], ! empty($line['product_unit_id']) ? (int) $line['product_unit_id'] : null,
                    $this->currency_code, $this->exchange_rate, $this->warehouse_id);
                $this->lines[$idx]['available_quantity'] = $selected['available_quantity'];
            }
        }
    }

    #[Locked]
    public bool $documentTotalsValid = false;

    public function recalculate(): void
    {
        $this->documentTotalsValid = false;
        if ($this->showPreview) {
            $this->cancelPreview();
        }

        $company = app(CompanyContext::class)->company();
        foreach ($this->lines as $index => $line) {
            foreach (['quantity', 'unit_price', 'discount_value'] as $field) {
                if (! is_string($line[$field]) || preg_match('/^\d{1,14}(?:\.\d{1,6})?$/D', $line[$field]) !== 1) {
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

            $discountType = $line['discount_type'];
            if ($discountType === 'percentage') {
                $discountType = 'percent';
            }

            $input = new SalesLineCalculationInput(
                quantity: $line['quantity'],
                unitPrice: $line['unit_price'],
                discountType: $discountType !== 'none' ? $discountType : null,
                discountValue: $line['discount_value'],
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
        $this->documentTotalsValid = true;
    }

    public function save(bool $andPost, SalesInvoiceCommands $commands): mixed
    {
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

        // Strict exact-string validation for economic values to reject raw floats/scientific notation
        foreach ($this->lines as $idx => $line) {
            if (! is_string($line['quantity'])) {
                $this->addError("lines.{$idx}.quantity", __('commands.exact_string_required'));

                return null;
            }
            if (! is_string($line['unit_price'])) {
                $this->addError("lines.{$idx}.unit_price", __('commands.exact_string_required'));

                return null;
            }
            if (! is_string($line['discount_value'])) {
                $this->addError("lines.{$idx}.discount_value", __('commands.exact_string_required'));

                return null;
            }
        }

        $this->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('company_id', $company->id)],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $company->id)],
            'currency_code' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['required', 'string', 'regex:/^\d+(\.\d{1,10})?$/'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['bail', 'required', 'string', 'regex:/^\d+(\.\d{1,6})?$/', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ! BigDecimal::of($value)->isPositive()) {
                    $fail(__('commands.code_validation_failed'));
                }
            }],
            'lines.*.unit_price' => ['required', 'string', 'regex:/^\d+(\.\d{1,6})?$/'],
            'lines.*.discount_type' => ['nullable', 'string', 'in:none,fixed,percent'],
            'lines.*.discount_value' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,6})?$/'],
        ]);

        if (BigDecimal::of($this->exchange_rate)->isZero()) {
            $this->addError('exchange_rate', __('commands.code_validation_failed'));

            return null;
        }

        foreach ($this->lines as $idx => $line) {
            if (BigDecimal::of($line['quantity'])->isZero()) {
                $this->addError("lines.{$idx}.quantity", __('commands.code_validation_failed'));

                return null;
            }
        }

        $dtoLines = [];
        foreach ($this->lines as $line) {
            $discountType = $line['discount_type'];
            if ($discountType === 'percentage') {
                $discountType = 'percent';
            }
            $dtoLines[] = [
                'product_id' => ! empty($line['product_id']) ? (int) $line['product_id'] : null,
                'product_unit_id' => ! empty($line['product_unit_id']) ? (int) $line['product_unit_id'] : null,
                'item_description' => $line['item_description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'discount_type' => $discountType !== 'none' ? $discountType : null,
                'discount_value' => $line['discount_value'],
                'tax_rate_id' => ! empty($line['tax_rate_id']) ? (int) $line['tax_rate_id'] : null,
            ];
        }

        $payload = [
            'customer_id' => (int) $this->customer_id,
            'warehouse_id' => $this->warehouse_id ? (int) $this->warehouse_id : null,
            'currency_code' => $this->currency_code,
            'exchange_rate' => $this->exchange_rate,
            'issue_date' => $this->issue_date,
            'due_date' => $this->due_date ?: null,
            'notes' => $this->notes,
            'terms' => $this->terms,
            'document_locale' => $this->document_locale,
            'lines' => $dtoLines,
        ];

        $currentDigest = $this->computePayloadDigest();

        try {
            if ($this->isEditing && $this->invoice !== null) {
                if ($this->lastSavedPayloadDigest !== null && $this->lastSavedPayloadDigest !== $currentDigest) {
                    $this->updateRequestKey = (string) Str::uuid();
                }
                $key = $this->updateRequestKey ?? (string) Str::uuid();
                $this->updateRequestKey = $key;
                $this->lastSavedPayloadDigest = $currentDigest;

                $this->invoice = $commands->update($this->invoice, $user, $payload, $key);
            } else {
                if ($this->lastSavedPayloadDigest !== null && $this->lastSavedPayloadDigest !== $currentDigest) {
                    $this->createRequestKey = (string) Str::uuid();
                }
                $key = $this->createRequestKey ?? (string) Str::uuid();
                $this->createRequestKey = $key;
                $this->lastSavedPayloadDigest = $currentDigest;

                $this->invoice = DB::transaction(function () use ($commands, $company, $user, $payload, $key): SalesInvoice {
                    if ($this->aiFormToken !== null && $this->aiFormKey !== null) {
                        app(AiDraftProvenance::class)->source($company, $user, $this->aiFormToken, session()->getId(), $this->aiFormKey, 'sales', $this->aiFormRevision);
                    }
                    $created = $commands->create($company, $user, $payload, $key);
                    if ($this->aiDraftSourceId !== null) {
                        app(AiDraftProvenance::class)->recordSales($company, $user, $this->aiDraftSourceId, $created, $payload, $key);
                    }

                    return $created;
                });
                $this->discardAiInput();
                $this->isEditing = true;
            }
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException || $e instanceof NoActiveCompanyException || ($e instanceof HttpException && $e->getStatusCode() === 403)) {
                throw $e;
            }
            $this->showPreview = false;
            $this->previewError = $this->resolveUserErrorMessage($e);

            return null;
        }

        if (! $andPost) {
            session()->flash('success', __('sales.created_successfully'));

            return redirect()->route('invoices.show', $this->invoice->public_id);
        }

        // save(true) creates authenticated server preview, displays reviewed panel, posts NO history.
        $this->previewError = null;

        try {
            $preview = $commands->preview($this->invoice, $user);
            $this->previewData = $preview;
            $this->confirmationToken = $preview['confirmation_token'] ?? null;
            $this->executeKey = (string) Str::uuid();
            $this->previewDigest = $this->computePayloadDigest();
            $this->showPreview = true;
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException || $e instanceof NoActiveCompanyException || ($e instanceof HttpException && $e->getStatusCode() === 403)) {
                throw $e;
            }
            $this->showPreview = false;
            $this->previewError = $this->resolveUserErrorMessage($e);
        }

        return null;
    }

    public function confirmPost(SalesInvoiceCommands $commands): mixed
    {
        $user = auth()->user();
        if (! $user || ! $user->hasPermissionTo('sales.invoice.post')) {
            abort(403, 'Unauthorized to post invoices.');
        }

        if (! $this->showPreview || ! $this->confirmationToken || ! $this->invoice) {
            $this->previewError = __('commands.preview_expired');

            return null;
        }

        // Reject stale/edited form
        if ($this->previewDigest === null || $this->previewDigest !== $this->computePayloadDigest()) {
            $this->cancelPreview();
            $this->previewError = __('commands.form_changed_repreview_required');

            return null;
        }

        // Block execution if blockers exist or can_execute is false
        if (! empty($this->previewData['blockers']) || ($this->previewData['can_execute'] ?? true) === false) {
            $this->previewError = __('commands.cannot_confirm_blockers');

            return null;
        }

        try {
            $invoice = $commands->execute($this->invoice, $user, $this->confirmationToken, $this->executeKey);
            $this->cancelPreview();
            session()->flash('success', __('sales.posted_successfully'));

            return redirect()->route('invoices.show', $invoice->public_id);
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException || $e instanceof NoActiveCompanyException || ($e instanceof HttpException && $e->getStatusCode() === 403)) {
                throw $e;
            }
            $this->cancelPreview();
            $this->previewError = $this->resolveUserErrorMessage($e);

            return null;
        }
    }

    public function cancelPreview(): void
    {
        $this->showPreview = false;
        $this->previewData = null;
        $this->confirmationToken = null;
        $this->executeKey = null;
        $this->previewDigest = null;
    }

    public function computePayloadDigest(): string
    {
        $normalizedLines = [];
        foreach ($this->lines as $line) {
            $discountType = $line['discount_type'];
            if ($discountType === 'percentage') {
                $discountType = 'percent';
            }
            $normalizedLines[] = [
                'product_id' => ! empty($line['product_id']) ? (int) $line['product_id'] : null,
                'product_unit_id' => ! empty($line['product_unit_id']) ? (int) $line['product_unit_id'] : null,
                'item_description' => trim($line['item_description']),
                'quantity' => trim($line['quantity']),
                'unit_price' => trim($line['unit_price']),
                'discount_type' => $discountType !== 'none' ? $discountType : null,
                'discount_value' => trim($line['discount_value']),
                'tax_rate_id' => ! empty($line['tax_rate_id']) ? (int) $line['tax_rate_id'] : null,
            ];
        }

        $normalized = [
            'customer_id' => (int) $this->customer_id,
            'warehouse_id' => $this->warehouse_id ? (int) $this->warehouse_id : null,
            'currency_code' => strtoupper(trim($this->currency_code)),
            'exchange_rate' => trim($this->exchange_rate),
            'issue_date' => trim($this->issue_date),
            'due_date' => $this->due_date ? trim($this->due_date) : null,
            'notes' => $this->notes !== null ? trim($this->notes) : null,
            'terms' => $this->terms !== null ? trim($this->terms) : null,
            'document_locale' => $this->document_locale,
            'lines' => $normalizedLines,
        ];

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function resolveUserErrorMessage(\Throwable $e): string
    {
        if ($e instanceof AuthorizationException
            || $e instanceof NoActiveCompanyException
            || ($e instanceof HttpException && $e->getStatusCode() === 403)) {
            throw $e;
        }

        if ($e instanceof \InvalidArgumentException) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'token') || str_contains($msg, 'expired') || str_contains($msg, 'stale')) {
                return __('commands.preview_expired');
            }
            if (str_contains($msg, 'warehouse')) {
                return __('commands.code_warehouse_inactive');
            }
            if (str_contains($msg, 'customer')) {
                return __('commands.code_customer_inactive');
            }
            if (str_contains($msg, 'stock') || str_contains($msg, 'insufficient')) {
                return __('commands.code_insufficient_stock');
            }

            return __('commands.code_validation_failed');
        }

        if ($e instanceof ConflictHttpException
            || $e->getCode() === 409
            || ($e instanceof HttpException && $e->getStatusCode() === 409)) {
            return __('commands.conflict_error');
        }

        return __('commands.generic_error');
    }

    public function render(CompanyContext $context): View
    {
        abort_unless(auth()->check() && $context->hasCompany() && $context->companyId() === $this->pageCompanyId, 403);
        $user = auth()->user();
        $company = DB::transaction(fn () => app(SalesActorGuard::class)->lockAndAuthorize(
            $this->pageCompanyId, $user, $this->isEditing ? 'sales.invoice.edit_draft' : 'sales.invoice.create'
        ));

        $canChangePrice = $user->hasPermissionTo('sales.invoice.change_price');
        $canChangeDiscount = $user->hasPermissionTo('sales.invoice.change_discount');
        $canPost = $user->hasPermissionTo('sales.invoice.post');
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
        $warehouses = Warehouse::where('company_id', $company->id)->where('active', true)->get();
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
        $matchingProducts = $productsQuery->orderBy('name_ar')->limit(50)->get();
        $products = $matchingProducts->merge($selectedProducts);
        $lastCustomerPrices = $this->customer_id !== null && count($selectedProductIds) <= 100
            && app(ReportingGuard::class)->allows($company, ['reports.sales.view', 'sales.invoice.view'], $user)
            ? app(CustomerLastPriceQuery::class)->execute($company, $user, $this->customer_id, array_map('intval', $selectedProductIds)) : [];
        $taxes = TaxRate::where('company_id', $company->id)->where('active', true)->get();
        $selectedCustomer = $customers->firstWhere('id', $this->customer_id);
        $creditLimitWarning = false;
        if ($selectedCustomer !== null && preg_match('/\A\d+(\.\d+)?\z/', $this->exchange_rate)) {
            $projectedBase = BigDecimal::of($this->previewGrandTotal)->multipliedBy($this->exchange_rate);
            $creditLimitWarning = app(CustomerCreditLimitQuery::class)->exceeds($selectedCustomer, $projectedBase);
        }

        return view('livewire.pages.sales.invoice-form', [
            'baseCurrencyCode' => $company->base_currency_code,
            'customers' => $customers,
            'currencies' => $currencies,
            'warehouses' => $warehouses,
            'products' => $products,
            'productMatchCount' => $matchingProducts->count(),
            'taxes' => $taxes,
            'canChangePrice' => $canChangePrice,
            'canChangeDiscount' => $canChangeDiscount,
            'canPost' => $canPost,
            'canManageCustomers' => $user->hasPermissionTo('customers.manage'),
            'lastCustomerPrices' => $lastCustomerPrices,
            'creditLimitWarning' => $creditLimitWarning,
        ]);
    }
}
