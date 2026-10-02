<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Actions\Sales\CreateQuotationAction;
use App\Actions\Sales\UpdateQuotationAction;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\TaxRate;
use App\Services\Sales\SalesProductSelection;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class QuotationForm extends Component
{
    public ?Quotation $quotation = null;

    public bool $isEditing = false;

    public ?int $customer_id = null;

    public string $currency_code = 'ILS';

    public string $exchange_rate = '1.0000000000';

    public string $issue_date = '';

    public ?string $expiry_date = null;

    public ?string $notes = null;

    public ?string $terms = null;

    public string $document_locale = 'ar';

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
        $user = auth()->user();

        if ($publicId !== null) {
            if (! $user->hasPermissionTo('sales.quote.edit')) {
                abort(403, 'Unauthorized.');
            }

            $this->quotation = Quotation::with('lines')
                ->where('company_id', $company->id)
                ->where('public_id', $publicId)
                ->firstOrFail();

            if (! in_array($this->quotation->status, [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT], true)) {
                abort(400, 'Cannot edit quotation in status: '.$this->quotation->status);
            }

            $this->isEditing = true;
            $this->customer_id = $this->quotation->customer_id;
            $this->currency_code = $this->quotation->currency_code;
            $this->exchange_rate = (string) $this->quotation->exchange_rate;
            $this->issue_date = $this->quotation->issue_date->toDateString();
            $this->expiry_date = $this->quotation->expiry_date ? $this->quotation->expiry_date->toDateString() : null;
            $this->notes = $this->quotation->notes;
            $this->terms = $this->quotation->terms;
            $this->document_locale = $this->quotation->document_locale ?? 'ar';

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
            $this->issue_date = Carbon::now()->toDateString();
            $this->document_locale = $company->default_locale ?? 'ar';

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
        $selected = app(SalesProductSelection::class)->select($productId, null, $this->currency_code, $this->exchange_rate, null);
        $this->lines[$index] = array_merge($this->lines[$index], $selected);
        $this->recalculate();
    }

    public function changeUnit(int $index, int $productUnitId): void
    {
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
        $company = app(CompanyContext::class)->company();
        $user = auth()->user();

        if ($this->isEditing) {
            if (! $user->hasPermissionTo('sales.quote.edit')) {
                abort(403, 'Unauthorized.');
            }
        } else {
            if (! $user->hasPermissionTo('sales.quote.create')) {
                abort(403, 'Unauthorized.');
            }
        }

        $this->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
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

    public function render(CompanyContext $context): View
    {
        $company = $context->company();
        $customers = Customer::where('company_id', $company->id)->where('status', 'active')->orderBy('name_ar')->get();
        $currencies = $company->currencies()->where('enabled', true)->get();
        $products = Product::with(['productUnits.unit', 'baseUnit'])->where('company_id', $company->id)->where('active', true)->orderBy('name_ar')->get();
        $taxes = TaxRate::where('company_id', $company->id)->where('active', true)->get();

        return view('livewire.pages.sales.quotation-form', [
            'customers' => $customers,
            'currencies' => $currencies,
            'products' => $products,
            'taxes' => $taxes,
        ]);
    }
}
