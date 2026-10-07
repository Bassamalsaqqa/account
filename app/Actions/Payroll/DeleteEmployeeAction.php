<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\SalaryEntry;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Phase7\Phase7FinancialRead;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class DeleteEmployeeAction
{
    public function execute(Employee $employee, User $actor): void
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

        DB::transaction(function () use ($employee, $actor): void {
            $lockedCompany = Company::where('id', $employee->company_id)->lockForUpdate()->firstOrFail();
            app(Phase7FinancialRead::class)->actor((int) $lockedCompany->id, $actor, 'employees.manage');
            $lockedEmployee = Employee::where('company_id', $lockedCompany->id)->lockForUpdate()->findOrFail($employee->id);

            // If financial records exist, soft delete instead of hard delete
            $hasFinancialHistory = EmployeeAdvance::where('company_id', $lockedCompany->id)->where('employee_id', $lockedEmployee->id)->exists()
                || SalaryEntry::where('company_id', $lockedCompany->id)->where('employee_id', $lockedEmployee->id)->exists()
                || SalaryPayment::where('company_id', $lockedCompany->id)->where('employee_id', $lockedEmployee->id)->exists();

            if ($hasFinancialHistory) {
                $lockedEmployee->update(['active' => false]);
                $lockedEmployee->delete(); // Soft delete
            } else {
                $lockedEmployee->forceDelete();
            }

            app(AuditService::class)->log(
                (int) $lockedCompany->id,
                'employee.deleted',
                "Deleted employee [{$lockedEmployee->code}] {$lockedEmployee->name}",
                (int) $actor->id,
                $lockedEmployee,
                meta: ['employee_id' => $lockedEmployee->id, 'code' => $lockedEmployee->code]
            );
        });
    }
}
