<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\EmployeeAdvance;
use App\Models\PostingBatch;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyEventScope;
use App\Services\Money\MoneyValues;
use App\Services\Phase7\OwnsPhase7Event;
use App\Services\Phase7\Phase7EventOwner;
use App\Services\Phase7\Phase7History;
use App\Services\Sales\SalesDocumentRules;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class ReverseEmployeeAdvanceAction implements Phase7EventOwner
{
    use OwnsPhase7Event;

    public function execute(EmployeeAdvance $advance, User $actor, ?string $reason = null, ?string $businessDate = null): EmployeeAdvance
    {
        $reason = MoneyValues::text($reason, 500);
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $advance->company_id) {
            throw new NoActiveCompanyException("Active company context does not match advance company [{$advance->company_id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('Actor does not match authenticated user.');
        }

        if (! $actor->belongsToCompany($advance->company_id)) {
            throw new AuthorizationException("User does not belong to company [{$advance->company_id}].");
        }

        setPermissionsTeamId($advance->company_id);
        if (! $actor->hasPermissionTo('payroll.advance.manage')) {
            throw new AuthorizationException('User does not have permission to reverse employee advances.');
        }

        return $this->canonicalTransaction((int) $advance->company_id, $actor, function () use ($advance, $actor, $reason, $businessDate): EmployeeAdvance {
            $lockedCompany = Company::where('id', $advance->company_id)->lockForUpdate()->firstOrFail();
            app(MoneyActorGuard::class)->authorize((int) $lockedCompany->id, 'payroll.advance.manage');

            $lockedAdvance = EmployeeAdvance::where('id', $advance->id)->lockForUpdate()->firstOrFail();

            if ($lockedAdvance->check_id !== null) {
                app(MoneyEventScope::class)->assertCheckTransition((int) $lockedAdvance->check_id, (int) $lockedCompany->id, $actor);
            }

            app(Phase7History::class)->validate($lockedAdvance);

            if ($lockedAdvance->status === 'reversed') {
                app(Phase7History::class)->validate($lockedAdvance);

                return $lockedAdvance->load(['employee', 'moneyAccount', 'postingBatch', 'reversalPostingBatch']);
            }

            if ($lockedAdvance->posting_batch_id === null || $lockedAdvance->posting_batch_id === 0) {
                throw new ImmutableRecordException('Cannot reverse unposted employee advance.');
            }

            // Blocker check: Cannot reverse if consumed by an active salary entry
            $hasActiveAllocations = $lockedAdvance->allocations()
                ->where('status', 'active')
                ->exists();

            if ($hasActiveAllocations) {
                throw new InvalidArgumentException('Cannot reverse an employee advance consumed by active salary entries.');
            }

            $reversalDate = $businessDate ?? Carbon::now($lockedCompany->timezone)->toDateString();
            app(SalesDocumentRules::class)->date($reversalDate);

            if ($reversalDate < Carbon::parse($lockedAdvance->advance_date)->toDateString()) {
                throw new InvalidArgumentException('Advance reversal date cannot precede original advance date.');
            }
            foreach ($lockedAdvance->salaryAdvanceAllocations()->get() as $released) {
                $parent = $released->salaryEntry()->first();
                if ($parent === null) {
                    throw new InvalidArgumentException('Missing dependent financial provenance.');
                }
                app(Phase7History::class)->validate($parent);
                $inverse = $parent->reversalPostingBatch;
                if ($inverse !== null && $inverse->posting_date->toDateString() > $reversalDate) {
                    throw new InvalidArgumentException('Reversal cannot precede dependent financial release.');
                }
            }

            $batch = PostingBatch::where('company_id', $lockedCompany->id)->findOrFail($lockedAdvance->posting_batch_id);
            $reversalBatch = $this->reversePhase7($batch, $actor, $reason, $reversalDate);

            $lockedAdvance->fill([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => (int) $actor->id,
                'reversal_posting_batch_id' => (int) $reversalBatch->id,
                'reversal_reason' => $reason,
            ]);
            $this->persistPhase7($lockedAdvance);

            app(AuditService::class)->log(
                (int) $lockedCompany->id,
                'employee_advance.reversed',
                "Reversed employee advance {$lockedAdvance->advance_number}",
                (int) $actor->id,
                $lockedAdvance,
                meta: [
                    'advance_id' => $lockedAdvance->id,
                    'advance_number' => $lockedAdvance->advance_number,
                    'reversal_batch_id' => $reversalBatch->id,
                    'reversal_reason' => $reason,
                ]
            );

            app(Phase7History::class)->validate($lockedAdvance);

            return $lockedAdvance->load(['employee', 'moneyAccount', 'postingBatch', 'reversalPostingBatch']);
        });
    }
}
