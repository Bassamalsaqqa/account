<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Money;

use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\Check;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
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

    public function updated(): void
    {
        $this->authorizeMoney('money.check.view');
        $this->resetPage();
    }

    public function render(): View
    {
        $this->authorizeMoney('money.check.view');
        $canOutgoing = $this->canMoney('purchasing.cost.view');
        $today = Carbon::now(app(CompanyContext::class)->company()->timezone)->toDateString();
        $checks = Check::where('company_id', $this->pageCompanyId)->when(! $canOutgoing, fn ($q) => $q->where('direction', 'incoming'))
            ->when(in_array($this->direction, ['incoming', 'outgoing'], true), fn ($q) => $q->where('direction', $this->direction))
            ->when(in_array($this->status, ['received', 'deposited', 'issued', 'cleared', 'returned', 'cancelled'], true), fn ($q) => $q->where('status', $this->status))
            ->when($this->status === 'due', fn ($q) => $q->whereNotIn('status', ['cleared', 'returned', 'cancelled'])->where('due_date', '<=', $today))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('check_number', 'like', '%'.trim($this->search).'%'))
            ->orderByDesc('received_issued_date')->orderByDesc('id')->paginate(15);

        return view('livewire.pages.money.check-index', ['checks' => $checks, 'canViewOutgoing' => $canOutgoing,
            'canIncoming' => $this->canMoney('money.check.incoming.manage') && $this->canMoney('money.receipt.create'),
            'canOutgoing' => $canOutgoing && $this->canMoney('money.check.outgoing.manage') && $this->canMoney('money.vendor_payment.create')]);
    }
}
