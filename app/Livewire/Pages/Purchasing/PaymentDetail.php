<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\Purchase;
use App\Models\VendorPayment;
use App\Services\Purchasing\PurchasePayablePosition;
use App\Services\Purchasing\VendorPaymentValidationException;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class PaymentDetail extends Component
{
    use AuthorizesPurchasingPages;

    #[Locked]
    public string $publicId;

    public VendorPayment $payment;

    public bool $showReverseModal = false;

    public string $reversalReason = '';

    public bool $showCreditForm = false;

    public string $applicationDate = '';

    public string $applicationKey = '';

    /**
     * @var array<int, string>
     */
    public array $creditAmounts = [];

    public function boot(CompanyContext $context): void
    {
        if (isset($this->pageCompanyId)) {
            if (! $context->hasCompany() || (int) $context->companyId() !== $this->pageCompanyId) {
                abort(403);
            }
            $this->authorizePaymentFinancialRead();
        }
    }

    public function mount(string $publicId, CompanyContext $context): void
    {
        $this->publicId = $publicId;
        $this->pageCompanyId = (int) $context->companyId();
        $this->authorizePaymentFinancialRead();

        $this->payment = VendorPayment::with(['vendor', 'moneyAccount', 'allocations.purchase', 'applicationEvents'])
            ->where('company_id', $this->pageCompanyId)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    public function openCreditForm(): void
    {
        $this->authorizePaymentFinancialRead();
        $this->authorizePurchasing('money.vendor_payment.allocate');

        $this->applicationDate = Carbon::now(app(CompanyContext::class)->company()->timezone)->toDateString();
        $this->applicationKey = 'credit:'.Str::ulid();
        $this->creditAmounts = [];
        $this->showCreditForm = true;
    }

    public function applyCredit(ApplyVendorPaymentCreditAction $action, CompanyContext $context): void
    {
        $this->authorizePaymentFinancialRead();
        $this->authorizePurchasing('money.vendor_payment.allocate');

        $this->validate([
            'applicationDate' => ['required', 'date'],
            'creditAmounts.*' => ['nullable', 'string', 'regex:/^\d+(?:\.\d{1,6})?$/D'],
        ]);

        $allocations = [];
        foreach ($this->creditAmounts as $id => $amount) {
            if (trim((string) $amount) !== '' && ! BigDecimal::of((string) $amount)->isZero()) {
                $allocations[] = [
                    'purchase_id' => (int) $id,
                    'allocated_amount' => (string) $amount,
                ];
            }
        }

        if (empty($allocations)) {
            $this->addError('creditAmounts', __('purchasing.credit_amount_positive'));

            return;
        }

        try {
            $action->execute(
                $this->payment,
                auth()->user(),
                [
                    'application_date' => $this->applicationDate,
                    'idempotency_key' => $this->applicationKey,
                    'allocations' => $allocations,
                ]
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addError('creditAmounts', $exception instanceof VendorPaymentValidationException
                ? __($exception->translationKey) : __('purchasing.payment_request_invalid'));

            return;
        }

        $this->payment->refresh();
        $this->payment->load(['vendor', 'moneyAccount', 'allocations.purchase', 'applicationEvents']);
        $this->showCreditForm = false;
        session()->flash('success', __('purchasing.credit_applied_successfully'));
    }

    public function reversePayment(ReverseVendorPaymentAction $reverseAction, CompanyContext $context): void
    {
        $this->authorizePaymentFinancialRead();
        $this->authorizePurchasing('money.vendor_payment.reverse');

        $this->validate([
            'reversalReason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->payment = $reverseAction->execute($this->payment, auth()->user(), $this->reversalReason);
        } catch (ImmutableRecordException|\InvalidArgumentException $exception) {
            $this->addError('reversalReason', $exception instanceof VendorPaymentValidationException
                ? __($exception->translationKey) : __('purchasing.payment_request_invalid'));

            return;
        }
        $this->payment->load(['vendor', 'moneyAccount', 'allocations.purchase', 'applicationEvents']);
        $this->showReverseModal = false;
        session()->flash('success', __('purchasing.reversed_successfully'));
    }

    public function render(CompanyContext $context): View
    {
        $this->authorizePaymentFinancialRead();

        $user = auth()->user();
        $canReverse = $user->hasPermissionTo('money.vendor_payment.reverse') && ! $this->payment->is_reversed;
        $canAllocate = $user->hasPermissionTo('money.vendor_payment.allocate') && ! $this->payment->is_reversed
            && BigDecimal::of($this->payment->unallocated_amount)->isPositive();

        $openPurchases = [];
        if ($this->showCreditForm) {
            $purchases = Purchase::with(['returns', 'paymentAllocations.vendorPayment'])
                ->where('company_id', $this->payment->company_id)
                ->where('vendor_id', $this->payment->vendor_id)
                ->where('currency_code', $this->payment->currency_code)
                ->where('status', Purchase::STATUS_POSTED)
                ->orderBy('purchase_date')
                ->get();

            $positions = PurchasePayablePosition::forPurchases($purchases);
            foreach ($purchases as $purchase) {
                $pos = $positions[$purchase->id] ?? null;
                if ($pos !== null && $pos->hasOutstanding()) {
                    $openPurchases[] = [
                        'id' => $purchase->id,
                        'number' => $purchase->purchase_number ?? (string) $purchase->id,
                        'date' => $purchase->purchase_date->toDateString(),
                        'grand_total' => (string) $purchase->grand_total_currency,
                        'outstanding' => (string) $pos->outstanding->toScale(6),
                        'exchange_rate' => (string) $purchase->exchange_rate,
                    ];
                }
            }
        }

        return view('livewire.pages.purchasing.payment-detail', [
            'canReverse' => $canReverse,
            'canAllocate' => $canAllocate,
            'openPurchases' => $openPurchases,
        ]);
    }
}
