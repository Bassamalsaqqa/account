<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Expense;
use App\Models\LandedCostAllocation;
use App\Models\Purchase;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Phase7\Phase7History;
use App\Services\Purchasing\PurchaseAcquisitionValue;
use App\Services\Purchasing\PurchaseInputTaxAccount;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AllocateLandedCostAction
{
    /**
     * @param  array<int|string, mixed>  $manualAllocations  [line_id => amount]
     * @return Collection<int, LandedCostAllocation>
     */
    public function execute(
        Purchase $purchase,
        Expense $expense,
        string $method,
        User $actor,
        array $manualAllocations = []
    ): Collection {
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

        return DB::transaction(function () use ($purchase, $expense, $method, $actor, $manualAllocations): Collection {
            $guard = app(SalesActorGuard::class);
            $company = $guard->lockAndAuthorize((int) $purchase->company_id, $actor, 'purchasing.landed_cost.manage');
            $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.cost.view');

            setPermissionsTeamId($company->id);
            if (! $actor->hasPermissionTo('money.expense.view') && ! $actor->hasPermissionTo('money.expense.manage')) {
                throw new AuthorizationException('User does not have permission to view or manage expenses.');
            }

            $lockedPurchase = Purchase::where('company_id', $company->id)->lockForUpdate()->findOrFail($purchase->id);
            $lockedPurchase->assertMutableDraft();

            $lockedExpense = Expense::where('company_id', $company->id)->lockForUpdate()->findOrFail($expense->id);

            if ($lockedExpense->status !== Expense::STATUS_POSTED) {
                throw new InvalidArgumentException('Landed cost expense must be posted.');
            }

            if ($lockedExpense->reversed_at !== null) {
                throw new InvalidArgumentException('Reversed expense cannot be allocated as landed cost.');
            }

            if ($lockedExpense->classification !== Expense::CLASSIFICATION_LANDED_COST) {
                throw new InvalidArgumentException('Expense must have landed_cost classification.');
            }

            app(Phase7History::class)->validate($lockedExpense);

            // An expense cannot span purchases
            $otherPurchaseAllocations = LandedCostAllocation::where('company_id', $company->id)
                ->where('expense_id', $lockedExpense->id)
                ->where('purchase_id', '!=', $lockedPurchase->id)
                ->whereIn('status', [LandedCostAllocation::STATUS_DRAFT, LandedCostAllocation::STATUS_LOCKED])
                ->exists();

            if ($otherPurchaseAllocations) {
                throw new InvalidArgumentException('Expense is already allocated to another purchase.');
            }

            $lines = $lockedPurchase->lines()->with('product')->orderBy('line_number')->get();
            $eligibleLines = $lines->filter(fn ($l) => (bool) $l->product?->track_stock)->values();

            if ($eligibleLines->isEmpty()) {
                throw new InvalidArgumentException('Purchase has no stock-tracked lines eligible for landed cost allocation.');
            }

            $expenseBase = BigDecimal::of($lockedExpense->base_amount);
            if (! $expenseBase->isPositive()) {
                throw new InvalidArgumentException('Expense base amount must be positive.');
            }

            $allocations = [];

            if ($method === LandedCostAllocation::METHOD_VALUE) {
                $weights = [];
                $totalWeight = BigDecimal::zero();
                $taxAccounts = [];

                foreach ($eligibleLines as $line) {
                    $tax = $line->tax_rate_id === null ? null : TaxRate::where('company_id', $company->id)->where('active', true)->lockForUpdate()->findOrFail($line->tax_rate_id);
                    $taxAccounts[$line->id] = app(PurchaseInputTaxAccount::class)->resolve((int) $company->id, $tax?->purchase_tax_account_id)?->id;
                    $w = app(PurchaseAcquisitionValue::class)->commercial($line, $taxAccounts[$line->id]);
                    $weights[$line->id] = $w;
                    $totalWeight = $totalWeight->plus($w);
                }

                if (! $totalWeight->isPositive()) {
                    throw new InvalidArgumentException('Total commercial acquisition value of eligible lines must be positive to allocate by value.');
                }

                $allocatedSoFar = BigDecimal::zero();
                $count = $eligibleLines->count();

                foreach ($eligibleLines as $idx => $line) {
                    if ($idx === $count - 1) {
                        $part = $expenseBase->minus($allocatedSoFar);
                    } else {
                        $w = $weights[$line->id];
                        $part = BigDecimal::min($expenseBase->multipliedBy($w)->dividedBy($totalWeight, 6, RoundingMode::HALF_UP), $expenseBase->minus($allocatedSoFar));
                        $allocatedSoFar = $allocatedSoFar->plus($part);
                    }

                    if ($part->isNegative()) {
                        throw new InvalidArgumentException('Calculated allocation part cannot be negative.');
                    }

                    $allocations[$line->id] = $part->toScale(6);
                }
            } elseif ($method === LandedCostAllocation::METHOD_QUANTITY) {
                $totalWeight = BigDecimal::zero();

                foreach ($eligibleLines as $line) {
                    $totalWeight = $totalWeight->plus(BigDecimal::of($line->quantity_base));
                }

                if (! $totalWeight->isPositive()) {
                    throw new InvalidArgumentException('Total quantity of eligible lines must be positive to allocate by quantity.');
                }

                $allocatedSoFar = BigDecimal::zero();
                $count = $eligibleLines->count();

                foreach ($eligibleLines as $idx => $line) {
                    if ($idx === $count - 1) {
                        $part = $expenseBase->minus($allocatedSoFar);
                    } else {
                        $w = BigDecimal::of($line->quantity_base);
                        $part = BigDecimal::min($expenseBase->multipliedBy($w)->dividedBy($totalWeight, 6, RoundingMode::HALF_UP), $expenseBase->minus($allocatedSoFar));
                        $allocatedSoFar = $allocatedSoFar->plus($part);
                    }

                    if ($part->isNegative()) {
                        throw new InvalidArgumentException('Calculated allocation part cannot be negative.');
                    }

                    $allocations[$line->id] = $part->toScale(6);
                }
            } elseif ($method === LandedCostAllocation::METHOD_MANUAL) {
                $sum = BigDecimal::zero();
                $eligibleLineIds = $eligibleLines->pluck('id')->all();

                foreach ($manualAllocations as $lineId => $rawAmount) {
                    if (! in_array((int) $lineId, $eligibleLineIds, true)) {
                        throw new InvalidArgumentException("Line [{$lineId}] is not an eligible stock-tracked line for this purchase.");
                    }

                    if (isset($allocations[(int) $lineId]) || (! is_string($rawAmount) && ! $rawAmount instanceof BigDecimal)) {
                        throw new InvalidArgumentException('Distinct line IDs and exact decimal strings required.');
                    }
                    $amt = BigDecimal::of($rawAmount);
                    if ($amt->isNegative()) {
                        throw new InvalidArgumentException("Manual allocation for line [{$lineId}] cannot be negative.");
                    }
                    if ($amt->getScale() > 6) {
                        throw new InvalidArgumentException("Manual allocation for line [{$lineId}] scale cannot exceed 6.");
                    }

                    $sum = $sum->plus($amt);
                    $allocations[(int) $lineId] = $amt->toScale(6);
                }

                foreach ($eligibleLines as $line) {
                    if (! isset($allocations[$line->id])) {
                        $allocations[$line->id] = BigDecimal::zero()->toScale(6);
                    }
                }

                if (! $sum->isEqualTo($expenseBase)) {
                    throw new InvalidArgumentException("Sum of manual allocations [{$sum}] must equal expense base amount [{$expenseBase}].");
                }
            } else {
                throw new InvalidArgumentException("Invalid allocation method [{$method}].");
            }

            // Remove existing draft allocations for this expense on this purchase
            LandedCostAllocation::where('company_id', $company->id)
                ->where('purchase_id', $lockedPurchase->id)
                ->where('expense_id', $lockedExpense->id)
                ->where('status', LandedCostAllocation::STATUS_DRAFT)
                ->delete();

            $created = new Collection;
            foreach ($eligibleLines as $line) {
                $amount = $allocations[$line->id];
                $created->push(LandedCostAllocation::create([
                    'company_id' => $company->id,
                    'expense_id' => $lockedExpense->id,
                    'purchase_id' => $lockedPurchase->id,
                    'purchase_line_id' => $line->id,
                    'allocation_method' => $method,
                    'allocated_base' => (string) $amount,
                    'status' => LandedCostAllocation::STATUS_DRAFT,
                ]));
            }

            app(AuditService::class)->log(
                $company->id,
                'purchasing.landed_cost.allocated',
                'Landed cost allocated to purchase draft',
                $actor->id,
                $lockedPurchase,
                null,
                [
                    'expense_id' => $lockedExpense->id,
                    'method' => $method,
                    'total_base' => (string) $expenseBase,
                ]
            );

            return $created;
        });
    }
}
