<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Money;

use App\Actions\Money\ReverseMoneyTransferAction;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\MoneyTransfer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class TransferDetail extends Component
{
    use AuthorizesMoneyPages;

    #[Locked]
    public string $publicId;

    public string $reason = '';

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
        $this->transfer();
    }

    private function transfer(): MoneyTransfer
    {
        $this->authorizeMoney('money.transfer.view');

        return MoneyTransfer::where('company_id', $this->pageCompanyId)->where('public_id', $this->publicId)->firstOrFail();
    }

    public function reverse(): void
    {
        $this->authorizeMoney('money.transfer.reverse');
        $this->validate(['reason' => 'nullable|string|max:2000']);
        try {
            app(ReverseMoneyTransferAction::class)->execute($this->transfer(), auth()->user(), reason: $this->reason);
        } catch (\InvalidArgumentException $exception) {
            $this->addError('transfer', __('money.invalid_request'));
        }
    }

    public function render(): View
    {
        return view('livewire.pages.money.transfer-detail', ['transfer' => $this->transfer(), 'canReverse' => $this->canMoney('money.transfer.reverse')]);
    }
}
