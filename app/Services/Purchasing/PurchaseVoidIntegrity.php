<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\PostingBatch;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class PurchaseVoidIntegrity
{
    public function assertComplete(Purchase|PurchaseReturn $document): void
    {
        $source = $document instanceof Purchase ? 'purchase' : 'purchase_return';
        $originals = StockMovement::withoutGlobalScopes()->where('company_id', $document->company_id)
            ->where('source_type', $source)->where('source_id', $document->id)->get();
        $inverses = StockMovement::withoutGlobalScopes()->where('company_id', $document->company_id)
            ->where('source_type', $source.'_void')->where('source_id', $document->id)->get();
        if ($originals->isEmpty() || $originals->count() !== $inverses->count() || $document->void_business_date === null) {
            throw new InvalidArgumentException('Incomplete historical purchase compensation set.');
        }
        $lines = $document->lines()->withoutGlobalScopes()->where('company_id', $document->company_id)->get();
        foreach ($lines as $line) {
            $movements = $originals->where('source_line_id', $line->id);
            $quantity = BigDecimal::zero();
            foreach ($movements as $movement) {
                if ((int) $movement->product_id !== (int) $line->product_id
                    || (int) $movement->warehouse_id !== (int) $document->warehouse_id) {
                    throw new InvalidArgumentException('Invalid original purchase movement ownership.');
                }
                $quantity = $quantity->plus(BigDecimal::of($movement->quantity_delta_base)->abs());
            }
            if (! $quantity->isEqualTo(BigDecimal::of($line->quantity_base))) {
                throw new InvalidArgumentException('Incomplete original purchase line quantity.');
            }
        }
        if ($originals->contains(fn ($movement) => ! $lines->contains('id', $movement->source_line_id))) {
            throw new InvalidArgumentException('Unknown original purchase source line.');
        }
        foreach ($originals as $original) {
            $delta = BigDecimal::of($original->quantity_delta_base);
            if ($original->movement_type !== $source || ($source === 'purchase' ? ! $delta->isPositive() : ! $delta->isNegative())) {
                throw new InvalidArgumentException('Invalid original purchase movement direction.');
            }
            $matches = $inverses->where('reversal_of_id', $original->id);
            if ($matches->count() !== 1) {
                throw new InvalidArgumentException('Missing or duplicate purchase compensation.');
            }
            $inverse = $matches->sole();
            foreach (['product_id', 'warehouse_id', 'lot_id', 'source_line_id', 'unit_id'] as $field) {
                if ($original->$field !== $inverse->$field) {
                    throw new InvalidArgumentException('Purchase compensation provenance mismatch.');
                }
            }
            foreach (['source_quantity', 'conversion_to_base'] as $field) {
                if (! BigDecimal::of($original->$field)->isEqualTo(BigDecimal::of($inverse->$field))) {
                    throw new InvalidArgumentException('Historical unit metadata mismatch.');
                }
            }
            foreach (['quantity_delta_base', 'value_delta_base'] as $field) {
                if (! BigDecimal::of($original->$field)->negated()->isEqualTo(BigDecimal::of($inverse->$field))) {
                    throw new InvalidArgumentException('Purchase compensation quantity/value mismatch.');
                }
            }
            if ($inverse->movement_date->format('Y-m-d') !== $document->void_business_date->format('Y-m-d')) {
                throw new InvalidArgumentException('Purchase compensation business date mismatch.');
            }
        }
        if ($document->posting_batch_id === null) {
            if ($document->void_posting_batch_id !== null || ! BigDecimal::of($document->grand_total_base)->isZero()
                || ! BigDecimal::of($document->grand_total_currency)->isZero()
                || $originals->contains(fn ($movement) => ! BigDecimal::of($movement->value_delta_base)->isZero())) {
                throw new InvalidArgumentException('Invalid zero-journal purchase void.');
            }
        } else {
            $batch = PostingBatch::withoutGlobalScopes()->where('company_id', $document->company_id)->findOrFail($document->posting_batch_id);
            $inverse = PostingBatch::withoutGlobalScopes()->where('company_id', $document->company_id)->findOrFail($document->void_posting_batch_id);
            $originalLines = $batch->lines()->orderBy('line_number')->get();
            $inverseLines = $inverse->lines()->orderBy('line_number')->get();
            if ($originalLines->isEmpty() || $originalLines->count() !== $inverseLines->count()) {
                throw new InvalidArgumentException('Incomplete purchase reversal journal.');
            }
            foreach ($originalLines as $index => $line) {
                $reversed = $inverseLines[$index];
                if ($line->transaction_currency_code !== $reversed->transaction_currency_code) {
                    throw new InvalidArgumentException('Purchase reversal currency mismatch.');
                }
                foreach (['transaction_amount', 'exchange_rate'] as $field) {
                    if (($line->$field === null) !== ($reversed->$field === null)
                        || ($line->$field !== null && ! BigDecimal::of($line->$field)->isEqualTo(BigDecimal::of($reversed->$field)))) {
                        throw new InvalidArgumentException('Purchase reversal currency metadata mismatch.');
                    }
                }
                if ($line->ledger_account_id !== $reversed->ledger_account_id
                    || ! BigDecimal::of($line->debit_base)->isEqualTo(BigDecimal::of($reversed->credit_base))
                    || ! BigDecimal::of($line->credit_base)->isEqualTo(BigDecimal::of($reversed->debit_base))) {
                    throw new InvalidArgumentException('Purchase reversal journal does not reciprocate original.');
                }
            }
            if ($batch->source_type !== $source || (int) $batch->source_id !== (int) $document->id
                || $batch->status !== PostingBatch::STATUS_REVERSED || (int) $batch->reversed_by_batch_id !== (int) $inverse->id
                || (int) $inverse->reversal_of_id !== (int) $batch->id || $inverse->status !== PostingBatch::STATUS_POSTED
                || $inverse->posting_date->format('Y-m-d') !== $document->void_business_date->format('Y-m-d')) {
                throw new InvalidArgumentException('Invalid purchase accounting reversal.');
            }
        }
    }
}
