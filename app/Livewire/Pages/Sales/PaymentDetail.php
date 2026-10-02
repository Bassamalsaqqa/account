<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Models\CustomerPayment;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PaymentDetail extends Component
{
    public CustomerPayment $payment;

    public bool $showReverseModal = false;

    public string $reversalReason = '';

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

        return view('livewire.pages.sales.payment-detail', [
            'canReverse' => $canReverse,
        ]);
    }
}
