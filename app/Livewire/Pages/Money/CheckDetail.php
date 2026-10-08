<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Money;

use App\Actions\Money\TransitionCheckAction;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\Check;
use App\Services\Money\CheckFinancialSourceResolver;
use App\Services\Money\EligibleMoneyAccounts;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
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

        $adapter = app(CheckFinancialSourceResolver::class)->resolve($check);
        $adapter->authorizeRead($this->pageCompanyId, auth()->user());

        return $check;
    }

    public function recordTransition(string $type): void
    {
        $check = $this->instrument();
        $adapter = app(CheckFinancialSourceResolver::class)->resolve($check);
        $this->authorizeMoney('money.check.'.$check->direction.'.manage');
        if (in_array($type, ['return', 'cancel'], true)) {
            $adapter->authorizeReverse($this->pageCompanyId, auth()->user());
        }

        $rules = ['eventDate' => 'required|date_format:Y-m-d', 'notes' => 'nullable|string|max:2000'];
        if (in_array($type, ['return', 'cancel'], true)) {
            $rules['notes'] = 'nullable|string|max:500';
        }
        if ($type === 'deposit') {
            $rules['bankId'] = 'required|integer|min:1';
        }
        $this->validate($rules);
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
        $adapter = app(CheckFinancialSourceResolver::class)->resolve($check);
        $canManage = $this->canMoney('money.check.'.$check->direction.'.manage');
        $canUndo = false;
        if ($canManage) {
            try {
                $adapter->authorizeReverse($this->pageCompanyId, auth()->user());
                $canUndo = true;
            } catch (AuthorizationException) {
                $canUndo = false;
            }
        }
        $payment = $adapter->sourceModel();

        return view('livewire.pages.money.check-detail', ['check' => $check, 'events' => $check->events()->get(), 'payment' => $payment,
            'sourceAdapter' => $adapter,
            'banks' => $canManage ? app(EligibleMoneyAccounts::class)->query($this->pageCompanyId, 'bank')->where('currency_code', $check->currency_code)->get() : collect(), 'canManage' => $canManage, 'canUndo' => $canUndo]);
    }
}
