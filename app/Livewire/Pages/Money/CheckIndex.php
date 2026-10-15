<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Money;

use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\Check;
use App\Services\Phase7\Phase7FinancialRead;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class CheckIndex extends Component
{
    use AuthorizesMoneyPages, WithPagination;

    public string $direction = '';

    public string $status = '';

    public string $search = '';

    public string $attention = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function updated(): void
    {
        $this->authorizeMoney('money.check.view');
        $this->resetPage();
    }

    public function setAttention(string $attention): void
    {
        $this->authorizeMoney('money.check.view');
        $this->attention = in_array($attention, ['today', 'overdue', 'next_7_days', 'open'], true) ? $attention : '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->authorizeMoney('money.check.view');
        $this->attention = '';
        $this->direction = '';
        $this->status = '';
        $this->search = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->resetPage();
    }

    public function render(): View
    {
        $this->authorizeMoney('money.check.view');
        $this->resetErrorBag(['dateFrom', 'dateTo']);
        $dates = Validator::make(['dateFrom' => $this->dateFrom, 'dateTo' => $this->dateTo],
            ['dateFrom' => ['nullable', 'date_format:Y-m-d'],
                'dateTo' => array_filter(['nullable', 'date_format:Y-m-d', $this->dateFrom !== '' ? 'after_or_equal:dateFrom' : null])]);
        foreach ($dates->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError($field, $message);
            }
        }
        $canVendor = $this->canMoney('purchasing.cost.view');
        $canExpense = $this->canMoney('money.expense.view');
        $canAdvance = $this->canMoney('payroll.advance.manage') || $this->canMoney('payroll.salary.view');
        $canSalary = $this->canMoney('payroll.salary.view');
        $canViewOutgoing = $canVendor || $canExpense || $canAdvance || $canSalary;

        $today = Carbon::now(app(CompanyContext::class)->company()->timezone)->toDateString();
        $next7Days = Carbon::parse($today)->addDays(7)->toDateString();
        $terminalStatuses = ['cleared', 'returned', 'cancelled'];

        $visibleCheckIds = app(Phase7FinancialRead::class)->visibleCheckIds($this->pageCompanyId);

        $attentionBase = Check::where('company_id', $this->pageCompanyId)
            ->whereIn('id', $visibleCheckIds)
            ->whereNotIn('status', $terminalStatuses);

        $counts = $attentionBase->selectRaw('COUNT(*) AS open_count')
            ->selectRaw('SUM(CASE WHEN due_date = ? THEN 1 ELSE 0 END) AS today_count', [$today])
            ->selectRaw('SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END) AS overdue_count', [$today])
            ->selectRaw('SUM(CASE WHEN due_date >= ? AND due_date <= ? THEN 1 ELSE 0 END) AS next_count', [$today, $next7Days])->toBase()->first();
        $attentionCounts = ['open' => (int) ($counts->open_count ?? 0), 'today' => (int) ($counts->today_count ?? 0),
            'overdue' => (int) ($counts->overdue_count ?? 0), 'next_7_days' => (int) ($counts->next_count ?? 0)];

        $query = Check::where('company_id', $this->pageCompanyId)
            ->whereIn('id', $visibleCheckIds);

        if ($this->attention === 'today') {
            $query->whereNotIn('status', $terminalStatuses)->where('due_date', '=', $today);
        } elseif ($this->attention === 'overdue') {
            $query->whereNotIn('status', $terminalStatuses)->where('due_date', '<', $today);
        } elseif ($this->attention === 'next_7_days') {
            $query->whereNotIn('status', $terminalStatuses)->where('due_date', '>=', $today)->where('due_date', '<=', $next7Days);
        } elseif ($this->attention === 'open') {
            $query->whereNotIn('status', $terminalStatuses);
        }

        $query->when(in_array($this->direction, ['incoming', 'outgoing'], true), fn ($q) => $q->where('direction', $this->direction))
            ->when(in_array($this->status, ['received', 'deposited', 'issued', 'cleared', 'returned', 'cancelled'], true), fn ($q) => $q->where('status', $this->status))
            ->when($this->status === 'due', fn ($q) => $q->whereNotIn('status', $terminalStatuses)->where('due_date', '<=', $today))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('check_number', 'like', '%'.trim($this->search).'%'));

        if ($dates->fails()) {
            $query->whereRaw('1 = 0');
        } elseif ($this->dateFrom !== '') {
            $query->where('due_date', '>=', $this->dateFrom);
        }
        if (! $dates->fails() && $this->dateTo !== '') {
            $query->where('due_date', '<=', $this->dateTo);
        }

        $checks = $query->orderByDesc('received_issued_date')->orderByDesc('id')->paginate(15);

        return view('livewire.pages.money.check-index', [
            'checks' => $checks,
            'canViewOutgoing' => $canViewOutgoing,
            'canIncoming' => $this->canMoney('money.check.incoming.manage') && $this->canMoney('money.receipt.create'),
            'canOutgoing' => $canVendor && $this->canMoney('money.check.outgoing.manage') && $this->canMoney('money.vendor_payment.create'),
            'attentionCounts' => $attentionCounts,
            'today' => $today,
        ]);
    }
}
