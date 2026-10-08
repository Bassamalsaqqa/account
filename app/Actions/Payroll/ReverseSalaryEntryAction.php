<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\PostingBatch;
use App\Models\SalaryEntry;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyValues;
use App\Services\Phase7\OwnsPhase7Event;
use App\Services\Phase7\Phase7EventOwner;
use App\Services\Phase7\Phase7History;
use App\Services\Sales\SalesDocumentRules;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class ReverseSalaryEntryAction implements Phase7EventOwner
{
    use OwnsPhase7Event;

    public function execute(SalaryEntry $salaryEntry, User $actor, ?string $reason = null, ?string $businessDate = null): SalaryEntry
    {
        $reason = MoneyValues::text($reason, 500);
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $salaryEntry->company_id) {
            throw new NoActiveCompanyException("Active company context does not match salary entry company [{$salaryEntry->company_id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('Actor does not match authenticated user.');
        }

        if (! $actor->belongsToCompany($salaryEntry->company_id)) {
            throw new AuthorizationException("User does not belong to company [{$salaryEntry->company_id}].");
        }

        setPermissionsTeamId($salaryEntry->company_id);
        if (! $actor->hasPermissionTo('payroll.salary.reverse')) {
            throw new AuthorizationException('User does not have permission to reverse salary entries.');
        }

        return $this->canonicalTransaction((int) $salaryEntry->company_id, $actor, function () use ($salaryEntry, $actor, $reason, $businessDate): SalaryEntry {
            $lockedCompany = Company::where('id', $salaryEntry->company_id)->lockForUpdate()->firstOrFail();
            app(MoneyActorGuard::class)->authorize((int) $lockedCompany->id, 'payroll.salary.reverse');

            $lockedEntry = SalaryEntry::where('id', $salaryEntry->id)->lockForUpdate()->firstOrFail();

            app(Phase7History::class)->validate($lockedEntry);

            if ($lockedEntry->status === 'reversed') {
                app(Phase7History::class)->validate($lockedEntry);

                return $lockedEntry->load(['employee', 'advanceAllocations', 'postingBatch', 'reversalPostingBatch']);
            }

            // Blocker check: active salary payments must be reversed first
            $hasActivePayments = $lockedEntry->paymentAllocations()
                ->where('status', 'active')
                ->exists();

            if ($hasActivePayments) {
                throw new InvalidArgumentException('Cannot reverse salary entry with active salary payments. Reverse salary payments first.');
            }

            $reversalDate = $businessDate ?? Carbon::now($lockedCompany->timezone)->toDateString();
            app(SalesDocumentRules::class)->date($reversalDate);

            if ($reversalDate < Carbon::parse($lockedEntry->recognition_date)->toDateString()) {
                throw new InvalidArgumentException('Salary entry reversal date cannot precede original recognition date.');
            }
            foreach ($lockedEntry->paymentAllocations()->get() as $released) {
                $parent = $released->salaryPayment()->first();
                if ($parent === null) {
                    throw new InvalidArgumentException('Missing dependent financial provenance.');
                }
                app(Phase7History::class)->validate($parent);
                $inverse = $parent->reversalPostingBatch;
                if ($inverse !== null && $inverse->posting_date->toDateString() > $reversalDate) {
                    throw new InvalidArgumentException('Reversal cannot precede dependent financial release.');
                }
            }

            // Restore advances by marking advance allocations reversed
            foreach ($lockedEntry->advanceAllocations()->where('status', 'active')->lockForUpdate()->get() as $allocation) {
                $allocation->fill([
                    'status' => 'reversed',
                    'reversed_at' => now(),
                ]);
                $this->persistPhase7($allocation);
            }

            $reversalBatchId = null;
            if ($lockedEntry->posting_batch_id !== null && $lockedEntry->posting_batch_id > 0) {
                $batch = PostingBatch::where('company_id', $lockedCompany->id)->findOrFail($lockedEntry->posting_batch_id);
                $reversalBatch = $this->reversePhase7($batch, $actor, $reason, $reversalDate);
                $reversalBatchId = (int) $reversalBatch->id;
            }

            $lockedEntry->fill([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => (int) $actor->id,
                'reversal_posting_batch_id' => $reversalBatchId,
                'reversal_reason' => $reason,
            ]);
            $this->persistPhase7($lockedEntry);

            app(AuditService::class)->log(
                (int) $lockedCompany->id,
                'salary_entry.reversed',
                "Reversed salary entry {$lockedEntry->salary_number}",
                (int) $actor->id,
                $lockedEntry,
                meta: [
                    'salary_entry_id' => $lockedEntry->id,
                    'salary_number' => $lockedEntry->salary_number,
                    'reversal_batch_id' => $reversalBatchId,
                    'reversal_reason' => $reason,
                ]
            );

            app(Phase7History::class)->validate($lockedEntry);

            return $lockedEntry->load(['employee', 'advanceAllocations', 'postingBatch', 'reversalPostingBatch']);
        });
    }
}
