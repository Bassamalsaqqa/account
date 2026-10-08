<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Models\Expense;
use App\Models\LandedCostAllocation;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Purchasing\PurchaseDraftBuilder;
use App\Services\Purchasing\PurchaseDraftWriter;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;

final class UpdatePurchaseDraftAction
{
    /** @param array<string, mixed> $data */
    public function execute(Purchase $purchase, User $actor, array $data): Purchase
    {
        return DB::transaction(function () use ($purchase, $actor, $data): Purchase {
            $guard = app(SalesActorGuard::class);
            $company = $guard->lockAndAuthorize((int) $purchase->company_id, $actor, 'purchasing.purchase.edit_draft');
            $guard->lockAndAuthorize((int) $company->id, $actor, 'purchasing.cost.view');
            $locked = Purchase::where('company_id', $company->id)->lockForUpdate()->findOrFail($purchase->id);
            $locked->assertMutableDraft();
            $before = $locked->only(['vendor_id', 'warehouse_id', 'currency_code']);
            $draft = app(PurchaseDraftBuilder::class)->prepare($company, $data, $locked);
            $locked->fill($draft->header)->fill(['updated_by' => $actor->id])->save();

            // Cancelled draft plans are nonfinancial editable metadata; preserve their audit before replacing line IDs.
            $cancelled = LandedCostAllocation::where('company_id', $company->id)->where('purchase_id', $locked->id)->where('status', 'cancelled')->get();
            if ($cancelled->isNotEmpty()) {
                app(AuditService::class)->log($company->id, 'purchasing.landed_cost.cancelled_plans_removed', 'Retired cancelled draft plans before line replacement', $actor->id, $locked,
                    meta: ['plans' => $cancelled->toArray()]);
                foreach ($cancelled as $plan) {
                    $plan->delete();
                }
            }
            $existingAllocations = LandedCostAllocation::where('company_id', $company->id)
                ->where('purchase_id', $locked->id)
                ->where('status', LandedCostAllocation::STATUS_DRAFT)
                ->lockForUpdate()
                ->get();

            if ($existingAllocations->isNotEmpty()) {
                $byExpense = $existingAllocations->groupBy('expense_id');
                $expensePlans = [];
                foreach ($byExpense as $expenseId => $allocGroup) {
                    $method = $allocGroup->first()->allocation_method;
                    if ($method === LandedCostAllocation::METHOD_MANUAL) {
                        $manualReplacement = $data['landed_cost_manual_allocations'][$expenseId] ?? null;
                        if (! is_array($manualReplacement)) {
                            throw new \InvalidArgumentException('Draft purchase has manual landed cost allocations that must be explicitly replaced or removed before editing lines.');
                        }
                        $expensePlans[$expenseId] = ['method' => $method, 'manual' => $manualReplacement];
                    } else {
                        $expensePlans[$expenseId] = ['method' => $method, 'manual' => []];
                    }
                }

                foreach ($existingAllocations as $alloc) {
                    $alloc->delete();
                }

                app(PurchaseDraftWriter::class)->replaceLines($locked, $draft);

                $allocAction = app(AllocateLandedCostAction::class);
                foreach ($expensePlans as $expenseId => $plan) {
                    $expense = Expense::where('company_id', $company->id)->lockForUpdate()->findOrFail($expenseId);
                    $manual = [];
                    if ($plan['method'] === LandedCostAllocation::METHOD_MANUAL) {
                        $newLines = $locked->lines()->get()->keyBy('line_number');
                        foreach ($plan['manual'] as $number => $amount) {
                            $line = $newLines->get((int) $number);
                            if ($line === null) {
                                throw new \InvalidArgumentException('Manual replacement references an unknown new line number.');
                            }
                            $manual[(int) $line->id] = $amount;
                        }
                    }
                    $allocAction->execute($locked, $expense, $plan['method'], $actor, $manual);
                }
            } else {
                app(PurchaseDraftWriter::class)->replaceLines($locked, $draft);
            }

            app(AuditService::class)->log($company->id, 'purchase.draft.updated', 'Purchase draft updated', $actor->id, $locked, $before, $locked->only(array_keys($before)));

            return $locked->fresh(['lines.lots']);
        });
    }
}
