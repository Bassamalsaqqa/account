<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Expense;
use App\Models\LandedCostAllocation;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RemoveLandedCostAllocationAction
{
    public function execute(Purchase $purchase, Expense $expense, User $actor): void
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $purchase->company_id) {
            throw new NoActiveCompanyException("Active company context does not match purchase company [{$purchase->company_id}].");
        }

        if ((int) $purchase->company_id !== (int) $expense->company_id) {
            throw new InvalidArgumentException('Purchase and expense must belong to the same company.');
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('Actor does not match authenticated user.');
        }

        if (! $actor->belongsToCompany($purchase->company_id)) {
            throw new AuthorizationException("User does not belong to company [{$purchase->company_id}].");
        }

        DB::transaction(function () use ($purchase, $expense, $actor): void {
            $guard = app(SalesActorGuard::class);
            $company = $guard->lockAndAuthorize((int) $purchase->company_id, $actor, 'purchasing.landed_cost.manage');
            $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.cost.view');

            $lockedPurchase = Purchase::where('company_id', $company->id)->lockForUpdate()->findOrFail($purchase->id);
            $lockedPurchase->assertMutableDraft();

            $lockedExpense = Expense::where('company_id', $company->id)->lockForUpdate()->findOrFail($expense->id);

            // Delete only draft allocations
            $draftAllocations = LandedCostAllocation::where('company_id', $company->id)
                ->where('purchase_id', $lockedPurchase->id)
                ->where('expense_id', $lockedExpense->id)
                ->where('status', LandedCostAllocation::STATUS_DRAFT)
                ->get();

            foreach ($draftAllocations as $allocation) {
                $allocation->delete();
            }

            app(AuditService::class)->log(
                $company->id,
                'purchasing.landed_cost.removed',
                'Landed cost allocation removed from purchase draft',
                $actor->id,
                $lockedPurchase,
                null,
                ['expense_id' => $lockedExpense->id]
            );
        });
    }
}
