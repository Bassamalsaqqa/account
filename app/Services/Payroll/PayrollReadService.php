<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\SalaryAdvanceAllocation;
use App\Models\SalaryEntry;
use App\Models\SalaryPayment;
use App\Models\SalaryPaymentAllocation;
use App\Models\User;
use App\Services\Phase7\Phase7FinancialRead;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class PayrollReadService
{
    /**
     * @param array{
     *     search?: string,
     *     active?: bool|null,
     * } $filters
     * @return LengthAwarePaginator<int, Employee>
     */
    public function employeeDirectory(int $companyId, User $actor, array $filters = [], int $perPage = 50): LengthAwarePaginator
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
            ->paginate(max(1, min(200, $perPage)));

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
            $employee = Employee::withTrashed()->where('company_id', $companyId)->select($this->identityColumns())->findOrFail($employee->id);
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

    public function advanceCount(Employee $employee, User $actor): int
    {
        $companyId = (int) $employee->company_id;
        app(Phase7FinancialRead::class)->actor($companyId, $actor, 'employees.view');
        setPermissionsTeamId($companyId);
        if (! app(Phase7FinancialRead::class)->allows($companyId, 'payroll.salary.view') && ! app(Phase7FinancialRead::class)->advance($companyId)) {
            throw new AuthorizationException('User does not have permission to view employee advance positions.');
        }

        return EmployeeAdvance::where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('status', EmployeeAdvance::STATUS_POSTED)
            ->count();
    }

    public function salaryCount(Employee $employee, User $actor): int
    {
        $companyId = (int) $employee->company_id;
        app(Phase7FinancialRead::class)->actor($companyId, $actor, 'payroll.salary.view');

        return SalaryEntry::where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('status', SalaryEntry::STATUS_POSTED)
            ->count();
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
            ->with(['check' => fn ($q) => $q->where('company_id', $companyId)])
            ->orderByDesc('advance_date')
            ->orderByDesc('id')
            ->get();

        $advanceSums = $this->batchAdvanceAllocationSums($companyId, $advances);

        $positions = [];
        foreach ($advances as $advance) {
            if ($advance->isReversed()) {
                $remaining = BigDecimal::zero();
                $remainingBase = BigDecimal::zero();
            } else {
                $sums = $advanceSums[(int) $advance->id] ?? null;
                $allocated = $sums !== null ? $sums['allocated'] : BigDecimal::zero();
                $consumed = $sums !== null ? $sums['base_consumed'] : BigDecimal::zero();
                $remaining = BigDecimal::of($advance->amount)->minus($allocated);
                $remainingBase = BigDecimal::of($advance->base_amount)->minus($consumed);
            }

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
            ->orderByDesc('recognition_date')
            ->orderByDesc('id')
            ->get();

        $salarySums = $this->batchSalaryAllocationSums($companyId, $entries);

        $positions = [];
        foreach ($entries as $entry) {
            if ($entry->isReversed()) {
                $remainingPayable = BigDecimal::zero();
                $remainingPayableBase = BigDecimal::zero();
            } else {
                $sums = $salarySums[(int) $entry->id] ?? null;
                $paid = $sums !== null ? $sums['allocated'] : BigDecimal::zero();
                $relieved = $sums !== null ? $sums['book_relief_base'] : BigDecimal::zero();
                $remainingPayable = BigDecimal::of($entry->net_payable)->minus($paid);
                $remainingPayableBase = BigDecimal::of($entry->base_payable)->minus($relieved);
            }

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
     * @return array{events: list<array<string, mixed>>, balances_by_currency: array<string, array{currency_code: string, outstanding_advance: string, outstanding_payable: string}>}
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
        $advanceSums = $this->batchAdvanceAllocationSums($companyId, $advances);
        $entrySums = $this->batchSalaryAllocationSums($companyId, $entries);

        $currencies = [];
        $allCurrencies = array_unique(array_merge(
            $advances->pluck('currency_code')->all(),
            $entries->pluck('currency_code')->all(),
            $payments->pluck('currency_code')->all()
        ));

        foreach ($allCurrencies as $curr) {
            $outstandingAdvance = BigDecimal::zero();
            foreach ($advances->where('currency_code', $curr) as $adv) {
                if ($adv->isReversed()) {
                    continue;
                }
                $allocated = $advanceSums[(int) $adv->id]['allocated'] ?? BigDecimal::zero();
                $outstandingAdvance = $outstandingAdvance->plus(BigDecimal::of($adv->amount)->minus($allocated));
            }

            $outstandingPayable = BigDecimal::zero();
            foreach ($entries->where('currency_code', $curr) as $ent) {
                if ($ent->isReversed()) {
                    continue;
                }
                $paid = $entrySums[(int) $ent->id]['allocated'] ?? BigDecimal::zero();
                $outstandingPayable = $outstandingPayable->plus(BigDecimal::of($ent->net_payable)->minus($paid));
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

    /**
     * @param  iterable<EmployeeAdvance>  $advances
     * @return array<int, array{allocated: BigDecimal, base_consumed: BigDecimal}>
     */
    private function batchAdvanceAllocationSums(int $companyId, iterable $advances): array
    {
        if ($companyId <= 0) {
            throw new InvalidArgumentException('Payroll batch allocation lookup requires a positive company_id.');
        }

        $activeIds = [];
        foreach ($advances as $advance) {
            if ((int) $advance->company_id !== $companyId) {
                throw new InvalidArgumentException('Payroll advance allocation batch cannot mix companies.');
            }
            if (! $advance->isReversed()) {
                $activeIds[(int) $advance->id] = (int) $advance->id;
            }
        }

        if ($activeIds === []) {
            return [];
        }

        $rows = SalaryAdvanceAllocation::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_advance_id', array_values($activeIds))
            ->where('status', SalaryAdvanceAllocation::STATUS_ACTIVE)
            ->selectRaw('employee_advance_id, SUM(allocated_amount) AS total_allocated, SUM(advance_base_consumed) AS total_base_consumed')
            ->groupBy('employee_advance_id')
            ->get();

        $sums = [];
        foreach ($rows as $row) {
            $id = (int) $row->getAttribute('employee_advance_id');
            $alloc = $row->getAttribute('total_allocated');
            $base = $row->getAttribute('total_base_consumed');
            $sums[$id] = [
                'allocated' => $alloc !== null ? BigDecimal::of((string) $alloc) : BigDecimal::zero(),
                'base_consumed' => $base !== null ? BigDecimal::of((string) $base) : BigDecimal::zero(),
            ];
        }

        return $sums;
    }

    /**
     * @param  iterable<SalaryEntry>  $entries
     * @return array<int, array{allocated: BigDecimal, book_relief_base: BigDecimal}>
     */
    private function batchSalaryAllocationSums(int $companyId, iterable $entries): array
    {
        if ($companyId <= 0) {
            throw new InvalidArgumentException('Payroll batch allocation lookup requires a positive company_id.');
        }

        $activeIds = [];
        foreach ($entries as $entry) {
            if ((int) $entry->company_id !== $companyId) {
                throw new InvalidArgumentException('Payroll salary allocation batch cannot mix companies.');
            }
            if (! $entry->isReversed()) {
                $activeIds[(int) $entry->id] = (int) $entry->id;
            }
        }

        if ($activeIds === []) {
            return [];
        }

        $rows = SalaryPaymentAllocation::query()
            ->where('company_id', $companyId)
            ->whereIn('salary_entry_id', array_values($activeIds))
            ->where('status', SalaryPaymentAllocation::STATUS_ACTIVE)
            ->selectRaw('salary_entry_id, SUM(allocated_amount) AS total_allocated, SUM(salary_book_relief_base) AS total_book_relief_base')
            ->groupBy('salary_entry_id')
            ->get();

        $sums = [];
        foreach ($rows as $row) {
            $id = (int) $row->getAttribute('salary_entry_id');
            $alloc = $row->getAttribute('total_allocated');
            $relief = $row->getAttribute('total_book_relief_base');
            $sums[$id] = [
                'allocated' => $alloc !== null ? BigDecimal::of((string) $alloc) : BigDecimal::zero(),
                'book_relief_base' => $relief !== null ? BigDecimal::of((string) $relief) : BigDecimal::zero(),
            ];
        }

        return $sums;
    }

    /** @return list<string> */
    public function identityColumns(): array
    {
        return ['id', 'public_id', 'company_id', 'code', 'name', 'phone', 'job_title', 'hire_date', 'active', 'notes', 'created_at', 'updated_at', 'deleted_at'];
    }
}
