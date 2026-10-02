<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Models\CustomerPayment;
use App\Models\SalesInvoice;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
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

    public function openCreditForm(): void
    {
        $this->authorizeCredit();
        $this->applicationDate = now(app(CompanyContext::class)->company()->timezone)->toDateString();
        $this->applicationKey = 'credit:'.Str::ulid();
        $this->creditAmounts = [];
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
        $allocations = [];
        foreach ($this->creditAmounts as $id => $amount) {
            if (trim($amount) !== '' && ! BigDecimal::of($amount)->isZero()) {
                $allocations[] = ['sales_invoice_id' => (int) $id, 'allocated_amount' => $amount];
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
        $user = auth()->user();
        $canReverse = $user->hasPermissionTo('money.receipt.reverse') && ! $this->payment->is_reversed;

        $canAllocate = $user->hasPermissionTo('money.receipt.allocate') && ! $this->payment->is_reversed
            && BigDecimal::of($this->payment->unallocated_amount)->isPositive();
        $openInvoices = [];
        if ($this->showCreditForm) {
            $this->authorizeCredit();
            foreach (SalesInvoice::where('company_id', $this->payment->company_id)->where('customer_id', $this->payment->customer_id)
                ->where('currency_code', $this->payment->currency_code)->where('status', 'posted')->orderBy('issue_date')->get() as $invoice) {
                $outstanding = $invoice->calculateOutstanding();
                if ($outstanding->isPositive()) {
                    $openInvoices[] = ['id' => $invoice->id, 'number' => $invoice->invoice_number, 'outstanding' => (string) $outstanding->toScale(6)];
                }
            }
        }

        return view('livewire.pages.sales.payment-detail', [
            'canReverse' => $canReverse,
            'canAllocate' => $canAllocate,
            'openInvoices' => $openInvoices,
            'canViewFx' => $user->hasPermissionTo('reports.financial.view'),
        ]);
    }
}
