<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesInvoiceLotAllocation;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\SalesReturnLotAllocation;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/** Read-only event linkage checks; existing provenance/replay checks remain authoritative. */
final class StockAccountingAudit
{
    /** @param Collection<int,StockMovement> $movements
     * @return list<string> */
    public function discrepancies(int $companyId, Collection $movements, ?int $inventoryAccountId): array
    {
        $issues = [];
        $batches = PostingBatch::withoutGlobalScopes()->where('company_id', $companyId)->get()->keyBy('id');
        $bySource = $batches->keyBy(fn ($batch) => $batch->source_type.':'.$batch->source_id);
        $gl = [];
        if ($inventoryAccountId !== null) {
            foreach (PostingLine::withoutGlobalScopes()->where('company_id', $companyId)
                ->where('ledger_account_id', $inventoryAccountId)->get() as $line) {
                $id = (int) $line->posting_batch_id;
                $gl[$id] = ($gl[$id] ?? BigDecimal::zero())->plus($line->debit_base)->minus($line->credit_base);
            }
        }
        $models = [
            'sales_invoice' => [SalesInvoice::class, SalesInvoiceLine::class, 'sales_invoice_id', StockMovement::TYPE_SALE],
            'sales_return' => [SalesReturn::class, SalesReturnLine::class, 'sales_return_id', StockMovement::TYPE_SALE_RETURN],
            'purchase' => [Purchase::class, PurchaseLine::class, 'purchase_id', StockMovement::TYPE_PURCHASE],
            'purchase_return' => [PurchaseReturn::class, PurchaseReturnLine::class, 'purchase_return_id', StockMovement::TYPE_PURCHASE_RETURN],
        ];
        $documents = $lines = $documentProducts = [];
        foreach ($models as $source => [$model, $lineModel]) {
            $documents[$source] = $model::withoutGlobalScopes()->where('company_id', $companyId)->get()->keyBy('id');
            $lines[$source] = $lineModel::withoutGlobalScopes()->where('company_id', $companyId)->get()->keyBy('id');
            foreach ($lines[$source] as $line) {
                $documentProducts[$source][(int) $line->getAttribute($models[$source][2])][(int) $line->product_id] = true;
            }
            foreach ($documents[$source] as $document) {
                if (! in_array($document->status, ['posted', 'void'], true)) {
                    continue;
                }
                $allZeroReturn = $source === 'purchase_return'
                    && BigDecimal::of($document->grand_total_currency)->isZero()
                    && BigDecimal::of($document->grand_total_base)->isZero();
                $batch = $batches->get($document->posting_batch_id);
                if ($batch === null) {
                    if (! $allZeroReturn || $document->posting_batch_id !== null) {
                        $issues[] = "Canonical document [{$source}:{$document->id}] has missing accounting recognition.";
                    }
                } elseif ($batch->source_type !== $source || (int) $batch->source_id !== (int) $document->id
                    || $batch->status !== ($document->status === 'void' ? PostingBatch::STATUS_REVERSED : PostingBatch::STATUS_POSTED)) {
                    $issues[] = "Canonical document [{$source}:{$document->id}] has inconsistent accounting source or lifecycle.";
                }
                if ($document->status === 'void') {
                    $reversal = $batches->get($document->void_posting_batch_id);
                    if ($batch === null || $reversal === null || $reversal->source_type !== 'reversal'
                        || $reversal->status !== PostingBatch::STATUS_POSTED
                        || (int) $reversal->reversal_of_id !== (int) $batch->id
                        || (int) $batch->reversed_by_batch_id !== (int) $reversal->id) {
                        $issues[] = "Canonical document [{$source}:{$document->id}] has missing or inconsistent void accounting.";
                    }
                }
            }
        }
        $allocations = [
            'sales_invoice' => SalesInvoiceLotAllocation::withoutGlobalScopes()->where('company_id', $companyId)->get()->keyBy('stock_movement_id'),
            'sales_return' => SalesReturnLotAllocation::withoutGlobalScopes()->where('company_id', $companyId)->get()->keyBy('stock_movement_id'),
        ];
        $inventorySources = [
            'opening_stock' => [StockMovement::TYPE_OPENING_BALANCE],
            'stock_adjustment' => [StockMovement::TYPE_ADJUSTMENT_INCREASE, StockMovement::TYPE_ADJUSTMENT_DECREASE, StockMovement::TYPE_DAMAGE_OR_LOSS],
            'expiry_disposal' => [StockMovement::TYPE_EXPIRY_DISPOSAL],
        ];
        foreach ($batches->groupBy(fn ($batch) => $batch->source_type.':'.$batch->source_id) as $sourceKey => $group) {
            if (isset($inventorySources[$group->first()->source_type]) && $group->count() !== 1) {
                $issues[] = "Inventory event [{$sourceKey}] has duplicate accounting recognition.";
            }
        }
        $expected = $movementSources = [];
        foreach ($movements as $movement) {
            $movementSources[$movement->source_type.':'.$movement->id] = true;
            if (in_array($movement->movement_type, [StockMovement::TYPE_TRANSFER_OUT, StockMovement::TYPE_TRANSFER_IN], true)) {
                continue; // Pair, ownership, quantity and value conservation are checked by Check G.
            }
            $source = (string) $movement->source_type;
            $batch = null;
            $document = null;
            $isVoid = in_array($source, ['sales_invoice_void', 'sales_return_void'], true);
            $baseSource = $isVoid ? substr($source, 0, -5) : $source;
            if (isset($inventorySources[$source])) {
                if ((int) $movement->source_id !== $companyId || ! in_array($movement->movement_type, $inventorySources[$source], true)) {
                    $issues[] = "Movement [{$movement->id}] has invalid canonical inventory source.";
                }
                $batch = $bySource->get($source.':'.$movement->id);
            } elseif (isset($models[$baseSource])) {
                [, , $foreignKey, $type] = $models[$baseSource];
                $document = $documents[$baseSource]->get($movement->source_id);
                $allowedStatus = $isVoid ? ['void'] : (str_starts_with($source, 'sales_') ? ['posted', 'void'] : ['posted']);
                $expectedType = $isVoid ? ($type === StockMovement::TYPE_SALE ? StockMovement::TYPE_SALE_RETURN : StockMovement::TYPE_SALE) : $type;
                if ($document === null || ! in_array($document->status, $allowedStatus, true) || $movement->movement_type !== $expectedType) {
                    $issues[] = "Movement [{$movement->id}] has orphan or invalid canonical document source [{$source}].";

                    continue;
                }
                $sourceLines = $lines[$baseSource];
                $matchingLine = $movement->source_line_id !== null ? $sourceLines->get($movement->source_line_id) : null;
                if ($movement->source_line_id !== null && ($matchingLine === null
                    || (int) $matchingLine->getAttribute($foreignKey) !== (int) $document->id
                    || (int) $matchingLine->product_id !== (int) $movement->product_id)) {
                    $issues[] = "Movement [{$movement->id}] has invalid same-company source line.";
                }
                if (! isset($documentProducts[$baseSource][(int) $document->id][(int) $movement->product_id])) {
                    $issues[] = "Movement [{$movement->id}] product is absent from its canonical document.";
                }
                if (! $isVoid && isset($allocations[$baseSource])) {
                    $allocation = $allocations[$baseSource]->get($movement->id);
                    if ($allocation === null || (int) $allocation->getAttribute($foreignKey) !== (int) $document->id
                        || ! BigDecimal::of($allocation->quantity_allocated_base)->isEqualTo(BigDecimal::of($movement->quantity_delta_base)->abs())
                        || ! BigDecimal::of($allocation->total_cost_base)->isEqualTo(BigDecimal::of($movement->value_delta_base)->abs())
                        || $allocation->inventory_lot_id !== $movement->lot_id) {
                        $issues[] = "Movement [{$movement->id}] has missing or inconsistent immutable Sales allocation.";
                    }
                }
                $batch = $batches->get($isVoid ? $document->void_posting_batch_id : $document->posting_batch_id);
                if ($batch !== null) {
                    if ($isVoid) {
                        $original = $batches->get($document->posting_batch_id);
                        if ($batch->source_type !== 'reversal' || (int) $batch->reversal_of_id !== (int) $document->posting_batch_id
                            || $original === null || $original->status !== PostingBatch::STATUS_REVERSED
                            || (int) $original->reversed_by_batch_id !== (int) $batch->id) {
                            $issues[] = "Movement [{$movement->id}] has invalid canonical reversal linkage.";
                        }
                    } elseif ($batch->source_type !== $baseSource || (int) $batch->source_id !== (int) $document->id) {
                        $issues[] = "Movement [{$movement->id}] accounting source differs from its canonical document.";
                    }
                }
            } else {
                $issues[] = "Movement [{$movement->id}] has unrecognized economic source [{$source}].";

                continue;
            }
            $value = BigDecimal::of((string) $movement->value_delta_base);
            if ($batch === null) {
                $requiresBatch = $document !== null && ($baseSource !== 'purchase_return'
                    || ! BigDecimal::of($document->grand_total_currency)->isZero()
                    || ! BigDecimal::of($document->grand_total_base)->isZero());
                if (! $value->isZero() || $requiresBatch) {
                    $issues[] = "Movement [{$movement->id}] has missing canonical accounting recognition.";
                }

                continue; // Valid zero-cost inventory/return events need no zero-amount GL batch.
            }
            if (! in_array($batch->status, [PostingBatch::STATUS_POSTED, PostingBatch::STATUS_REVERSED], true)
                || ($batch->status === PostingBatch::STATUS_REVERSED && ! str_starts_with($baseSource, 'sales_'))) {
                $issues[] = "Movement [{$movement->id}] accounting lifecycle is invalid.";
            }
            $id = (int) $batch->id;
            $expected[$id] = ($expected[$id] ?? BigDecimal::zero())->plus($value);
        }
        foreach ($expected as $id => $value) {
            if (! ($gl[$id] ?? BigDecimal::zero())->isEqualTo($value)) {
                $issues[] = "Stock event posting batch [{$id}] inventory GL effect does not match immutable stock valuation.";
            }
        }
        foreach ($batches as $batch) {
            $source = (string) $batch->source_type;
            if (isset($models[$source])) {
                $document = $documents[$source]->get($batch->source_id);
                $sales = str_starts_with($source, 'sales_');
                if ($document === null || ! in_array($document->status, $sales ? ['posted', 'void'] : ['posted'], true)
                    || (int) $document->posting_batch_id !== (int) $batch->id) {
                    $issues[] = "Posting batch [{$batch->id}] has orphan or inconsistent canonical document source [{$source}].";
                } else {
                    $void = $sales && $document->status === 'void';
                    $reversal = $void ? $batches->get($document->void_posting_batch_id) : null;
                    if ($batch->status !== ($void ? PostingBatch::STATUS_REVERSED : PostingBatch::STATUS_POSTED)
                        || ($void && ($reversal === null || $reversal->source_type !== 'reversal'
                            || (int) $reversal->reversal_of_id !== (int) $batch->id
                            || (int) $batch->reversed_by_batch_id !== (int) $reversal->id))) {
                        $issues[] = "Posting batch [{$batch->id}] lifecycle differs from its canonical document.";
                    }
                    if (! ($gl[$batch->id] ?? BigDecimal::zero())->isEqualTo($expected[$batch->id] ?? BigDecimal::zero())) {
                        $issues[] = "Posting batch [{$batch->id}] inventory GL effect has no matching canonical stock valuation.";
                    }
                    if (! $sales && ! isset($expected[$batch->id])) {
                        $issues[] = "Purchasing posting batch [{$batch->id}] has no canonical stock source.";
                    }
                }
            }
            if (isset($inventorySources[$batch->source_type]) && ! isset($movementSources[$batch->source_type.':'.$batch->source_id])) {
                $issues[] = "Inventory posting batch [{$batch->id}] has no canonical stock source.";
            }
        }

        return array_values(array_unique($issues));
    }
}
