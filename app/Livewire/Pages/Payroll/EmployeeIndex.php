<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Payroll;

use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Services\Payroll\PayrollReadService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class EmployeeIndex extends Component
{
    use AuthorizesMoneyPages, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $status = '';

    public function updated(): void
    {
        $this->authorizeMoney('employees.view');
        $this->resetPage();
    }

    public function render(): View
    {
        $this->authorizeMoney('employees.view');

        $activeFilter = null;
        if ($this->status === 'active') {
            $activeFilter = true;
        } elseif ($this->status === 'inactive') {
            $activeFilter = false;
        }

        $readService = app(PayrollReadService::class);
        $employees = $readService->employeeDirectory($this->pageCompanyId, auth()->user(), [
            'search' => $this->search,
            'active' => $activeFilter,
        ]);

        return view('livewire.pages.payroll.employee-index', [
            'employees' => $employees,
            'canManage' => $this->canMoney('employees.manage'),
            'canSalary' => $this->canMoney('payroll.salary.view'),
            'canAdvance' => $this->canMoney('payroll.advance.manage'),
        ]);
    }
}
