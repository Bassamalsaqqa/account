<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Money;

use App\Actions\Money\PostMoneyTransferAction;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Services\Money\EligibleMoneyAccounts;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class TransferForm extends Component
{
    use AuthorizesMoneyPages;

    #[Locked]
    public string $requestKey;

    public string $date = '';

    public ?int $fromId = null;

    public ?int $toId = null;

    public string $fromAmount = '';

    public string $toAmount = '';

    public string $fromRate = '';

    public string $toRate = '';

    public string $notes = '';

    public function mount(): void
    {
        $company = $this->authorizeMoney('money.transfer.create');
        $this->date = Carbon::now($company->timezone)->toDateString();
        $this->requestKey = (string) Str::uuid();
    }

    public function save(): mixed
    {
        $company = $this->authorizeMoney('money.transfer.create');
        $this->validate(['date' => 'required|date_format:Y-m-d', 'fromId' => 'required|integer', 'toId' => 'required|integer|different:fromId',
            'fromAmount' => 'required|string', 'toAmount' => 'required|string', 'fromRate' => 'required|string', 'toRate' => 'required|string', 'notes' => 'nullable|string|max:2000']);
        try {
            $transfer = app(PostMoneyTransferAction::class)->execute($company, auth()->user(), [
                'transfer_date' => $this->date, 'from_money_account_id' => $this->fromId, 'to_money_account_id' => $this->toId,
                'from_amount' => $this->fromAmount, 'to_amount' => $this->toAmount, 'from_exchange_rate' => $this->fromRate, 'to_exchange_rate' => $this->toRate,
                'notes' => $this->notes, 'idempotency_key' => $this->requestKey]);
        } catch (\InvalidArgumentException|MathException $exception) {
            $this->addError('transfer', __('money.invalid_request'));

            return null;
        }

        return redirect()->route('money.transfers.show', $transfer->public_id);
    }

    public function render(): View
    {
        $company = $this->authorizeMoney('money.transfer.create');

        return view('livewire.pages.money.transfer-form', ['accounts' => app(EligibleMoneyAccounts::class)->query($this->pageCompanyId)->get(), 'baseCurrency' => $company->base_currency_code]);
    }
}
