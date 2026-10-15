<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Money;

use App\Domain\Money\Queries\MoneyActivityQuery;
use App\Domain\Money\Queries\MoneyBalanceQuery;
use App\Domain\Money\Queries\MoneyDailySummaryQuery;
use App\Domain\Money\Queries\MoneySourceLinksQuery;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\Check;
use App\Services\Phase7\Phase7FinancialRead;
use App\Services\Sales\SalesDocumentRules;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class Overview extends Component
{
    use AuthorizesMoneyPages;

    #[Locked]
    public ?string $type = null;

    public string $date = '';

    public function updatedDate(): void
    {
        $this->resetErrorBag('date');
    }

    public function mount(?string $type = null): void
    {
        abort_unless($type === null || in_array($type, ['cash', 'bank'], true), 404);
        $this->type = $type;
        $this->date = now(app(CompanyContext::class)->company()->timezone)->format('Y-m-d');
        if ($type !== null) {
            $this->authorizeMoney('money.'.$type.'.view');
        }
    }

    public function render(): View
    {
        if ($this->type !== null) {
            $this->authorizeMoney('money.'.$this->type.'.view');
        }
        $cash = $this->type !== 'bank' && $this->canMoney('money.cash.view');
        $bank = $this->type !== 'cash' && $this->canMoney('money.bank.view');
        $checks = $this->canMoney('money.check.view');
        abort_unless($cash || $bank || $checks || $this->canMoney('money.transfer.view'), 403);
        $company = app(CompanyContext::class)->company();
        $accounts = array_merge($cash ? app(MoneyBalanceQuery::class)->forType($company, 'cash') : [], $bank ? app(MoneyBalanceQuery::class)->forType($company, 'bank') : []);
        $checkSummary = [];
        if ($checks) {
            $query = Check::where('company_id', $this->pageCompanyId)->whereIn('id', app(Phase7FinancialRead::class)->visibleCheckIds($this->pageCompanyId))->whereNotIn('status', ['cleared', 'returned', 'cancelled']);
            $checkSummary = $query->selectRaw('direction, currency_code, COUNT(*) AS count, SUM(amount) AS total')->groupBy('direction', 'currency_code')->get()->toArray();
        }
        $today = Carbon::now($company->timezone)->toDateString();
        $selectedDate = $this->date !== '' ? $this->date : $today;
        try {
            app(SalesDocumentRules::class)->date($selectedDate);
        } catch (\InvalidArgumentException) {
            $selectedDate = $today;
            $this->date = $today;
            $this->addError('date', __('money.daily_date_invalid'));
        }

        $types = array_values(array_filter([$cash ? 'cash' : null, $bank ? 'bank' : null]));
        $dailySummaries = $types !== []
            ? app(MoneyDailySummaryQuery::class)->forTypes($company, $types, $selectedDate)
            : [];

        $dueSoon = $checks ? Check::where('company_id', $this->pageCompanyId)->whereNotIn('status', ['cleared', 'returned', 'cancelled'])
            ->whereIn('id', app(Phase7FinancialRead::class)->visibleCheckIds($this->pageCompanyId))
            ->where('due_date', '<=', Carbon::parse($today)->addDays(7)->toDateString())->orderBy('due_date')->orderBy('id')->limit(8)->get() : collect();
        $activity = app(MoneyActivityQuery::class)->recent($this->pageCompanyId, $types);
        $sourceLinks = app(MoneySourceLinksQuery::class)->forRows($this->pageCompanyId, $activity);

        return view('livewire.pages.money.overview', compact('accounts', 'checkSummary', 'dueSoon', 'today', 'selectedDate', 'dailySummaries', 'activity', 'sourceLinks', 'company'));
    }
}
