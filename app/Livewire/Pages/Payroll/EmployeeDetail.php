<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Payroll;

use App\Actions\Payroll\ReverseEmployeeAdvanceAction;
use App\Actions\Payroll\ReverseSalaryEntryAction;
use App\Actions\Payroll\ReverseSalaryPaymentAction;
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

#[Layout('layouts.app')]
class EmployeeDetail extends Component
{
    use AuthorizesMoneyPages;

    #[Locked]
    public string $publicId;

    public string $tab = 'overview';

    public string $reversalReason = '';

    public string $reversalDate = '';

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
        $this->authorizeMoney('employees.view');

        $company = app(CompanyContext::class)->company();
        $this->reversalDate = Carbon::now($company->timezone)->toDateString();
    }

    public function reverseAdvance(int $advanceId): void
    {
        $this->authorizeMoney('payroll.advance.manage');

        $advance = EmployeeAdvance::where('company_id', $this->pageCompanyId)
            ->where('employee_id', Employee::where('company_id', $this->pageCompanyId)->where('public_id', $this->publicId)->value('id'))
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

    public function reverseSalaryEntry(int $entryId): void
    {
        $this->authorizeMoney('payroll.salary.reverse');

        $entry = SalaryEntry::where('company_id', $this->pageCompanyId)
            ->where('employee_id', Employee::where('company_id', $this->pageCompanyId)->where('public_id', $this->publicId)->value('id'))
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

    public function reverseSalaryPayment(int $paymentId): void
    {
        $this->authorizeMoney('payroll.salary.reverse');

        $payment = SalaryPayment::where('company_id', $this->pageCompanyId)
            ->where('employee_id', Employee::where('company_id', $this->pageCompanyId)->where('public_id', $this->publicId)->value('id'))
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

        $employee = Employee::where('company_id', $this->pageCompanyId)->when(! $this->canMoney('payroll.salary.view'), fn ($q) => $q->select(app(PayrollReadService::class)->identityColumns()))
            ->where('public_id', $this->publicId)
            ->firstOrFail();

        $readService = app(PayrollReadService::class);
        $user = auth()->user();

        $detail = $readService->employeeDetail($employee, $user);

        $advancePositions = [];
        if ($detail['has_salary_view'] || $detail['has_advance_manage']) {
            $advancePositions = $readService->advancePositions($employee, $user);
        }

        $salaryPositions = [];
        $statement = ['events' => [], 'balances_by_currency' => []];
        if ($detail['has_salary_view']) {
            $salaryPositions = $readService->salaryPositions($employee, $user);
            $statement = $readService->employeeStatement($employee, $user);
        }

        return view('livewire.pages.payroll.employee-detail', [
            'employee' => $detail['employee'],
            'detail' => $detail,
            'advancePositions' => $advancePositions,
            'salaryPositions' => $salaryPositions,
            'statement' => $statement,
            'canPostSalary' => $this->canMoney('payroll.salary.post'),
            'canPaySalary' => $this->canMoney('payroll.salary.pay'),
            'canReverseSalary' => $this->canMoney('payroll.salary.reverse'),
        ]);
    }
}
