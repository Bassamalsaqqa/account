<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\Employee;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Money\MoneyValues;
use App\Services\Phase7\Phase7Amounts;
use App\Services\Phase7\Phase7FinancialRead;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateEmployeeAction
{
    /** @param array<string, mixed> $data */
    public function execute(Company $company, User $actor, array $data): Employee
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $company->id) {
            throw new NoActiveCompanyException("Active company context does not match company [{$company->id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('Actor does not match authenticated user.');
        }

        if (! $actor->belongsToCompany($company->id)) {
            throw new AuthorizationException("User does not belong to company [{$company->id}].");
        }

        setPermissionsTeamId($company->id);
        if (! $actor->hasPermissionTo('employees.manage')) {
            throw new AuthorizationException('User does not have permission to manage employees.');
        }

        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || mb_strlen($code) > 32) {
            throw new InvalidArgumentException('Employee code is required and must not exceed 32 characters.');
        }
        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('Employee name is required and must not exceed 255 characters.');
        }

        $currencyCode = (string) ($data['salary_currency_code'] ?? $company->base_currency_code);
        $defaultSalary = '0';
        if (isset($data['default_salary']) && trim((string) $data['default_salary']) !== '') {
            $defaultSalary = (string) Phase7Amounts::nonNegative($data['default_salary'], $currencyCode);
            if (BigDecimal::of($defaultSalary)->isNegative()) {
                throw new InvalidArgumentException('Default salary cannot be negative.');
            }
        }

        $hireDate = null;
        if (isset($data['hire_date']) && trim((string) $data['hire_date']) !== '') {
            $hireDate = Carbon::parse((string) $data['hire_date'])->toDateString();
        }

        $phone = MoneyValues::text($data['phone'] ?? null, 64);
        $jobTitle = MoneyValues::text($data['job_title'] ?? null, 255);
        $notes = MoneyValues::text($data['notes'] ?? null, 2000);
        $active = isset($data['active']) ? (bool) $data['active'] : true;

        return DB::transaction(function () use ($company, $actor, $code, $name, $phone, $jobTitle, $hireDate, $defaultSalary, $currencyCode, $active, $notes, $data): Employee {
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            app(Phase7FinancialRead::class)->actor((int) $lockedCompany->id, $actor, 'employees.manage');
            if (array_key_exists('default_salary', $data) || array_key_exists('salary_currency_code', $data)) {
                app(Phase7FinancialRead::class)->actor((int) $lockedCompany->id, $actor, 'payroll.salary.view');
            }

            if (Employee::where('company_id', $lockedCompany->id)->where('code', $code)->exists()) {
                throw new InvalidArgumentException("Employee with code [{$code}] already exists.");
            }

            if (! CompanyCurrency::where('company_id', $lockedCompany->id)->where('currency_code', $currencyCode)->where('enabled', true)->exists()) {
                throw new InvalidArgumentException("Salary currency [{$currencyCode}] is not enabled in this company.");
            }

            $employee = Employee::create([
                'public_id' => (string) Str::ulid(),
                'company_id' => (int) $lockedCompany->id,
                'code' => $code,
                'name' => $name,
                'phone' => $phone,
                'job_title' => $jobTitle,
                'hire_date' => $hireDate,
                'default_salary' => $defaultSalary,
                'salary_currency_code' => $currencyCode,
                'active' => $active,
                'notes' => $notes,
                'created_by' => (int) $actor->id,
            ]);

            app(AuditService::class)->log(
                (int) $lockedCompany->id,
                'employee.created',
                "Created employee [{$employee->code}] {$employee->name}",
                (int) $actor->id,
                $employee,
                meta: ['employee_id' => $employee->id, 'code' => $employee->code]
            );

            return $employee;
        });
    }
}
