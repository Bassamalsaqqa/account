<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Domain\Money\Queries\SettlementTargetsQuery;
use App\Models\CustomerPayment;
use App\Services\Money\MoneyActorGuard;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class PaymentDetail extends Component
{
    public CustomerPayment $payment;

    public bool $showReverseModal = false;

    public string $reversalReason = '';

    public bool $showCreditForm = false;

    public string $applicationDate = '';

    public string $applicationKey = '';

    /** @var array<int, string> */
    public array $creditAmounts = [];

    /** @var array<int, string> */
    public array $creditPaymentAmounts = [];

    #[Locked]
    public int $pageCompanyId;

    public function boot(): void
    {
        if (isset($this->pageCompanyId)) {
            app(MoneyActorGuard::class)->authorize($this->pageCompanyId, 'money.receipt.view');
        }
    }

    public function openCreditForm(): void
    {
        $this->authorizeCredit();
        $this->applicationDate = now(app(CompanyContext::class)->company()->timezone)->toDateString();
        $this->applicationKey = 'credit:'.Str::ulid();
        $this->creditAmounts = [];
        $this->creditPaymentAmounts = [];
        $this->showCreditForm = true;
    }

    private function authorizeCredit(): void
    {
        try {
            DB::transaction(function (): void {
                app(SalesActorGuard::class)->lockAndAuthorize((int) $this->payment->company_id, auth()->user(), 'money.receipt.allocate');
            });
        } catch (AuthorizationException $exception) {
            abort(403);
        }
    }

    public function applyCredit(ApplyCustomerPaymentCreditAction $action): void
    {
        $this->authorizeCredit();
        $this->validate(['applicationDate' => ['required', 'date'], 'creditAmounts.*' => ['nullable', 'string', 'regex:/^\\d+(?:\\.\\d{1,6})?$/D']]);
        $targets = collect(app(SettlementTargetsQuery::class)->forParty((int) $this->payment->company_id, (int) $this->payment->customer_id, 'customer', 'allocate'))->keyBy('id');
        $allocations = [];
        foreach ($this->creditAmounts as $id => $amount) {
            if (trim($amount) !== '' && ! BigDecimal::of($amount)->isZero()) {
                $allocations[] = ['sales_invoice_id' => (int) $id, 'allocated_amount' => $amount,
                    'payment_currency_amount' => ($targets->get($id)['currency'] ?? null) === $this->payment->currency_code ? (string) $amount : ($this->creditPaymentAmounts[$id] ?? ''), ];
            }
        }
        try {
            $action->execute($this->payment, auth()->user(), ['application_date' => $this->applicationDate, 'idempotency_key' => $this->applicationKey, 'allocations' => $allocations]);
        } catch (\InvalidArgumentException $exception) {
            $this->addError('creditAmounts', $exception->getMessage());

            return;
        }
        $this->payment->refresh();
        $this->showCreditForm = false;
        session()->flash('success', __('sales.credit_applied'));
    }

    public function mount(string $publicId, CompanyContext $context): void
    {
        $company = $context->company();
        $this->pageCompanyId = (int) $company->id;
        $user = auth()->user();

        if (! $user->hasPermissionTo('money.receipt.view')) {
            abort(403, 'Unauthorized.');
        }

        $this->payment = CustomerPayment::with(['customer', 'moneyAccount', 'allocations.salesInvoice'])
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    public function reversePayment(ReverseCustomerPaymentAction $reverseAction, CompanyContext $context): void
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('money.receipt.reverse')) {
            abort(403, 'Unauthorized.');
        }

        $this->validate([
            'reversalReason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->payment = $reverseAction->execute($this->payment, $user, $this->reversalReason);
        $this->showReverseModal = false;
        session()->flash('success', __('sales.reversed_successfully'));
    }

    public function render(): View
    {
        app(MoneyActorGuard::class)->authorize($this->pageCompanyId, 'money.receipt.view');
        $user = auth()->user();
        $canReverse = $user->hasPermissionTo('money.receipt.reverse') && ! $this->payment->is_reversed && $this->payment->check_id === null;

        $canAllocate = $user->hasPermissionTo('money.receipt.allocate') && ! $this->payment->is_reversed
            && BigDecimal::of($this->payment->unallocated_amount)->isPositive();
        $openInvoices = [];
        if ($this->showCreditForm) {
            $this->authorizeCredit();
            $openInvoices = app(SettlementTargetsQuery::class)->forParty((int) $this->payment->company_id, (int) $this->payment->customer_id, 'customer', 'allocate');
        }

        return view('livewire.pages.sales.payment-detail', [
            'canReverse' => $canReverse,
            'canAllocate' => $canAllocate,
            'openInvoices' => $openInvoices,
            'canViewFx' => $user->hasPermissionTo('reports.financial.view'),
        ]);
    }
}
