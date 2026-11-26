<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Payroll;

use App\Actions\Payroll\ReverseEmployeeAdvanceAction;
use App\Actions\Payroll\ReverseSalaryEntryAction;
use App\Actions\Payroll\ReverseSalaryPaymentAction;
use App\Livewire\Concerns\HasTablePageSize;
use App\Livewire\Concerns\ReviewsStandaloneReversal;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\SalaryEntry;
use App\Models\SalaryPayment;
use App\Services\Payroll\PayrollReadService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class EmployeeDetail extends Component
{
    use AuthorizesMoneyPages;
    use HasTablePageSize;
    use ReviewsStandaloneReversal;
    use WithPagination;

    #[Locked]
    public string $publicId;

    public string $tab = 'overview';

    public string $reversalReason = '';

    public string $reversalDate = '';

    public function updatedTab(): void
    {
        foreach (['advancesPage', 'salariesPage', 'statementPage'] as $pageName) {
            $this->resetPage($pageName);
        }
    }

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
        $this->authorizeMoney('employees.view');

        $company = app(CompanyContext::class)->company();
        $this->reversalDate = Carbon::now($company->timezone)->toDateString();
    }

    private function executeReviewedreverseAdvance(int $advanceId): void
    {
        $this->authorizeMoney('payroll.advance.manage');

        $employeeId = Employee::withTrashed()
            ->where('company_id', $this->pageCompanyId)
            ->where('public_id', $this->publicId)
            ->value('id');

        abort_if($employeeId === null, 404);

        $advance = EmployeeAdvance::where('company_id', $this->pageCompanyId)
            ->where('employee_id', $employeeId)
            ->findOrFail($advanceId);

        $this->validate([
            'reversalReason' => 'required|string|max:500',
            'reversalDate' => 'required|date_format:Y-m-d',
        ]);

        try {
            app(ReverseEmployeeAdvanceAction::class)->execute(
                $advance,
                auth()->user(),
                $this->reversalReason,
                $this->reversalDate
            );

            $this->reversalReason = '';
            session()->flash('success', __('payroll.advance_reversed'));
        } catch (\Exception $e) {
            $this->addError('reversal', __('money.invalid_request'));
        }
    }

    private function executeReviewedreverseSalaryEntry(int $entryId): void
    {
        $this->authorizeMoney('payroll.salary.reverse');

        $employeeId = Employee::withTrashed()
            ->where('company_id', $this->pageCompanyId)
            ->where('public_id', $this->publicId)
            ->value('id');

        abort_if($employeeId === null, 404);

        $entry = SalaryEntry::where('company_id', $this->pageCompanyId)
            ->where('employee_id', $employeeId)
            ->findOrFail($entryId);

        $this->validate([
            'reversalReason' => 'required|string|max:500',
            'reversalDate' => 'required|date_format:Y-m-d',
        ]);

        try {
            app(ReverseSalaryEntryAction::class)->execute(
                $entry,
                auth()->user(),
                $this->reversalReason,
                $this->reversalDate
            );

            $this->reversalReason = '';
            session()->flash('success', __('payroll.entry_reversed'));
        } catch (\Exception $e) {
            $this->addError('reversal', __('money.invalid_request'));
        }
    }

    private function executeReviewedreverseSalaryPayment(int $paymentId): void
    {
        $this->authorizeMoney('payroll.salary.reverse');

        $employeeId = Employee::withTrashed()
            ->where('company_id', $this->pageCompanyId)
            ->where('public_id', $this->publicId)
            ->value('id');

        abort_if($employeeId === null, 404);

        $payment = SalaryPayment::where('company_id', $this->pageCompanyId)
            ->where('employee_id', $employeeId)
            ->findOrFail($paymentId);

        $this->validate([
            'reversalReason' => 'required|string|max:500',
            'reversalDate' => 'required|date_format:Y-m-d',
        ]);

        try {
            app(ReverseSalaryPaymentAction::class)->execute(
                $payment,
                auth()->user(),
                $this->reversalReason,
                $this->reversalDate
            );

            $this->reversalReason = '';
            session()->flash('success', __('payroll.payment_reversed'));
        } catch (\Exception $e) {
            $this->addError('reversal', __('money.invalid_request'));
        }
    }

    public function render(): View
    {
        $this->authorizeMoney('employees.view');

        $employee = Employee::withTrashed()
            ->where('company_id', $this->pageCompanyId)
            ->when(! $this->canMoney('payroll.salary.view'), fn ($q) => $q->select(app(PayrollReadService::class)->identityColumns()))
            ->where('public_id', $this->publicId)
            ->where(function ($q) {
                $q->whereNull('deleted_at')
                    ->orWhere(function ($delQ) {
                        $delQ->whereHas('advances')
                            ->orWhereHas('salaryEntries')
                            ->orWhereHas('salaryPayments');
                    });
            })
            ->firstOrFail();

        $readService = app(PayrollReadService::class);
        $user = auth()->user();

        $detail = $readService->employeeDetail($employee, $user);

        $canAdvance = $detail['has_salary_view'] || $detail['has_advance_manage'];
        $canSalary = $detail['has_salary_view'];

        $allowedTabs = ['overview'];
        if ($canAdvance) {
            $allowedTabs[] = 'advances';
        }
        if ($canSalary) {
            $allowedTabs[] = 'salaries';
            $allowedTabs[] = 'statement';
        }
        if (! in_array($this->tab, $allowedTabs, true)) {
            $this->tab = 'overview';
        }

        $advancePositions = [];
        $advanceCount = 0;
        if ($canAdvance) {
            if ($this->tab === 'advances') {
                $advancePositions = $readService->advancePositions($employee, $user);
                $advanceCount = count($advancePositions);
            } else {
                $advanceCount = $readService->advanceCount($employee, $user);
            }
        }

        $salaryPositions = [];
        $salaryCount = 0;
        $statement = ['events' => [], 'balances_by_currency' => []];
        if ($canSalary) {
            if ($this->tab === 'salaries') {
                $salaryPositions = $readService->salaryPositions($employee, $user);
                $salaryCount = count($salaryPositions);
            } else {
                $salaryCount = $readService->salaryCount($employee, $user);
            }

            if ($this->tab === 'statement') {
                $statement = $readService->employeeStatement($employee, $user);
            }
        }

        return view('livewire.pages.payroll.employee-detail', [
            'employee' => $detail['employee'],
            'detail' => $detail,
            'advancePositions' => $this->paginateTableRows($advancePositions, 'advancesPage'),
            'advanceCount' => $advanceCount,
            'salaryPositions' => $this->paginateTableRows($salaryPositions, 'salariesPage'),
            'salaryCount' => $salaryCount,
            'statement' => $statement,
            'statementEvents' => $this->paginateTableRows($statement['events'], 'statementPage'),
            'canPostSalary' => $this->canMoney('payroll.salary.post'),
            'canPaySalary' => $this->canMoney('payroll.salary.pay'),
            'canReverseSalary' => $this->canMoney('payroll.salary.reverse'),
        ]);
    }
}
