<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Money;

use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\MoneyTransfer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class TransferIndex extends Component
{
    use AuthorizesMoneyPages, WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->authorizeMoney('money.transfer.view');
        $this->resetPage();
    }

    public function render(): View
    {
        $this->authorizeMoney('money.transfer.view');
        $transfers = MoneyTransfer::where('company_id', $this->pageCompanyId)->when(trim($this->search) !== '', fn ($q) => $q->where('transfer_number', 'like', '%'.trim($this->search).'%'))->orderByDesc('transfer_date')->orderByDesc('id')->paginate(15);

        return view('livewire.pages.money.transfer-index', ['transfers' => $transfers, 'canCreate' => $this->canMoney('money.transfer.create')]);
    }
}
