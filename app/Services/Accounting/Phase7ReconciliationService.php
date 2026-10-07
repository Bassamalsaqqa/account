<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\LandedCostAllocation;
use App\Models\PostingBatch;
use App\Models\Purchase;
use App\Models\SalaryAdvanceAllocation;
use App\Models\SalaryEntry;
use App\Models\SalaryPaymentAllocation;
use App\Services\Phase7\Phase7History;
use App\Services\Purchasing\LandedCostIntegrity;
use App\Services\Purchasing\PurchasePostingCommandBuilder;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use Throwable;

final class Phase7ReconciliationService
{
    public function reconcile(Company $company, bool $isSystem = false): ReconciliationReport
    {
        $context = app(CompanyContext::class);
        if ($isSystem && $context->hasCompany()) {
            throw new InvalidArgumentException('System reconciliation requires no company context.');
        }
        if (! $isSystem && ! $context->hasCompany()) {
            throw new NoActiveCompanyException('Company context required.');
        }
        if (! $isSystem && (int) $context->companyId() !== (int) $company->id) {
            throw new CompanyReassignmentException('Company mismatch.');
        }
        $execute = function () use ($company): ReconciliationReport {
            $cid = (int) $company->id;
            $violations = [];
            $stats = [];
            $audit = function (string $identity, callable $work) use (&$violations): void {
                try {
                    $work();
                } catch (Throwable $e) {
                    $violations[] = $identity.': '.$e->getMessage();
                }
            };
            foreach (Phase7History::SOURCES as $type => $class) {
                $sources = $class::where('company_id', $cid)->get();
                $stats[match ($type) {
                    'expense' => 'expenses_count','employee_advance' => 'advances_count','salary_entry' => 'salary_entries_count',default => 'salary_payments_count'
                }] = $sources->count();
                foreach ($sources as $source) {
                    $audit($type.' '.$source->id, fn () => app(Phase7History::class)->validate($source));
                }
                foreach (PostingBatch::where('company_id', $cid)->where('source_type', $type)->get() as $batch) {
                    $source = $sources->firstWhere('id', $batch->source_id);
                    if ($source === null || (int) $source->posting_batch_id !== (int) $batch->id || $batch->reversal_of_id !== null) {
                        $violations[] = 'Canonical source provenance failure '.$type.' batch '.$batch->id;
                    }
                }
            }
            foreach (PostingBatch::where('company_id', $cid)->where('source_type', 'reversal')->get() as $inverse) {
                $original = PostingBatch::where('company_id', $cid)->find($inverse->reversal_of_id);
                if ($original !== null && isset(Phase7History::SOURCES[$original->source_type])) {
                    $class = Phase7History::SOURCES[$original->source_type];
                    $source = $class::where('company_id', $cid)->find($original->source_id);
                    if ($source === null || $source->status !== 'reversed' || (int) $source->reversal_posting_batch_id !== (int) $inverse->id) {
                        $violations[] = 'Canonical inverse ownership failure batch '.$inverse->id;
                    }
                }
            }
            foreach (SalaryAdvanceAllocation::where('company_id', $cid)->get()->concat(SalaryPaymentAllocation::where('company_id', $cid)->get()) as $row) {
                $audit('Allocation '.$row->id, fn () => $this->allocation($row, $cid));
            }
            foreach (EmployeeAdvance::where('company_id', $cid)->get()->concat(SalaryEntry::where('company_id', $cid)->get()) as $target) {
                $audit('Residual '.$target->id, fn () => $this->residual($target));
            }
            $entries = SalaryEntry::where('company_id', $cid)->where('status', 'posted')->get();
            foreach ($entries as $entry) {
                if ($entry->period_start->gt($entry->period_end)) {
                    $violations[] = 'Invalid salary period '.$entry->id;
                }
                foreach ($entries as $other) {
                    if ($other->id > $entry->id && $other->employee_id === $entry->employee_id && $other->period_start->lte($entry->period_end) && $other->period_end->gte($entry->period_start)) {
                        $violations[] = 'Overlapping salary periods '.$entry->id.'/'.$other->id;
                    }
                }
            }
            $stats['employees_count'] = Employee::withTrashed()->where('company_id', $cid)->count();
            $stats['landed_cost_allocations_count'] = LandedCostAllocation::where('company_id', $cid)->count();
            $ids = LandedCostAllocation::where('company_id', $cid)->pluck('purchase_id')->unique();
            foreach (Purchase::where('company_id', $cid)->where(function ($q) use ($ids) {
                $q->whereIn('id', $ids)->orWhereHas('lines', fn ($l) => $l->where('landed_cost_allocated_base', '!=', '0'));
            })->get() as $purchase) {
                $audit('Landed Purchase '.$purchase->id, function () use ($purchase): void {
                    app(LandedCostIntegrity::class)->validate($purchase, $purchase->isPosted());
                    if ($purchase->isPosted()) {
                        app(PurchasePostingCommandBuilder::class)->validatePosted($purchase);
                    }
                });
            }

            return new ReconciliationReport(company: $company, isHealthy: $violations === [], violations: $violations, stats: $stats);
        };

        return $isSystem ? CompanyScope::executeWithoutScope($execute) : $execute();
    }

    private function allocation(SalaryAdvanceAllocation|SalaryPaymentAllocation $row, int $cid): void
    {
        $parent = $row instanceof SalaryAdvanceAllocation ? $row->salaryEntry()->first() : $row->salaryPayment()->first();
        $target = $row instanceof SalaryAdvanceAllocation ? $row->employeeAdvance()->first() : $row->salaryEntry()->first();
        if ($parent === null || $target === null || (int) $parent->company_id !== $cid || (int) $target->company_id !== $cid
            || (int) $target->employee_id !== (int) $parent->employee_id) {
            throw new InvalidArgumentException('Allocation ownership failure');
        }
    }

    private function residual(EmployeeAdvance|SalaryEntry $target): void
    {
        $advance = $target instanceof EmployeeAdvance;
        $rows = ($advance ? $target->salaryAdvanceAllocations() : $target->paymentAllocations())->where('status', 'active')->get();
        $principal = BigDecimal::zero();
        $base = BigDecimal::zero();
        foreach ($rows as $row) {
            $principal = $principal->plus($row->allocated_amount);
            $base = $base->plus($advance ? $row->advance_base_consumed : $row->salary_book_relief_base);
        }
        if (($target->status === 'reversed' && $rows->isNotEmpty()) || $principal->isGreaterThan($advance ? $target->amount : $target->net_payable)
            || $base->isGreaterThan($advance ? $target->base_amount : $target->base_payable)) {
            throw new InvalidArgumentException('Active residual exceeds principal or carrying value');
        }
    }
}
