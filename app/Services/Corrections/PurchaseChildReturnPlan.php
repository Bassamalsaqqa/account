<?php

declare(strict_types=1);

namespace App\Services\Corrections;

use App\Actions\Purchasing\VoidPurchaseReturnAction;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\User;
use App\Services\Sales\SalesActorGuard;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseChildReturnPlan
{
    /** @return array{returns: list<array<string, mixed>>, blockers: list<string>} */
    public function inspect(Purchase $purchase, User $actor): array
    {
        $children = PurchaseReturn::where('company_id', $purchase->company_id)->where('purchase_id', $purchase->id)->where('status', '!=', 'void')->orderBy('id')->limit(2001)->get();
        if ($children->count() > 2000) {
            throw ValidationException::withMessages(['correction' => __('corrections.graph_too_large')]);
        }
        $returns = [];
        $blockers = [];
        foreach ($children as $child) {
            if (! $actor->can('purchasing.return.void')) {
                $blockers[] = __('corrections.dependency_restricted');

                continue;
            }
            app(SalesActorGuard::class)->lockAndAuthorize((int) $purchase->company_id, $actor, 'purchasing.return.void');
            if ($child->isDraft()) {
                $blockers[] = __('corrections.draft_return', ['number' => $child->return_number ?? $child->public_id]);

                continue;
            }
            foreach (app(VoidPurchaseReturnAction::class)->inspectBlockers($child) as $blocker) {
                $blockers[] = __($blocker['translation_key'], $blocker['translation_params']);
            }
            $returns[] = ['id' => (int) $child->id, 'number' => $child->return_number, 'amount' => (string) $child->grand_total_currency, 'currency' => $child->currency_code, 'stock_rows' => app(CorrectionStockEffects::class)->rows($child)];
        }

        return compact('returns', 'blockers');
    }

    /**
     * Check a root stock shortage against exact stock its selected child reversals restore.
     *
     * @param  array<string, mixed>  $blocker
     * @param  list<array<string, mixed>>  $children
     */
    public function resolvedByChildren(Purchase $purchase, array $blocker, array $children): bool
    {
        if ($children === []) {
            return false;
        }
        $code = $blocker['code'];
        if (! in_array($code, ['insufficient_warehouse_stock', 'insufficient_company_stock', 'carrying_value_mismatch', 'insufficient_lot_stock'], true)) {
            return false;
        }
        $q = DB::table('purchase_return_allocations as a')->join('purchase_return_lines as l', function ($join) {
            $join->on('l.id', '=', 'a.purchase_return_line_id')->on('l.company_id', '=', 'a.company_id');
        })->where('a.company_id', $purchase->company_id)->whereIn('a.purchase_return_id', array_column($children, 'id'));
        if ($code === 'insufficient_lot_stock') {
            $q->where('a.inventory_lot_id', $blocker['details']['lot_id']);
        } else {
            $q->where('l.product_id', $blocker['details']['product_id']);
        }
        $restored = BigDecimal::zero();
        foreach ($q->get(['a.quantity_base', 'a.inventory_value_removed_base']) as $allocation) {
            $restored = $restored->plus((string) ($code === 'carrying_value_mismatch' ? $allocation->inventory_value_removed_base : $allocation->quantity_base));
        }
        $available = $blocker['details'][$code === 'carrying_value_mismatch' ? 'current_value' : 'available'];
        $required = $blocker['details'][$code === 'carrying_value_mismatch' ? 'receipt_value' : 'required'];

        return BigDecimal::of((string) $available)->plus($restored)->isGreaterThanOrEqualTo((string) $required);
    }
}
