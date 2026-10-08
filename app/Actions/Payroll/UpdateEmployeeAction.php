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
use InvalidArgumentException;

final class UpdateEmployeeAction
{
    /** @param array<string, mixed> $data */
    public function execute(Employee $employee, User $actor, array $data): Employee
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $employee->company_id) {
            throw new NoActiveCompanyException("Active company context does not match employee company [{$employee->company_id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('Actor does not match authenticated user.');
        }

        if (! $actor->belongsToCompany($employee->company_id)) {
            throw new AuthorizationException("User does not belong to company [{$employee->company_id}].");
        }

        setPermissionsTeamId($employee->company_id);
        if (! $actor->hasPermissionTo('employees.manage')) {
            throw new AuthorizationException('User does not have permission to manage employees.');
        }

        return DB::transaction(function () use ($employee, $actor, $data): Employee {
            $lockedCompany = Company::where('id', $employee->company_id)->lockForUpdate()->firstOrFail();
            app(Phase7FinancialRead::class)->actor((int) $lockedCompany->id, $actor, 'employees.manage');
            if (array_key_exists('default_salary', $data) || array_key_exists('salary_currency_code', $data)) {
                app(Phase7FinancialRead::class)->actor((int) $lockedCompany->id, $actor, 'payroll.salary.view');
            }
            $lockedEmployee = Employee::where('company_id', $lockedCompany->id)->lockForUpdate()->findOrFail($employee->id);

            $updates = [];

            if (array_key_exists('code', $data)) {
                $code = trim((string) $data['code']);
                if ($code === '' || mb_strlen($code) > 32) {
                    throw new InvalidArgumentException('Employee code is required and must not exceed 32 characters.');
                }
                if ($code !== $lockedEmployee->code && Employee::where('company_id', $lockedCompany->id)->where('code', $code)->where('id', '!=', $lockedEmployee->id)->exists()) {
                    throw new InvalidArgumentException("Employee with code [{$code}] already exists.");
                }
                $updates['code'] = $code;
            }

            if (array_key_exists('name', $data)) {
                $name = trim((string) $data['name']);
                if ($name === '' || mb_strlen($name) > 255) {
                    throw new InvalidArgumentException('Employee name is required and must not exceed 255 characters.');
                }
                $updates['name'] = $name;
            }

            if (array_key_exists('phone', $data)) {
                $updates['phone'] = MoneyValues::text($data['phone'] ?? null, 64);
            }

            if (array_key_exists('job_title', $data)) {
                $updates['job_title'] = MoneyValues::text($data['job_title'] ?? null, 255);
            }

            if (array_key_exists('hire_date', $data)) {
                $updates['hire_date'] = isset($data['hire_date']) && trim((string) $data['hire_date']) !== ''
                    ? Carbon::parse((string) $data['hire_date'])->toDateString()
                    : null;
            }

            if (array_key_exists('salary_currency_code', $data)) {
                $currencyCode = (string) $data['salary_currency_code'];
                if (! CompanyCurrency::where('company_id', $lockedCompany->id)->where('currency_code', $currencyCode)->where('enabled', true)->exists()) {
                    throw new InvalidArgumentException("Salary currency [{$currencyCode}] is not enabled in this company.");
                }
                $updates['salary_currency_code'] = $currencyCode;
            }

            if (array_key_exists('default_salary', $data)) {
                $curr = $updates['salary_currency_code'] ?? $lockedEmployee->salary_currency_code;
                $defaultSalary = (string) Phase7Amounts::nonNegative($data['default_salary'] ?? '0', $curr);
                if (BigDecimal::of($defaultSalary)->isNegative()) {
                    throw new InvalidArgumentException('Default salary cannot be negative.');
                }
                $updates['default_salary'] = $defaultSalary;
            }

            if (array_key_exists('active', $data)) {
                $updates['active'] = (bool) $data['active'];
            }

            if (array_key_exists('notes', $data)) {
                $updates['notes'] = MoneyValues::text($data['notes'] ?? null, 2000);
            }

            $lockedEmployee->update($updates);

            app(AuditService::class)->log(
                (int) $lockedCompany->id,
                'employee.updated',
                "Updated employee [{$lockedEmployee->code}] {$lockedEmployee->name}",
                (int) $actor->id,
                $lockedEmployee,
                meta: ['employee_id' => $lockedEmployee->id, 'changes' => array_keys($updates)]
            );

            return $lockedEmployee;
        });
    }
}
