<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Money;

use App\Domain\Money\Queries\MoneyBalanceQuery;
use App\Domain\Money\Queries\MoneyMovementQuery;
use App\Domain\Money\Queries\MoneySourceLinksQuery;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\MoneyAccount;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class AccountDetail extends Component
{
    use AuthorizesMoneyPages, WithPagination;

    #[Locked]
    public string $publicId;

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
        $this->account();
    }

    private function account(): MoneyAccount
    {
        $account = MoneyAccount::withTrashed()->where('company_id', $this->pageCompanyId)->where('public_id', $this->publicId)->firstOrFail();
        $this->authorizeMoney($account->account_type === 'cash' ? 'money.cash.view' : 'money.bank.view');

        return $account;
    }

    public function render(): View
    {
        $account = $this->account();
        $company = app(CompanyContext::class)->company();
        $balances = app(MoneyBalanceQuery::class)->forType($company, $account->account_type);
        $balance = collect($balances)->firstWhere('public_id', $account->public_id);
        $movements = app(MoneyMovementQuery::class)->forAccount($account, $this->getPage());
        $sourceLinks = app(MoneySourceLinksQuery::class)->forRows((int) $company->id, $movements->items());

        return view('livewire.pages.money.account-detail', compact('account', 'balance', 'movements', 'sourceLinks'));
    }
}
