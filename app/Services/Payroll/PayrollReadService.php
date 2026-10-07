<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\SalaryEntry;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Services\Phase7\Phase7FinancialRead;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

final class PayrollReadService
{
    /**
     * @param array{
     *     search?: string,
     *     active?: bool|null,
     * } $filters
     * @return LengthAwarePaginator<int, Employee>
     */
    public function employeeDirectory(int $companyId, User $actor, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        app(Phase7FinancialRead::class)->actor($companyId, $actor, 'employees.view');
        setPermissionsTeamId($companyId);
        $hasSalaryView = app(Phase7FinancialRead::class)->allows($companyId, 'payroll.salary.view');

        $query = Employee::where('company_id', $companyId);
        if (! $hasSalaryView) {
            $query->select($this->identityColumns());
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('job_title', 'like', "%{$search}%");
            });
        }

        if (isset($filters['active'])) {
            $query->where('active', (bool) $filters['active']);
        }

        $paginator = $query->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);

        if (! $hasSalaryView) {
            $paginator->getCollection()->transform(function (Employee $emp) {
                $emp->setAttribute('default_salary', null);
                $emp->setAttribute('salary_currency_code', null);

                return $emp;
            });
        }

        return $paginator;
    }

    /**
     * @return array<string, mixed>
     */
    public function employeeDetail(Employee $employee, User $actor): array
    {
        $companyId = (int) $employee->company_id;
        app(Phase7FinancialRead::class)->actor($companyId, $actor, 'employees.view');
        setPermissionsTeamId($companyId);
        $hasSalaryView = app(Phase7FinancialRead::class)->allows($companyId, 'payroll.salary.view');
        $hasAdvanceManage = app(Phase7FinancialRead::class)->advance($companyId);
        $hasManage = app(Phase7FinancialRead::class)->allows($companyId, 'employees.manage');

        if (! $hasSalaryView) {
            $employee = Employee::where('company_id', $companyId)->select($this->identityColumns())->findOrFail($employee->id);
            $employee->setAttribute('default_salary', null);
            $employee->setAttribute('salary_currency_code', null);
        }

        return [
            'employee' => $employee,
            'has_salary_view' => $hasSalaryView,
            'has_advance_manage' => $hasAdvanceManage,
            'has_manage' => $hasManage,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function advancePositions(Employee $employee, User $actor): array
    {
        $companyId = (int) $employee->company_id;
        app(Phase7FinancialRead::class)->actor($companyId, $actor, 'employees.view');
        setPermissionsTeamId($companyId);
        if (! app(Phase7FinancialRead::class)->allows($companyId, 'payroll.salary.view') && ! app(Phase7FinancialRead::class)->advance($companyId)) {
            throw new AuthorizationException('User does not have permission to view employee advance positions.');
        }

        $advances = EmployeeAdvance::where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('status', EmployeeAdvance::STATUS_POSTED)
            ->with(['allocations' => fn ($q) => $q->where('status', 'active')])
            ->orderByDesc('advance_date')
            ->orderByDesc('id')
            ->get();

        $positions = [];
        foreach ($advances as $advance) {
            $remaining = $advance->getRemainingAmount();
            $remainingBase = $advance->getRemainingBaseAmount();

            $positions[] = [
                'advance' => $advance,
                'original_amount' => (string) $advance->amount,
                'remaining_amount' => (string) $remaining,
                'remaining_base_amount' => (string) $remainingBase,
                'currency_code' => $advance->currency_code,
                'is_available' => $remaining->isGreaterThan(BigDecimal::zero()),
            ];
        }

        return $positions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function salaryPositions(Employee $employee, User $actor): array
    {
        $companyId = (int) $employee->company_id;
        app(Phase7FinancialRead::class)->actor($companyId, $actor, 'payroll.salary.view');

        $entries = SalaryEntry::where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('status', SalaryEntry::STATUS_POSTED)
            ->with(['paymentAllocations' => fn ($q) => $q->where('status', 'active')])
            ->orderByDesc('recognition_date')
            ->orderByDesc('id')
            ->get();

        $positions = [];
        foreach ($entries as $entry) {
            $remainingPayable = $entry->getRemainingPayableAmount();
            $remainingPayableBase = $entry->getRemainingPayableBaseAmount();

            $positions[] = [
                'entry' => $entry,
                'net_payable' => (string) $entry->net_payable,
                'remaining_payable' => (string) $remainingPayable,
                'remaining_payable_base' => (string) $remainingPayableBase,
                'currency_code' => $entry->currency_code,
                'is_unpaid' => $remainingPayable->isGreaterThan(BigDecimal::zero()),
            ];
        }

        return $positions;
    }

    /**
     * @return array<string, mixed>
     */
    public function employeeStatement(Employee $employee, User $actor, ?string $currency = null): array
    {
        $companyId = (int) $employee->company_id;
        app(Phase7FinancialRead::class)->actor($companyId, $actor, 'employees.view');
        app(Phase7FinancialRead::class)->actor($companyId, $actor, 'payroll.salary.view');

        $advancesQuery = EmployeeAdvance::where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->when($currency !== null, fn ($q) => $q->where('currency_code', $currency))
            ->orderBy('advance_date')
            ->orderBy('id');

        $entriesQuery = SalaryEntry::where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->when($currency !== null, fn ($q) => $q->where('currency_code', $currency))
            ->orderBy('recognition_date')
            ->orderBy('id');

        $paymentsQuery = SalaryPayment::where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->when($currency !== null, fn ($q) => $q->where('currency_code', $currency))
            ->orderBy('payment_date')
            ->orderBy('id');

        $advances = $advancesQuery->get();
        $entries = $entriesQuery->get();
        $payments = $paymentsQuery->get();

        /** @var list<array<string, mixed>> $events */
        $events = [];

        foreach ($advances as $adv) {
            $events[] = [
                'date' => Carbon::parse($adv->advance_date)->toDateString(),
                'type' => 'advance',
                'number' => $adv->advance_number,
                'currency_code' => $adv->currency_code,
                'amount' => (string) $adv->amount,
                'status' => $adv->status,
                'method' => $adv->payment_method,
                'id' => $adv->id,
            ];
        }

        foreach ($entries as $ent) {
            $periodStr = Carbon::parse($ent->period_start)->toDateString().' to '.Carbon::parse($ent->period_end)->toDateString();
            $events[] = [
                'date' => Carbon::parse($ent->recognition_date)->toDateString(),
                'type' => 'salary_entry',
                'number' => $ent->salary_number,
                'currency_code' => $ent->currency_code,
                'earned_salary' => (string) $ent->earned_salary,
                'advance_applied' => (string) $ent->advance_applied,
                'net_payable' => (string) $ent->net_payable,
                'period' => $periodStr,
                'status' => $ent->status,
                'id' => $ent->id,
            ];
        }

        foreach ($payments as $pay) {
            $events[] = [
                'date' => Carbon::parse($pay->payment_date)->toDateString(),
                'type' => 'salary_payment',
                'number' => $pay->payment_number,
                'currency_code' => $pay->currency_code,
                'amount' => (string) $pay->amount,
                'status' => $pay->status,
                'method' => $pay->payment_method,
                'id' => $pay->id,
            ];
        }

        // Sort events chronologically
        usort($events, function (array $a, array $b): int {
            $cmp = strcmp((string) $a['date'], (string) $b['date']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int) $a['id']) <=> ((int) $b['id']);
        });

        // Compute balances per currency from active records
        $currencies = [];
        $allCurrencies = array_unique(array_merge(
            $advances->pluck('currency_code')->all(),
            $entries->pluck('currency_code')->all(),
            $payments->pluck('currency_code')->all()
        ));

        foreach ($allCurrencies as $curr) {
            $outstandingAdvance = BigDecimal::zero();
            foreach ($advances->where('currency_code', $curr) as $adv) {
                $outstandingAdvance = $outstandingAdvance->plus($adv->getRemainingAmount());
            }

            $outstandingPayable = BigDecimal::zero();
            foreach ($entries->where('currency_code', $curr) as $ent) {
                $outstandingPayable = $outstandingPayable->plus($ent->getRemainingPayableAmount());
            }

            $currencies[$curr] = [
                'currency_code' => $curr,
                'outstanding_advance' => (string) $outstandingAdvance,
                'outstanding_payable' => (string) $outstandingPayable,
            ];
        }

        return [
            'events' => $events,
            'balances_by_currency' => $currencies,
        ];
    }

    /** @return list<string> */
    public function identityColumns(): array
    {
        return ['id', 'public_id', 'company_id', 'code', 'name', 'phone', 'job_title', 'hire_date', 'active', 'notes', 'created_at', 'updated_at'];
    }
}
