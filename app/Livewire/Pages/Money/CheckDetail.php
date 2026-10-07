<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Money;

use App\Actions\Money\TransitionCheckAction;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\Check;
use App\Services\Money\EligibleMoneyAccounts;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class CheckDetail extends Component
{
    use AuthorizesMoneyPages;

    #[Locked]
    public string $publicId;

    #[Locked]
    public string $requestKey;

    public string $eventDate = '';

    public ?int $bankId = null;

    public string $rate = '';

    public string $notes = '';

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
        $check = $this->instrument();
        $company = app(CompanyContext::class)->company();
        $this->eventDate = Carbon::now($company->timezone)->toDateString();
        $this->rate = $check->currency_code === $company->base_currency_code ? '1' : '';
        $this->requestKey = (string) Str::uuid();
    }

    private function instrument(): Check
    {
        $this->authorizeMoney('money.check.view');
        $check = Check::where('company_id', $this->pageCompanyId)->where('public_id', $this->publicId)->firstOrFail();
        $this->authorizeCheckDirection($check->direction);

        return $check;
    }

    public function recordTransition(string $type): void
    {
        $check = $this->instrument();
        $this->authorizeCheckDirection($check->direction, true);
        $this->validate(['eventDate' => 'required|date_format:Y-m-d', 'notes' => 'nullable|string|max:2000']);
        try {
            app(TransitionCheckAction::class)->execute($check, auth()->user(), ['event_type' => $type, 'event_date' => $this->eventDate,
                'idempotency_key' => $this->requestKey, 'money_account_id' => $type === 'deposit' ? $this->bankId : null, 'exchange_rate' => $this->rate, 'notes' => $this->notes]);
            $this->requestKey = (string) Str::uuid();
        } catch (\InvalidArgumentException|MathException $exception) {
            $this->addError('check', __('money.invalid_request'));
        }
    }

    public function render(): View
    {
        $check = $this->instrument();
        $canManage = $this->canMoney('money.check.'.$check->direction.'.manage');
        $canUndo = $canManage && $this->canMoney($check->direction === 'incoming' ? 'money.receipt.reverse' : 'money.vendor_payment.reverse');
        $payment = $check->direction === 'incoming' ? $check->customerPayment()->firstOrFail() : $check->vendorPayment()->firstOrFail();

        return view('livewire.pages.money.check-detail', ['check' => $check, 'events' => $check->events()->get(), 'payment' => $payment,
            'banks' => $canManage ? app(EligibleMoneyAccounts::class)->query($this->pageCompanyId, 'bank')->where('currency_code', $check->currency_code)->get() : collect(), 'canManage' => $canManage, 'canUndo' => $canUndo]);
    }
}
