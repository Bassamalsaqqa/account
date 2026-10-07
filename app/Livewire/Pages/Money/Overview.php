<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Money;

use App\Domain\Money\Queries\MoneyActivityQuery;
use App\Domain\Money\Queries\MoneyBalanceQuery;
use App\Domain\Money\Queries\MoneySourceLinksQuery;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\Check;
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

    public function mount(?string $type = null): void
    {
        abort_unless($type === null || in_array($type, ['cash', 'bank'], true), 404);
        $this->type = $type;
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
            $query = Check::where('company_id', $this->pageCompanyId)->whereNotIn('status', ['cleared', 'returned', 'cancelled']);
            if (! $this->canMoney('purchasing.cost.view')) {
                $query->where('direction', 'incoming');
            }
            $checkSummary = $query->selectRaw('direction, currency_code, COUNT(*) AS count, SUM(amount) AS total')->groupBy('direction', 'currency_code')->get()->toArray();
        }
        $today = Carbon::now($company->timezone)->toDateString();
        $dueSoon = $checks ? Check::where('company_id', $this->pageCompanyId)->whereNotIn('status', ['cleared', 'returned', 'cancelled'])
            ->when(! $this->canMoney('purchasing.cost.view'), fn ($q) => $q->where('direction', 'incoming'))
            ->where('due_date', '<=', Carbon::parse($today)->addDays(7)->toDateString())->orderBy('due_date')->orderBy('id')->limit(8)->get() : collect();
        $types = array_values(array_filter([$cash ? 'cash' : null, $bank ? 'bank' : null]));
        $activity = app(MoneyActivityQuery::class)->recent($this->pageCompanyId, $types);
        $sourceLinks = app(MoneySourceLinksQuery::class)->forRows($this->pageCompanyId, $activity);

        return view('livewire.pages.money.overview', compact('accounts', 'checkSummary', 'dueSoon', 'today', 'activity', 'sourceLinks', 'company'));
    }
}
