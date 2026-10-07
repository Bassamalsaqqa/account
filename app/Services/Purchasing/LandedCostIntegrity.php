<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\Expense;
use App\Models\LandedCostAllocation;
use App\Models\Purchase;
use App\Services\Phase7\Phase7History;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class LandedCostIntegrity
{
    public function validate(Purchase $purchase, bool $posted): void
    {
        $cid = (int) $purchase->company_id;
        $lines = $purchase->lines()->withoutGlobalScopes()->with('product')->get()->keyBy('id');
        $rows = LandedCostAllocation::withoutGlobalScopes()->where('purchase_id', $purchase->id)
            ->whereIn('status', ['draft', 'locked'])->get();
        $totals = [];
        foreach ($rows as $row) {
            $line = $lines->get($row->purchase_line_id);
            $this->require((int) $row->company_id === $cid && $line !== null && (int) $line->company_id === $cid
                && (int) $line->purchase_id === (int) $purchase->id && $line->product !== null
                && (int) $line->product->company_id === $cid && ($posted ? $line->stock_movement_id !== null : $line->product->track_stock), 'Line ownership/eligibility');
            $this->require($row->status === ($posted ? 'locked' : 'draft') && ($posted ? $row->locked_at !== null : $row->locked_at === null)
                && $row->cancelled_at === null && in_array($row->allocation_method, ['value', 'quantity', 'manual'], true), 'Allocation lifecycle');
            $amount = BigDecimal::of($row->allocated_base);
            $this->require(! $amount->isNegative(), 'Nonnegative allocation');
            $totals[$line->id] = ($totals[$line->id] ?? BigDecimal::zero())->plus($amount);
        }
        foreach ($rows->groupBy('expense_id') as $id => $parts) {
            $expense = Expense::where('company_id', $cid)->findOrFail($id);
            app(Phase7History::class)->validate($expense);
            $this->require($expense->status === 'posted' && $expense->classification === 'landed_cost'
                && $expense->expense_date->lte($purchase->purchase_date), 'Eligible expense/chronology');
            $all = LandedCostAllocation::withoutGlobalScopes()->where('expense_id', $id)->whereIn('status', ['draft', 'locked'])->get();
            $sum = BigDecimal::zero();
            $seen = [];
            foreach ($all as $part) {
                $this->require((int) $part->company_id === $cid && (int) $part->purchase_id === (int) $purchase->id
                    && ! isset($seen[$part->purchase_line_id]), 'Single Purchase and distinct lines');
                $seen[$part->purchase_line_id] = true;
                $sum = $sum->plus($part->allocated_base);
            }
            $this->require($sum->isEqualTo($expense->base_amount), 'Exact full expense allocation');
        }
        if ($posted) {
            foreach ($lines as $line) {
                $this->require(BigDecimal::of($line->landed_cost_allocated_base ?? '0')->isEqualTo($totals[$line->id] ?? '0'), 'Frozen per-line acquisition allocation');
            }
        }
    }

    private function require(bool $condition, string $rule): void
    {
        if (! $condition) {
            throw new InvalidArgumentException('Landed Cost integrity: '.$rule);
        }
    }
}
