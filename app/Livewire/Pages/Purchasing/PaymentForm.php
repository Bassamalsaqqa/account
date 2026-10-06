<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Currency;
use App\Models\MoneyAccount;
use App\Models\Purchase;
use App\Models\Vendor;
use App\Services\Purchasing\PurchasePayablePosition;
use App\Services\Purchasing\VendorPaymentPreview;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PaymentForm extends Component
{
    use AuthorizesPurchasingPages;

    public ?int $vendor_id = null;

    public ?int $money_account_id = null;

    public string $payment_method = 'cash';

    public string $payment_date = '';

    public string $document_locale = 'ar';

    public string $amount = '0.00';

    public string $currency_code = 'ILS';

    public string $exchange_rate = '1.0000000000';

    public ?string $reference_number = null;

    public ?string $notes = null;

    /**
     * @var list<array{
     *     purchase_id: int,
     *     purchase_number: string,
     *     purchase_date: string,
     *     due_date: ?string,
     *     grand_total: string,
     *     outstanding: string,
     *     allocated_amount: string,
     *     purchase_exchange_rate: string,
     *     preview_fx: string,
     * }>
     */
    public array $allocations = [];

    public string $allocatedTotal = '0.00';

    public string $unallocatedAmount = '0.00';

    public string $idempotency_key = '';

    public function boot(CompanyContext $context): void
    {
        if (isset($this->pageCompanyId)) {
            if (! $context->hasCompany() || (int) $context->companyId() !== $this->pageCompanyId) {
                abort(403);
            }
            $this->authorizePurchasing('purchasing.cost.view');
            $this->authorizePurchasing('money.vendor_payment.create');
        }
    }

    public function mount(CompanyContext $context, ?int $vendor_id = null, ?int $purchase_id = null): void
    {
        $this->pageCompanyId = (int) $context->companyId();
        $this->authorizePurchasing('purchasing.cost.view');
        $this->authorizePurchasing('money.vendor_payment.create');

        $vendor_id ??= request()->integer('vendor_id') ?: null;
        $purchase_id ??= request()->integer('purchase_id') ?: null;

        $this->idempotency_key = (string) Str::ulid();
        $company = $context->company();

        $this->vendor_id = $vendor_id;
        $this->document_locale = $company->default_locale;
        $this->payment_date = Carbon::now($company->timezone)->toDateString();

        $defaultAccount = MoneyAccount::where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->first();

        if ($defaultAccount !== null) {
            $this->money_account_id = $defaultAccount->id;
            $this->currency_code = $defaultAccount->currency_code;
            $this->payment_method = $defaultAccount->account_type === MoneyAccount::TYPE_BANK ? 'bank_transfer' : 'cash';
            if ($this->currency_code === $company->base_currency_code) {
                $this->exchange_rate = '1.0000000000';
            }
        }

        if ($purchase_id !== null) {
            $purchase = Purchase::where('company_id', $company->id)
                ->where('status', Purchase::STATUS_POSTED)
                ->find($purchase_id);

            if ($purchase !== null) {
                $position = $purchase->payablePosition();
                if ($position->hasOutstanding()) {
                    $this->vendor_id = $purchase->vendor_id;
                    $matchingAccount = MoneyAccount::where('company_id', $company->id)
                        ->where('currency_code', $purchase->currency_code)
                        ->where('is_active', true)
                        ->first();

                    $this->money_account_id = $matchingAccount?->id;
                    $this->currency_code = $purchase->currency_code;
                    if ($matchingAccount !== null) {
                        $this->money_account_id = $matchingAccount->id;
                        $this->currency_code = $matchingAccount->currency_code;
                        $this->payment_method = $matchingAccount->account_type === MoneyAccount::TYPE_BANK ? 'bank_transfer' : 'cash';
                        if ($this->currency_code === $company->base_currency_code) {
                            $this->exchange_rate = '1.0000000000';
                        }
                    }
                    $this->amount = (string) $position->outstanding;
                }
            }
        }

        $this->refreshDocumentLocale();
        $this->loadOpenPurchases();

        // If purchase_id was given and pre-allocated, set it
        if ($purchase_id !== null) {
            foreach ($this->allocations as $idx => $alloc) {
                if ($alloc['purchase_id'] === $purchase_id) {
                    $this->allocations[$idx]['allocated_amount'] = $alloc['outstanding'];
                    break;
                }
            }
            $this->recalculateAllocations();
        }
    }

    public function updatedVendorId(): void
    {
        $this->refreshDocumentLocale();
        $this->loadOpenPurchases();
    }

    private function refreshDocumentLocale(): void
    {
        $company = app(CompanyContext::class)->company();
        $vendor = Vendor::withTrashed()->where('company_id', $company->id)->find($this->vendor_id);
        $this->document_locale = $vendor?->preferred_locale !== null && $company->isLanguageEnabled($vendor->preferred_locale)
            ? $vendor->preferred_locale : $company->default_locale;
    }

    public function updatedMoneyAccountId(): void
    {
        if ($this->money_account_id !== null) {
            $account = MoneyAccount::find($this->money_account_id);
            if ($account !== null) {
                $this->currency_code = $account->currency_code;
                $this->payment_method = $account->account_type === MoneyAccount::TYPE_BANK ? 'bank_transfer' : 'cash';
                $company = app(CompanyContext::class)->company();
                if ($this->currency_code === $company->base_currency_code) {
                    $this->exchange_rate = '1.0000000000';
                }
            }
        }
        $this->loadOpenPurchases();
    }

    public function updatedAmount(): void
    {
        $this->recalculateAllocations();
    }

    public function updatedExchangeRate(): void
    {
        $this->recalculateAllocations();
    }

    public function loadOpenPurchases(): void
    {
        if ($this->vendor_id === null || $this->money_account_id === null) {
            $this->allocations = [];
            $this->recalculateAllocations();

            return;
        }

        $company = app(CompanyContext::class)->company();

        $purchases = Purchase::with(['returns', 'paymentAllocations.vendorPayment'])
            ->where('company_id', $company->id)
            ->where('vendor_id', $this->vendor_id)
            ->where('currency_code', $this->currency_code)
            ->where('status', Purchase::STATUS_POSTED)
            ->orderByRaw('CASE WHEN due_date IS NOT NULL THEN 0 ELSE 1 END, due_date ASC, purchase_date ASC, id ASC')
            ->get();

        $positions = PurchasePayablePosition::forPurchases($purchases);
        $this->allocations = [];

        foreach ($purchases as $purchase) {
            $position = $positions[$purchase->id] ?? null;
            if ($position !== null && $position->hasOutstanding()) {
                $this->allocations[] = [
                    'purchase_id' => $purchase->id,
                    'purchase_number' => $purchase->purchase_number ?? (string) $purchase->id,
                    'purchase_date' => $purchase->purchase_date->toDateString(),
                    'due_date' => $purchase->due_date?->toDateString(),
                    'grand_total' => (string) $purchase->grand_total_currency,
                    'outstanding' => (string) $position->outstanding,
                    'allocated_amount' => '0.00',
                    'purchase_exchange_rate' => (string) $purchase->exchange_rate,
                    'preview_fx' => '0.00',
                ];
            }
        }

        $this->recalculateAllocations();
    }

    public function autoAllocate(): void
    {
        $remaining = BigDecimal::of($this->amount !== '' ? $this->amount : '0');

        foreach ($this->allocations as $idx => $alloc) {
            $due = BigDecimal::of($alloc['outstanding']);
            if ($remaining->isPositive()) {
                $allocAmount = $remaining->isGreaterThanOrEqualTo($due) ? $due : $remaining;
                $this->allocations[$idx]['allocated_amount'] = (string) $allocAmount;
                $remaining = $remaining->minus($allocAmount);
            } else {
                $this->allocations[$idx]['allocated_amount'] = '0.00';
            }
        }

        $this->recalculateAllocations();
    }

    public function recalculateAllocations(): void
    {
        $preview = app(VendorPaymentPreview::class)->calculate(
            $this->amount,
            $this->exchange_rate,
            $this->allocations,
            $this->currency_code
        );

        $this->allocations = $preview['allocations'];
        $this->allocatedTotal = $preview['allocated'];
        $this->unallocatedAmount = $preview['unallocated'];
    }

    public function save(PostVendorPaymentAction $postAction, CompanyContext $context): mixed
    {
        $this->pageCompanyId = $context->companyId();
        $company = $this->authorizePurchasing('purchasing.cost.view');
        $this->authorizePurchasing('money.vendor_payment.create');
        $user = auth()->user();

        $this->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'money_account_id' => ['required', 'integer', 'exists:money_accounts,id'],
            'payment_method' => ['required', 'string', 'in:cash,bank_transfer'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'document_locale' => ['required', 'string', 'in:ar,en'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $pmtAmount = BigDecimal::of($this->amount);
        $allocList = [];
        $allocatedSum = BigDecimal::zero();

        foreach ($this->allocations as $alloc) {
            $amt = BigDecimal::of($alloc['allocated_amount'] !== '' ? $alloc['allocated_amount'] : '0');
            if ($amt->isPositive()) {
                $maxDue = BigDecimal::of($alloc['outstanding']);
                if ($amt->isGreaterThan($maxDue)) {
                    $this->addError('allocations', __('purchasing.application_exceeds_available'));

                    return null;
                }

                $allocatedSum = $allocatedSum->plus($amt);
                $allocList[] = [
                    'purchase_id' => (int) $alloc['purchase_id'],
                    'allocated_amount' => (string) $amt,
                ];
            }
        }

        if ($allocatedSum->isGreaterThan($pmtAmount)) {
            $this->addError('amount', __('purchasing.application_exceeds_available'));

            return null;
        }

        $payload = [
            'vendor_id' => (int) $this->vendor_id,
            'money_account_id' => (int) $this->money_account_id,
            'payment_date' => $this->payment_date,
            'payment_method' => $this->payment_method,
            'amount' => (string) $pmtAmount,
            'exchange_rate' => $this->exchange_rate,
            'document_locale' => $this->document_locale,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
            'idempotency_key' => $this->idempotency_key,
            'allocations' => $allocList,
        ];

        try {
            $payment = $postAction->execute($company, $user, $payload);
        } catch (\InvalidArgumentException $e) {
            $message = __('purchasing.payment_request_invalid');
            if (preg_match('/Payment amount precision exceeds (\d+) decimals\./', $e->getMessage(), $m)) {
                $message = __('purchasing.payment_precision_exceeded', ['decimals' => $m[1]]);
            }
            $this->addError('amount', $message);

            return null;
        }

        session()->flash('success', __('purchasing.payment_posted_successfully'));

        return redirect()->route('vendor-payments.show', $payment->public_id);
    }

    public function render(CompanyContext $context): View
    {
        $this->authorizePurchasing('purchasing.cost.view');
        $this->authorizePurchasing('money.vendor_payment.create');

        $company = $context->company();
        $vendors = Vendor::withTrashed()->where('company_id', $this->pageCompanyId)
            ->where(function ($query): void {
                $query->where(fn ($active) => $active->where('status', 'active')->whereNull('deleted_at'))
                    ->orWhereHas('purchases', fn ($purchase) => $purchase->where('status', Purchase::STATUS_POSTED));
            })->orderBy('name_ar')->get();
        $accounts = MoneyAccount::where('company_id', $this->pageCompanyId)->where('is_active', true)->orderBy('sort_order')->get();

        $minorUnits = (int) Currency::findOrFail($this->currency_code)->getAttribute('minor_units');
        $paymentAmountMinimum = (string) BigDecimal::one()->dividedBy(BigDecimal::of(10)->power($minorUnits), $minorUnits);

        return view('livewire.pages.purchasing.payment-form', [
            'company' => $company,
            'vendors' => $vendors,
            'accounts' => $accounts,
            'paymentAmountMinimum' => $paymentAmountMinimum,
        ]);
    }
}
