<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Money;

use App\Domain\Money\Queries\MoneyBalanceQuery;
use App\Domain\Money\Queries\MoneyDailySummaryQuery;
use App\Domain\Money\Queries\MoneyMovementQuery;
use App\Domain\Money\Queries\MoneySourceLinksQuery;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\MoneyAccount;
use App\Services\Sales\SalesDocumentRules;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
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

    public string $date = '';

    public function updatedDate(): void
    {
        $this->resetErrorBag('date');
    }

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
        $this->account();
        $this->date = now(app(CompanyContext::class)->company()->timezone)->format('Y-m-d');
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
        $today = Carbon::now($company->timezone)->toDateString();
        $selectedDate = $this->date !== '' ? $this->date : $today;
        try {
            app(SalesDocumentRules::class)->date($selectedDate);
        } catch (\InvalidArgumentException) {
            $selectedDate = $today;
            $this->date = $today;
            $this->addError('date', __('money.daily_date_invalid'));
        }

        $balances = app(MoneyBalanceQuery::class)->forType($company, $account->account_type);
        $balance = collect($balances)->firstWhere('public_id', $account->public_id);
        $dailySummary = app(MoneyDailySummaryQuery::class)->forAccount($account, $selectedDate);
        $movements = app(MoneyMovementQuery::class)->forAccount($account, $this->getPage());
        $sourceLinks = app(MoneySourceLinksQuery::class)->forRows((int) $company->id, $movements->items());

        return view('livewire.pages.money.account-detail', compact('account', 'balance', 'dailySummary', 'selectedDate', 'today', 'movements', 'sourceLinks'));
    }
}
