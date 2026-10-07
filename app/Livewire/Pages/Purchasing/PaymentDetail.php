<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Money\Queries\SettlementTargetsQuery;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\VendorPayment;
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

    /** @var array<int, string> */
    public array $creditPaymentAmounts = [];

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
        $this->creditPaymentAmounts = [];
        $this->showCreditForm = true;
    }

    public function applyCredit(ApplyVendorPaymentCreditAction $action, CompanyContext $context): void
    {
        $this->authorizePaymentFinancialRead();
        $this->authorizePurchasing('money.vendor_payment.allocate');

        $this->validate([
            'applicationDate' => ['required', 'date'],
            'creditAmounts.*' => ['nullable', 'string', 'regex:/^\d+(?:\.\d{1,6})?$/D'],
            'creditPaymentAmounts.*' => ['filled', 'string', 'regex:/^\d+(?:\.\d{1,6})?$/D'],
        ]);

        $targets = collect(app(SettlementTargetsQuery::class)->forParty((int) $this->payment->company_id, (int) $this->payment->vendor_id, 'vendor', 'allocate'))->keyBy('id');
        $allocations = [];
        foreach ($this->creditAmounts as $id => $amount) {
            if (trim((string) $amount) !== '' && ! BigDecimal::of((string) $amount)->isZero()) {
                if (($targets->get($id)['currency'] ?? null) !== $this->payment->currency_code) {
                    $this->validate(['creditPaymentAmounts.'.$id => ['required', 'string', 'regex:/^\d+(?:\.\d{1,6})?$/D']]);
                }
                $allocations[] = [
                    'purchase_id' => (int) $id,
                    'allocated_amount' => (string) $amount,
                    'payment_currency_amount' => ($targets->get($id)['currency'] ?? null) === $this->payment->currency_code ? (string) $amount : ($this->creditPaymentAmounts[$id] ?? ''),
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
        $canReverse = $user->hasPermissionTo('money.vendor_payment.reverse') && ! $this->payment->is_reversed && $this->payment->check_id === null;
        $canAllocate = $user->hasPermissionTo('money.vendor_payment.allocate') && ! $this->payment->is_reversed
            && BigDecimal::of($this->payment->unallocated_amount)->isPositive();

        $openPurchases = [];
        if ($this->showCreditForm) {
            $this->authorizePurchasing('money.vendor_payment.allocate');
            $openPurchases = app(SettlementTargetsQuery::class)->forParty((int) $this->payment->company_id, (int) $this->payment->vendor_id, 'vendor', 'allocate');
        }

        return view('livewire.pages.purchasing.payment-detail', [
            'canReverse' => $canReverse,
            'canAllocate' => $canAllocate,
            'openPurchases' => $openPurchases,
        ]);
    }
}
