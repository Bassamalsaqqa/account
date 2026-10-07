<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\PostingBatch;
use App\Models\SalaryPayment;
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

final class ReverseSalaryPaymentAction implements Phase7EventOwner
{
    use OwnsPhase7Event;

    public function execute(SalaryPayment $payment, User $actor, ?string $reason = null, ?string $businessDate = null): SalaryPayment
    {
        $reason = MoneyValues::text($reason, 500);
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $payment->company_id) {
            throw new NoActiveCompanyException("Active company context does not match payment company [{$payment->company_id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('Actor does not match authenticated user.');
        }

        if (! $actor->belongsToCompany($payment->company_id)) {
            throw new AuthorizationException("User does not belong to company [{$payment->company_id}].");
        }

        setPermissionsTeamId($payment->company_id);
        if (! $actor->hasPermissionTo('payroll.salary.reverse')) {
            throw new AuthorizationException('User does not have permission to reverse salary payments.');
        }

        return $this->canonicalTransaction((int) $payment->company_id, $actor, function () use ($payment, $actor, $reason, $businessDate): SalaryPayment {
            $lockedCompany = Company::where('id', $payment->company_id)->lockForUpdate()->firstOrFail();
            app(MoneyActorGuard::class)->authorize((int) $lockedCompany->id, 'payroll.salary.reverse');

            $lockedPayment = SalaryPayment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->check_id !== null) {
                app(MoneyEventScope::class)->assertCheckTransition((int) $lockedPayment->check_id, (int) $lockedCompany->id, $actor);
            }

            app(Phase7History::class)->validate($lockedPayment);

            if ($lockedPayment->status === 'reversed') {
                app(Phase7History::class)->validate($lockedPayment);

                return $lockedPayment->load(['employee', 'allocations', 'postingBatch', 'reversalPostingBatch']);
            }

            if ($lockedPayment->posting_batch_id === null || $lockedPayment->posting_batch_id === 0) {
                throw new ImmutableRecordException('Cannot reverse unposted salary payment.');
            }

            $reversalDate = $businessDate ?? Carbon::now($lockedCompany->timezone)->toDateString();
            app(SalesDocumentRules::class)->date($reversalDate);

            if ($reversalDate < Carbon::parse($lockedPayment->payment_date)->toDateString()) {
                throw new InvalidArgumentException('Salary payment reversal date cannot precede original payment date.');
            }

            // Restore entries by marking payment allocations reversed
            foreach ($lockedPayment->allocations()->where('status', 'active')->lockForUpdate()->get() as $allocation) {
                $allocation->fill([
                    'status' => 'reversed',
                    'reversed_at' => now(),
                ]);
                $this->persistPhase7($allocation);
            }

            $batch = PostingBatch::where('company_id', $lockedCompany->id)->findOrFail($lockedPayment->posting_batch_id);
            $reversalBatch = $this->reversePhase7($batch, $actor, $reason, $reversalDate);

            $lockedPayment->fill([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => (int) $actor->id,
                'reversal_posting_batch_id' => (int) $reversalBatch->id,
                'reversal_reason' => $reason,
            ]);
            $this->persistPhase7($lockedPayment);

            app(AuditService::class)->log(
                (int) $lockedCompany->id,
                'salary_payment.reversed',
                "Reversed salary payment {$lockedPayment->payment_number}",
                (int) $actor->id,
                $lockedPayment,
                meta: [
                    'salary_payment_id' => $lockedPayment->id,
                    'payment_number' => $lockedPayment->payment_number,
                    'reversal_batch_id' => $reversalBatch->id,
                    'reversal_reason' => $reason,
                ]
            );

            app(Phase7History::class)->validate($lockedPayment);

            return $lockedPayment->load(['employee', 'allocations', 'postingBatch', 'reversalPostingBatch']);
        });
    }
}
