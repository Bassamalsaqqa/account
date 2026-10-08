<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Payroll;

use App\Actions\Payroll\CreateEmployeeAction;
use App\Actions\Payroll\UpdateEmployeeAction;
use App\Livewire\Pages\Money\Concerns\AuthorizesMoneyPages;
use App\Models\CompanyCurrency;
use App\Models\Employee;
use App\Services\Payroll\PayrollReadService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class EmployeeForm extends Component
{
    use AuthorizesMoneyPages;

    #[Locked]
    public ?string $publicId = null;

    public string $name = '';

    public string $code = '';

    public string $phone = '';

    public string $jobTitle = '';

    public string $hireDate = '';

    public string $defaultSalary = '';

    public string $salaryCurrencyCode = '';

    public bool $active = true;

    public string $notes = '';

    public function mount(?string $publicId = null): void
    {
        $this->authorizeMoney('employees.manage');
        $this->publicId = $publicId;

        $company = app(CompanyContext::class)->company();
        $this->salaryCurrencyCode = $company->base_currency_code;

        if ($this->publicId !== null) {
            $employee = Employee::where('company_id', $this->pageCompanyId)->when(! $this->canMoney('payroll.salary.view'), fn ($q) => $q->select(app(PayrollReadService::class)->identityColumns()))
                ->where('public_id', $this->publicId)
                ->firstOrFail();

            $this->name = $employee->name;
            $this->code = $employee->code;
            $this->phone = (string) ($employee->phone ?? '');
            $this->jobTitle = (string) ($employee->job_title ?? '');
            $this->hireDate = $employee->hire_date ? Carbon::parse($employee->hire_date)->toDateString() : '';
            $this->active = (bool) $employee->active;
            $this->notes = (string) ($employee->notes ?? '');

            if ($this->canMoney('payroll.salary.view')) {
                $this->defaultSalary = (string) ($employee->default_salary ?? '');
                $this->salaryCurrencyCode = (string) ($employee->salary_currency_code ?? $company->base_currency_code);
            }
        }
    }

    public function save(): void
    {
        $this->authorizeMoney('employees.manage');

        $rules = [
            'name' => 'required|string|max:128',
            'code' => 'required|string|max:32',
            'phone' => 'nullable|string|max:32',
            'jobTitle' => 'nullable|string|max:128',
            'hireDate' => 'nullable|date_format:Y-m-d',
            'active' => 'boolean',
            'notes' => 'nullable|string|max:2000',
        ];

        $canSalary = $this->canMoney('payroll.salary.view');
        if ($canSalary) {
            $rules['defaultSalary'] = 'nullable|numeric|gte:0';
            $rules['salaryCurrencyCode'] = 'nullable|string|size:3';
        }

        $this->validate($rules);

        $data = [
            'name' => $this->name,
            'code' => $this->code,
            'phone' => $this->phone ?: null,
            'job_title' => $this->jobTitle ?: null,
            'hire_date' => $this->hireDate ?: null,
            'active' => $this->active,
            'notes' => $this->notes ?: null,
        ];

        if ($canSalary) {
            $data['default_salary'] = $this->defaultSalary !== '' ? $this->defaultSalary : '0';
            $data['salary_currency_code'] = $this->salaryCurrencyCode ?: app(CompanyContext::class)->company()->base_currency_code;
        }

        try {
            if ($this->publicId !== null) {
                $employee = Employee::where('company_id', $this->pageCompanyId)->when(! $this->canMoney('payroll.salary.view'), fn ($q) => $q->select(app(PayrollReadService::class)->identityColumns()))
                    ->where('public_id', $this->publicId)
                    ->firstOrFail();

                $employee = app(UpdateEmployeeAction::class)->execute($employee, auth()->user(), $data);
            } else {
                $data['company_id'] = $this->pageCompanyId;
                $data['created_by'] = auth()->id();
                $data['public_id'] = (string) Str::ulid();

                $employee = app(CreateEmployeeAction::class)->execute(app(CompanyContext::class)->company(), auth()->user(), $data);
            }

            $this->redirect(route('employees.show', $employee->public_id), navigate: true);
        } catch (\InvalidArgumentException|MathException $e) {
            $this->addError('defaultSalary', __('money.invalid_request'));
        }
    }

    public function render(): View
    {
        $this->authorizeMoney('employees.manage');

        if (! $this->canMoney('payroll.salary.view')) {
            $this->defaultSalary = '';
            $this->salaryCurrencyCode = app(CompanyContext::class)->company()->base_currency_code;
        }
        $currencies = CompanyCurrency::where('company_id', $this->pageCompanyId)
            ->where('enabled', true)
            ->get();

        return view('livewire.pages.payroll.employee-form', [
            'currencies' => $currencies,
            'canSalary' => $this->canMoney('payroll.salary.view'),
        ]);
    }
}
