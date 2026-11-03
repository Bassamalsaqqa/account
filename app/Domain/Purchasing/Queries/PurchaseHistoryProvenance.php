<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Queries;

use App\Models\PostingBatch;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class PurchaseHistoryProvenance
{
    /** Candidate filtering only; assertLines verifies persisted ownership below. */
    public static function receiptRecognitionFilter(): \Closure
    {
        return static fn ($query) => $query->whereNotNull('purchases.posting_batch_id')
            ->orWhere(fn ($zero) => $zero->whereNull('purchases.posting_batch_id')
                ->where('purchases.grand_total_currency', '0')->where('purchases.grand_total_base', '0'));
    }

    /**
     * Validate selected price-history ownership and original-batch metadata in one read.
     * Deep economic replay belongs to reconciliation and canonical posting retries.
     *
     * @param  Collection<int, PurchaseLine>  $lines
     */
    public function assertLines(Collection $lines, int $companyId): void
    {
        if ($lines->isEmpty()) {
            return;
        }

        foreach ($lines as $line) {
            $purchase = $line->purchase;
            if ((int) $line->company_id !== $companyId || $purchase === null
                || (int) $purchase->company_id !== $companyId
                || (int) $line->purchase_id !== (int) $purchase->id) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }
        }

        $validCount = PurchaseLine::withoutGlobalScopes()
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->leftJoin('posting_batches', 'posting_batches.id', '=', 'purchases.posting_batch_id')
            ->leftJoin('stock_movements as receipt', 'receipt.id', '=', 'purchase_lines.stock_movement_id')
            ->join('vendors', 'vendors.id', '=', 'purchases.vendor_id')
            ->join('products', 'products.id', '=', 'purchase_lines.product_id')
            ->join('product_units', 'product_units.id', '=', 'purchase_lines.product_unit_id')
            ->whereIn('purchase_lines.id', $lines->pluck('id'))
            ->where('purchase_lines.company_id', $companyId)
            ->where('purchases.company_id', $companyId)
            ->where('vendors.company_id', $companyId)
            ->where('products.company_id', $companyId)
            ->where('product_units.company_id', $companyId)
            ->whereColumn('product_units.product_id', 'purchase_lines.product_id')
            ->where('purchases.status', Purchase::STATUS_POSTED)
            ->whereNotNull('purchases.purchase_number')
            ->whereRaw("TRIM(purchases.purchase_number) <> ''")
            ->whereNotNull('purchases.posted_at')
            ->whereNotNull('purchases.posted_by')
            ->where(function ($query) use ($companyId): void {
                $query->where(fn ($batch) => $batch->where('posting_batches.company_id', $companyId)
                    ->where('posting_batches.source_type', 'purchase')->whereColumn('posting_batches.source_id', 'purchases.id')
                    ->where('posting_batches.status', PostingBatch::STATUS_POSTED))
                    ->orWhere(fn ($zero) => $zero->whereNull('purchases.posting_batch_id')
                        ->where('purchases.grand_total_base', '0')->where('purchases.grand_total_currency', '0')
                        ->where('purchase_lines.line_total_base', '0')->where('purchase_lines.line_total', '0')
                        ->where('purchase_lines.inventory_unit_cost_base', '0')->where('purchase_lines.landed_cost_allocated_base', '0')
                        ->where('receipt.company_id', $companyId)->where('receipt.source_type', 'purchase')
                        ->where('receipt.movement_type', StockMovement::TYPE_PURCHASE)
                        ->whereColumn('receipt.source_id', 'purchases.id')->whereColumn('receipt.source_line_id', 'purchase_lines.id')
                        ->whereColumn('receipt.product_id', 'purchase_lines.product_id')->whereColumn('receipt.warehouse_id', 'purchases.warehouse_id')
                        ->whereColumn('receipt.created_by', 'purchases.posted_by')->where('receipt.value_delta_base', '0')->where('receipt.unit_cost_base', '0'));
            })
            ->count();

        if ($validCount !== $lines->count()) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }
    }
}
