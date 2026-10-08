<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Payroll;

use App\Actions\Payroll\PostSalaryEntryAction;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\CompanyCurrency;
use App\Models\Employee;
use App\Services\Payroll\PayrollReadService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class SalaryEntryForm extends Component
{
    use AuthorizesMoneyPages;

    #[Locked]
    public string $requestKey;

    #[Url(as: 'employee')]
    public ?string $employeePublicId = null;

    public ?int $employeeId = null;

    public string $recognitionDate = '';

    public string $periodStart = '';

    public string $periodEnd = '';

    public string $currencyCode = '';

    public string $exchangeRate = '1';

    #[Locked]
    public bool $loadedSalaryDefaults = false;

    public string $baseSalary = '';

    public string $bonus = '0';

    public string $deduction = '0';

    public string $notes = '';

    /** @var array<int, string> */
    public array $advanceAllocations = [];

    public function mount(?string $employee = null): void
    {
        $this->authorizeMoney('payroll.salary.post');

        if ($employee !== null) {
            $this->employeePublicId = $employee;
        }

        $company = app(CompanyContext::class)->company();
        $this->currencyCode = $company->base_currency_code;
        $now = Carbon::now($company->timezone);
        $this->recognitionDate = $now->toDateString();
        $this->periodStart = $now->startOfMonth()->toDateString();
        $this->periodEnd = $now->endOfMonth()->toDateString();
        $this->requestKey = (string) Str::uuid();

        if ($this->employeePublicId !== null) {
            $emp = Employee::where('company_id', $this->pageCompanyId)->when(! $this->canMoney('payroll.salary.view'), fn ($q) => $q->select(app(PayrollReadService::class)->identityColumns()))
                ->where('public_id', $this->employeePublicId)
                ->first();
            if ($emp !== null) {
                $this->employeeId = $emp->id;
                if ($this->canMoney('payroll.salary.view') && $emp->salary_currency_code) {
                    $this->currencyCode = $emp->salary_currency_code;
                }
                if ($this->canMoney('payroll.salary.view') && $emp->default_salary) {
                    $this->baseSalary = (string) $emp->default_salary;
                    $this->loadedSalaryDefaults = true;
                }
            }
        }
    }

    public function updatedEmployeeId(): void
    {
        $emp = Employee::where('company_id', $this->pageCompanyId)->when(! $this->canMoney('payroll.salary.view'), fn ($q) => $q->select(app(PayrollReadService::class)->identityColumns()))->find($this->employeeId);
        if ($emp !== null) {
            if ($this->canMoney('payroll.salary.view') && $emp->salary_currency_code) {
                $this->currencyCode = $emp->salary_currency_code;
            }
            if ($this->canMoney('payroll.salary.view') && $emp->default_salary) {
                $this->baseSalary = (string) $emp->default_salary;
                $this->loadedSalaryDefaults = true;
            }
        }
        $this->advanceAllocations = [];
    }

    public function updatedCurrencyCode(): void
    {
        $company = app(CompanyContext::class)->company();
        if ($this->currencyCode === $company->base_currency_code) {
            $this->exchangeRate = '1';
        } else {
            $this->exchangeRate = '';
        }
        $this->advanceAllocations = [];
    }

    public function save(): void
    {
        $this->authorizeMoney('payroll.salary.post');

        $this->validate([
            'employeeId' => 'required|integer|exists:employees,id',
            'recognitionDate' => 'required|date_format:Y-m-d',
            'periodStart' => 'required|date_format:Y-m-d',
            'periodEnd' => 'required|date_format:Y-m-d|after_or_equal:periodStart',
            'currencyCode' => 'required|string|size:3',
            'exchangeRate' => 'required|numeric|gt:0',
            'baseSalary' => 'required|numeric|gte:0',
            'bonus' => 'nullable|numeric|gte:0',
            'deduction' => 'nullable|numeric|gte:0',
            'notes' => 'nullable|string|max:2000',
            'advanceAllocations' => 'array',
            'advanceAllocations.*' => 'nullable|numeric|gte:0',
        ]);

        $advancesPayload = [];
        foreach ($this->advanceAllocations as $advId => $allocatedAmt) {
            if (trim((string) $allocatedAmt) !== '' && BigDecimal::of($allocatedAmt)->isPositive()) {
                $advancesPayload[] = [
                    'advance_id' => (int) $advId,
                    'allocated_amount' => (string) $allocatedAmt,
                ];
            }
        }

        $company = app(CompanyContext::class)->company();
        $user = auth()->user();

        try {
            app(PostSalaryEntryAction::class)->execute($company, $user, [
                'employee_id' => $this->employeeId,
                'recognition_date' => $this->recognitionDate,
                'period_start' => $this->periodStart,
                'period_end' => $this->periodEnd,
                'currency_code' => $this->currencyCode,
                'exchange_rate' => $this->exchangeRate,
                'base_salary' => $this->baseSalary,
                'bonus' => $this->bonus ?: '0',
                'deduction' => $this->deduction ?: '0',
                'advances' => $advancesPayload,
                'notes' => $this->notes,
                'idempotency_key' => $this->requestKey,
            ]);

            $emp = Employee::where('company_id', $this->pageCompanyId)->select(['id', 'public_id'])->findOrFail($this->employeeId);
            session()->flash('success', __('payroll.entry_success'));
            $this->redirect(route('employees.show', $emp->public_id), navigate: true);
        } catch (\InvalidArgumentException|MathException|ModelNotFoundException $e) {
            $this->addError('salary', __('money.invalid_request'));
        }
    }

    public function render(): View
    {
        $this->authorizeMoney('payroll.salary.post');

        if ($this->loadedSalaryDefaults && ! $this->canMoney('payroll.salary.view')) {
            $this->baseSalary = '';
            $this->bonus = '0';
            $this->deduction = '0';
            $this->advanceAllocations = [];
            $this->loadedSalaryDefaults = false;
        }
        $employees = Employee::where('company_id', $this->pageCompanyId)->when(! $this->canMoney('payroll.salary.view'), fn ($q) => $q->select(app(PayrollReadService::class)->identityColumns()))
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $currencies = CompanyCurrency::where('company_id', $this->pageCompanyId)
            ->where('enabled', true)
            ->get();

        $availableAdvances = [];
        if ($this->employeeId !== null && ($this->canMoney('payroll.salary.view') || $this->canMoney('payroll.advance.manage'))) {
            $emp = Employee::where('company_id', $this->pageCompanyId)->when(! $this->canMoney('payroll.salary.view'), fn ($q) => $q->select(app(PayrollReadService::class)->identityColumns()))->find($this->employeeId);
            if ($emp !== null) {
                $positions = app(PayrollReadService::class)->advancePositions($emp, auth()->user());
                $availableAdvances = array_filter($positions, fn ($p) => $p['is_available'] && $p['currency_code'] === $this->currencyCode);
            }
        }

        // Calculations preview
        $earned = BigDecimal::zero();
        $applied = BigDecimal::zero();
        $netPayable = BigDecimal::zero();

        try {
            $base = BigDecimal::of($this->baseSalary !== '' ? $this->baseSalary : '0');
            $bon = BigDecimal::of($this->bonus !== '' ? $this->bonus : '0');
            $ded = BigDecimal::of($this->deduction !== '' ? $this->deduction : '0');
            $earned = $base->plus($bon)->minus($ded);

            foreach ($this->advanceAllocations as $advAmt) {
                if (trim((string) $advAmt) !== '' && is_numeric($advAmt)) {
                    $applied = $applied->plus(BigDecimal::of($advAmt));
                }
            }

            if ($earned->isGreaterThanOrEqualTo(BigDecimal::zero())) {
                $netPayable = $earned->minus($applied);
            }
        } catch (\Exception) {
            // Ignore format exceptions during live typing
        }

        return view('livewire.pages.payroll.salary-entry-form', [
            'employees' => $employees,
            'currencies' => $currencies,
            'availableAdvances' => $availableAdvances,
            'previewEarned' => (string) $earned,
            'previewApplied' => (string) $applied,
            'previewNetPayable' => (string) $netPayable,
        ]);
    }
}
