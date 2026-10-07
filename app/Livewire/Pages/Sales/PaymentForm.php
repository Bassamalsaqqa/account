<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Actions\Sales\PostCustomerPaymentAction;
use App\Models\CompanyCurrency;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Services\Money\EligibleMoneyAccounts;
use App\Services\Money\MoneyActorGuard;
use App\Services\Sales\ReceiptPreview;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class PaymentForm extends Component
{
    public ?int $customer_id = null;

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
     *     sales_invoice_id: int,
     *     invoice_number: string,
     *     invoice_date: string,
     *     grand_total: string,
     *     outstanding: string,
     *     allocated_amount: string,
     *     document_currency_code: string,
     *     payment_currency_amount?: string,
     *     invoice_exchange_rate: string,
     *     preview_fx: string,
     * }>
     */
    public array $allocations = [];

    public string $allocatedTotal = '0.00';

    public string $unallocatedAmount = '0.00';

    public string $idempotency_key = '';

    #[Locked]
    public int $pageCompanyId;

    public function boot(CompanyContext $context): void
    {
        if (isset($this->pageCompanyId)) {
            app(MoneyActorGuard::class)->authorize($this->pageCompanyId, 'money.receipt.create');
        }
    }

    public function mount(CompanyContext $context, ?int $customer_id = null, ?int $invoice_id = null): void
    {
        $customer_id ??= request()->integer('customer_id') ?: null;
        $invoice_id ??= request()->integer('invoice_id') ?: null;
        $this->idempotency_key = (string) Str::ulid();
        $company = $context->company();
        $this->pageCompanyId = (int) $company->id;
        $user = auth()->user();

        if (! $user->hasPermissionTo('money.receipt.create')) {
            abort(403, 'Unauthorized.');
        }

        $this->customer_id = $customer_id;
        $this->document_locale = $company->default_locale;
        $this->payment_date = Carbon::now($company->timezone)->toDateString();

        $defaultAccount = app(EligibleMoneyAccounts::class)->query((int) $company->id)
            ->orderBy('sort_order')
            ->first();

        if ($defaultAccount !== null) {
            $this->money_account_id = $defaultAccount->id;
            $this->currency_code = $defaultAccount->currency_code;
            $this->payment_method = $defaultAccount->account_type === 'bank' ? 'bank_transfer' : 'cash';
        }

        if ($invoice_id !== null) {
            $inv = SalesInvoice::where('company_id', $company->id)->find($invoice_id);
            if ($inv !== null) {
                $this->customer_id = $inv->customer_id;
                $accWithCurr = app(EligibleMoneyAccounts::class)->query((int) $company->id)
                    ->where('currency_code', $inv->currency_code)
                    ->where('is_active', true)
                    ->first();
                if ($accWithCurr !== null) {
                    $this->money_account_id = $accWithCurr->id;
                    $this->currency_code = $accWithCurr->currency_code;
                    $this->payment_method = $accWithCurr->account_type === 'bank' ? 'bank_transfer' : 'cash';
                }
                $this->amount = (string) $inv->calculateOutstanding();
            }
        }

        $this->loadOpenInvoices();
    }

    public function updatedCustomerId(): void
    {
        $this->loadOpenInvoices();
    }

    public function updatedMoneyAccountId(): void
    {
        if ($this->money_account_id !== null) {
            $account = app(EligibleMoneyAccounts::class)->query((int) app(CompanyContext::class)->companyId())->find($this->money_account_id);
            if ($account !== null) {
                $this->currency_code = $account->currency_code;
                $this->payment_method = $account->account_type === 'bank' ? 'bank_transfer' : 'cash';
                $company = app(CompanyContext::class)->company();
                $this->exchange_rate = $this->currency_code === $company->base_currency_code ? '1.0000000000' : '';
            }
        }
        $this->loadOpenInvoices();
    }

    public function updatedAmount(): void
    {
        $this->recalculateAllocations();
    }

    public function loadOpenInvoices(): void
    {
        if ($this->customer_id === null || $this->money_account_id === null) {
            $this->allocations = [];
            $this->recalculateAllocations();

            return;
        }

        $company = app(CompanyContext::class)->company();

        $invoices = SalesInvoice::with('allocations')
            ->where('company_id', $company->id)
            ->where('customer_id', $this->customer_id)
            ->whereIn('currency_code', CompanyCurrency::where('company_id', $company->id)->where('enabled', true)->select('currency_code'))
            ->where('status', SalesInvoice::STATUS_POSTED)
            ->orderBy('issue_date')
            ->get();

        $this->allocations = [];

        foreach ($invoices as $inv) {
            $outstanding = $inv->calculateOutstanding();
            if ($outstanding->isPositive()) {
                $this->allocations[] = [
                    'sales_invoice_id' => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'invoice_date' => $inv->issue_date->toDateString(),
                    'grand_total' => (string) $inv->grand_total,
                    'outstanding' => (string) $outstanding,
                    'allocated_amount' => '0.00',
                    'document_currency_code' => $inv->currency_code,
                    'invoice_exchange_rate' => (string) $inv->exchange_rate,
                    'preview_fx' => '0.00',
                ];
            }
        }

        $this->recalculateAllocations();
    }

    public function autoAllocate(): void
    {
        $remaining = BigDecimal::of($this->amount ?: '0');

        foreach ($this->allocations as $idx => $alloc) {
            if ($alloc['document_currency_code'] !== $this->currency_code) {
                continue;
            }
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
        $preview = app(ReceiptPreview::class)->calculate($this->amount, $this->exchange_rate, $this->allocations, $this->currency_code);
        $this->allocations = $preview['allocations'];
        $this->allocatedTotal = $preview['allocated'];
        $this->unallocatedAmount = $preview['unallocated'];
    }

    public function save(PostCustomerPaymentAction $postAction): mixed
    {
        $company = app(CompanyContext::class)->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('money.receipt.create')) {
            abort(403, 'Unauthorized.');
        }

        $this->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'money_account_id' => ['required', 'integer', 'exists:money_accounts,id'],
            'payment_method' => ['required', 'string', 'in:cash,bank_transfer'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $pmtAmount = BigDecimal::of($this->amount);
        $allocList = [];
        $allocatedSum = BigDecimal::zero();

        foreach ($this->allocations as $alloc) {
            $amt = BigDecimal::of($alloc['allocated_amount'] ?: '0');
            if ($amt->isPositive()) {
                $maxDue = BigDecimal::of($alloc['outstanding']);
                if ($amt->isGreaterThan($maxDue)) {
                    $this->addError('allocations', "Allocation for invoice {$alloc['invoice_number']} cannot exceed outstanding balance ({$maxDue}).");

                    return null;
                }

                $paymentAmount = $alloc['document_currency_code'] === $this->currency_code ? $amt
                    : BigDecimal::of(trim($alloc['payment_currency_amount'] ?? '') === '' ? '0' : $alloc['payment_currency_amount']);
                if (! $paymentAmount->isPositive()) {
                    $this->addError('allocations', __('money.payment_amount_required'));

                    return null;
                }
                $allocatedSum = $allocatedSum->plus($paymentAmount);
                $allocList[] = [
                    'sales_invoice_id' => (int) $alloc['sales_invoice_id'],
                    'allocated_amount' => (string) $amt,
                    ...($alloc['document_currency_code'] !== $this->currency_code ? ['payment_currency_amount' => (string) $paymentAmount] : []),
                ];
            }
        }

        if (collect($allocList)->contains(fn ($row) => isset($row['payment_currency_amount']))) {
            foreach ($allocList as &$row) {
                $row['payment_currency_amount'] ??= $row['allocated_amount'];
            }
            unset($row);
        }

        if ($allocatedSum->isGreaterThan($pmtAmount)) {
            $this->addError('amount', "Total allocations ({$allocatedSum}) cannot exceed receipt amount ({$pmtAmount}).");

            return null;
        }

        $payload = [
            'customer_id' => (int) $this->customer_id,
            'money_account_id' => (int) $this->money_account_id,
            'payment_date' => $this->payment_date,
            'document_locale' => $this->document_locale,
            'payment_method' => $this->payment_method,
            'amount' => (string) $pmtAmount,
            'exchange_rate' => $this->exchange_rate,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
            'idempotency_key' => $this->idempotency_key,
            'allocations' => $allocList,
        ];

        $payment = $postAction->execute($company, $user, $payload);
        session()->flash('success', __('sales.posted_successfully'));

        return redirect()->route('payments.show', $payment->public_id);
    }

    public function render(CompanyContext $context): View
    {
        app(MoneyActorGuard::class)->authorize($this->pageCompanyId, 'money.receipt.create');
        $company = $context->company();
        $customers = Customer::where('company_id', $company->id)->where('status', 'active')->orderBy('name_ar')->get();
        $accounts = app(EligibleMoneyAccounts::class)->query((int) $company->id)->orderBy('sort_order')->get();

        return view('livewire.pages.sales.payment-form', [
            'company' => $company,
            'customers' => $customers,
            'accounts' => $accounts,
        ]);
    }
}
