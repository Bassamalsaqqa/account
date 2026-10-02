<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Services\Inventory\HistoricalSaleCost;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/** Reads immutable posting/movement facts independently of document caches. */
final class SalesHistoryAudit
{
    /** @return list<string> */
    public function audit(int $companyId): array
    {
        $errors = [];
        $accounts = DB::table('ledger_accounts')->where('company_id', $companyId)->pluck('id', 'system_key')->all();
        foreach (['sales_invoices' => 'sales_invoice', 'sales_returns' => 'sales_return'] as $table => $source) {
            $lineTable = $source === 'sales_invoice' ? 'sales_invoice_lines' : 'sales_return_lines';
            $allocationTable = $source === 'sales_invoice' ? 'sales_invoice_lot_allocations' : 'sales_return_lot_allocations';
            $parentKey = $source.'_id';
            $lineKey = $source.'_line_id';
            foreach (DB::table($table)->where('company_id', $companyId)->whereIn('status', ['posted', 'void'])->get() as $document) {
                $label = "$source [{$document->id}]";
                $expected = [];
                $cogs = BigDecimal::zero();
                $taxGroups = [];
                foreach (DB::table($lineTable)->where($parentKey, $document->id)->get() as $line) {
                    if ((int) $line->company_id !== $companyId) {
                        $errors[] = "$label foreign line.";
                    }
                    if ($line->product_id !== null && ! DB::table('products')->where('company_id', $companyId)->where('id', $line->product_id)->exists()) {
                        $errors[] = "$label foreign product.";
                    }
                    if ($line->product_unit_id !== null && ! DB::table('product_units')->where('company_id', $companyId)->where('product_id', $line->product_id)->where('id', $line->product_unit_id)->exists()) {
                        $errors[] = "$label foreign product unit.";
                    }
                    $quantity = BigDecimal::zero();
                    $lineCost = BigDecimal::zero();
                    foreach (DB::table($allocationTable)->where($lineKey, $line->id)->get() as $allocation) {
                        $movement = DB::table('stock_movements')->where('id', $allocation->stock_movement_id)->first();
                        if ($movement === null || (int) $movement->company_id !== $companyId || $movement->source_type !== $source || (int) $movement->source_id !== (int) $document->id
                            || (int) $movement->product_id !== (int) $line->product_id || (int) $movement->warehouse_id !== (int) $document->warehouse_id
                            || $movement->lot_id !== $allocation->inventory_lot_id || (int) $allocation->company_id !== $companyId || (int) $allocation->$parentKey !== (int) $document->id
                            || $movement->movement_type !== ($source === 'sales_invoice' ? 'sale' : 'sale_return')) {
                            $errors[] = "$label allocation/movement provenance mismatch.";

                            continue;
                        }
                        $qty = BigDecimal::of($movement->quantity_delta_base);
                        $value = BigDecimal::of($movement->value_delta_base);
                        if (($source === 'sales_invoice' && ! $qty->isNegative()) || ($source === 'sales_return' && ! $qty->isPositive())
                            || ! $qty->abs()->isEqualTo($allocation->quantity_allocated_base) || ! $value->abs()->isEqualTo($allocation->total_cost_base)
                            || ! BigDecimal::of($movement->unit_cost_base)->isEqualTo($allocation->unit_cost_base)) {
                            $errors[] = "$label allocation quantity/cost differs from immutable movement.";
                        }
                        if ($source === 'sales_return') {
                            $original = DB::table('stock_movements')->where('company_id', $companyId)->where('id', $movement->reversal_of_id)->first();
                            if ($original === null || $original->source_type !== 'sales_invoice' || (int) $original->source_id !== (int) $document->sales_invoice_id) {
                                $errors[] = "$label original sale cost provenance missing.";
                            } else {
                                try {
                                    $cost = app(HistoricalSaleCost::class)->value($companyId, (int) $original->id, (int) $movement->product_id,
                                        (int) $movement->warehouse_id, $movement->lot_id, $source, (int) $document->id, $qty, (int) $movement->id);
                                    if (! $cost->isEqualTo($value)) {
                                        $errors[] = "$label historical COGS restoration mismatch.";
                                    }
                                } catch (\Throwable $exception) {
                                    $errors[] = "$label historical COGS invalid: {$exception->getMessage()}";
                                }
                            }
                        }
                        $quantity = $quantity->plus($qty->abs());
                        $lineCost = $lineCost->plus($value->abs());
                    }
                    if (! $lineCost->isEqualTo($line->cogs_total_base) || (! $quantity->isZero() && ! $quantity->isEqualTo($line->quantity_base))) {
                        $errors[] = "$label line COGS/stock quantity mismatch.";
                    }
                    if ($line->stock_movement_id !== null && $quantity->isZero()) {
                        $errors[] = "$label stock movement lacks allocation.";
                    }
                    $cogs = $cogs->plus($lineCost);
                    $tax = BigDecimal::of($line->line_tax);
                    if ($tax->isPositive()) {
                        $original = $source === 'sales_invoice' ? $line : DB::table('sales_invoice_lines')->where('company_id', $companyId)->where('id', $line->sales_invoice_line_id)->first();
                        $accountId = $original?->sales_tax_account_id;
                        if ($accountId === null || ! DB::table('ledger_accounts')->where('company_id', $companyId)->where('id', $accountId)->exists()) {
                            $errors[] = "$label original tax account missing.";
                        } else {
                            $taxGroups[$accountId] = ($taxGroups[$accountId] ?? BigDecimal::zero())->plus($tax);
                        }
                    }
                }
                if (! $cogs->isEqualTo($document->cogs_total_base)) {
                    $errors[] = "$label header COGS mismatch.";
                }
                $grand = BigDecimal::of($document->grand_total_base);
                $tax = BigDecimal::of($document->tax_total_base);
                $sign = $source === 'sales_invoice' ? 1 : -1;
                $this->add($expected, (int) ($accounts['accounts_receivable'] ?? 0), $grand->multipliedBy($sign));
                $this->add($expected, (int) ($accounts[$source === 'sales_invoice' ? 'sales_revenue' : 'sales_returns'] ?? 0), $grand->minus($tax)->multipliedBy(-$sign));
                $remaining = $tax;
                $groups = count($taxGroups);
                foreach ($taxGroups as $id => $amount) {
                    $groups--;
                    $base = $groups === 0 ? $remaining : $amount->multipliedBy($document->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                    $remaining = $remaining->minus($base);
                    $this->add($expected, (int) $id, $base->multipliedBy(-$sign));
                }
                $this->add($expected, (int) ($accounts['cogs'] ?? 0), $cogs->multipliedBy($sign));
                $this->add($expected, (int) ($accounts['inventory'] ?? 0), $cogs->multipliedBy(-$sign));
                $errors = array_merge($errors, $this->batch($companyId, (int) $document->posting_batch_id, $source, (int) $document->id, $expected));
                if ($document->status === 'void') {
                    $errors = array_merge($errors, $this->reversal($companyId, (int) $document->posting_batch_id, (int) $document->void_posting_batch_id));
                }
            }
        }
        foreach (DB::table('customer_payments')->where('company_id', $companyId)->get() as $payment) {
            $expected = [];
            $account = DB::table('money_accounts')->where('company_id', $companyId)->where('id', $payment->money_account_id)->first();
            $base = BigDecimal::of($payment->amount)->multipliedBy($payment->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
            if ($account === null || ! $base->isEqualTo($payment->amount_base)) {
                $errors[] = "Payment [{$payment->id}] money account/amount mismatch.";
            }
            $this->add($expected, (int) ($account->ledger_account_id ?? 0), $base);
            $applied = BigDecimal::zero();
            $settled = BigDecimal::zero();
            $amount = BigDecimal::zero();
            $gain = BigDecimal::zero();
            $loss = BigDecimal::zero();
            foreach (DB::table('customer_payment_allocations')->where('customer_payment_id', $payment->id)->whereNull('application_event_id')->orderBy('id')->get() as $allocation) {
                $invoice = DB::table('sales_invoices')->where('company_id', $companyId)->where('id', $allocation->sales_invoice_id)->first();
                if ($invoice === null || (int) $allocation->company_id !== $companyId || (int) $invoice->customer_id !== (int) $payment->customer_id || $invoice->currency_code !== $payment->currency_code) {
                    $errors[] = "Payment [{$payment->id}] allocation provenance mismatch.";

                    continue;
                }
                $prior = app(ReceivableReliefHistory::class)->before($companyId, (int) $invoice->id, (int) $allocation->prior_posting_batch_id, (int) $allocation->id);
                $targetAmount = $prior['amount']->plus($allocation->allocated_amount);
                $targetBase = $targetAmount->isEqualTo($invoice->grand_total_currency) ? BigDecimal::of($invoice->grand_total_base) : $targetAmount->multipliedBy($invoice->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                $book = $targetBase->minus($prior['base']);
                $amount = $amount->plus($allocation->allocated_amount);
                $settlement = $amount->multipliedBy($payment->exchange_rate)->toScale(6, RoundingMode::HALF_UP)->minus($settled);
                if ($targetAmount->isGreaterThan($invoice->grand_total_currency) || ! $book->isEqualTo($allocation->base_amount_applied_to_receivable) || ! $settlement->isEqualTo($allocation->settlement_base_value)
                    || ! $settlement->minus($book)->isEqualTo($allocation->realized_fx_gain_loss_base) || ! BigDecimal::of($allocation->invoice_exchange_rate)->isEqualTo($invoice->exchange_rate)
                    || ! BigDecimal::of($allocation->payment_exchange_rate)->isEqualTo($payment->exchange_rate)) {
                    $errors[] = "Payment [{$payment->id}] cumulative AR/settlement/FX mismatch.";
                }
                $applied = $applied->plus($allocation->base_amount_applied_to_receivable);
                $settled = $settled->plus($allocation->settlement_base_value);
                $delta = BigDecimal::of($allocation->realized_fx_gain_loss_base);
                if ($delta->isPositive()) {
                    $gain = $gain->plus($delta);
                } else {
                    $loss = $loss->plus($delta->abs());
                }
            }
            $this->add($expected, (int) ($accounts['accounts_receivable'] ?? 0), $applied->plus($base->minus($settled))->negated());
            $this->add($expected, (int) ($accounts['fx_gain'] ?? 0), $gain->negated());
            $this->add($expected, (int) ($accounts['fx_loss'] ?? 0), $loss);
            $errors = array_merge($errors, $this->batch($companyId, (int) $payment->posting_batch_id, 'customer_payment', (int) $payment->id, $expected));
            if ($payment->is_reversed) {
                $errors = array_merge($errors, $this->reversal($companyId, (int) $payment->posting_batch_id, (int) $payment->reversal_posting_batch_id));
            }
        }

        return $errors;
    }

    /** @param array<int, BigDecimal> $values */
    private function add(array &$values, int $account, BigDecimal $amount): void
    {
        $values[$account] = ($values[$account] ?? BigDecimal::zero())->plus($amount);
    }

    /** @param array<int, BigDecimal> $expected
     * @return list<string>
     */
    public function batch(int $companyId, int $id, string $source, int $sourceId, array $expected): array
    {
        $errors = [];
        $batch = DB::table('posting_batches')->where('company_id', $companyId)->where('id', $id)->first();
        if ($batch === null || $batch->source_type !== $source || (int) $batch->source_id !== $sourceId) {
            return ["$source [$sourceId] canonical GL provenance mismatch."];
        }
        $actual = [];
        foreach (DB::table('posting_lines')->where('posting_batch_id', $id)->get() as $line) {
            if ((int) $line->company_id !== $companyId || ! DB::table('ledger_accounts')->where('company_id', $companyId)->where('id', $line->ledger_account_id)->exists()) {
                $errors[] = "$source [$sourceId] foreign posting line/account.";
            }
            $net = BigDecimal::of($line->debit_base)->minus($line->credit_base);
            $this->add($actual, (int) $line->ledger_account_id, $net);
            if ($line->transaction_currency_code !== null) {
                if ($line->transaction_amount === null || $line->exchange_rate === null || ! $net->abs()->isEqualTo(BigDecimal::of($line->transaction_amount)->multipliedBy($line->exchange_rate)->toScale(6, RoundingMode::HALF_UP))) {
                    $errors[] = "$source [$sourceId] posting currency metadata mismatch.";
                }
            }
        }
        foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $account) {
            if (! ($expected[$account] ?? BigDecimal::zero())->isEqualTo($actual[$account] ?? BigDecimal::zero())) {
                $errors[] = "$source [$sourceId] GL account [$account] amount mismatch.";
            }
        }

        return $errors;
    }

    /** @return list<string> */
    public function reversal(int $companyId, int $originalId, int $reversalId): array
    {
        $reversal = DB::table('posting_batches')->where('company_id', $companyId)->where('id', $reversalId)->first();
        $original = DB::table('posting_batches')->where('company_id', $companyId)->where('id', $originalId)->first();
        if ($reversal === null || $original === null || (int) $reversal->reversal_of_id !== $originalId || (int) $original->reversed_by_batch_id !== $reversalId) {
            return ['Canonical accounting reversal linkage mismatch.'];
        }
        $expected = [];
        foreach (DB::table('posting_lines')->where('posting_batch_id', $originalId)->get() as $line) {
            $this->add($expected, (int) $line->ledger_account_id, BigDecimal::of($line->credit_base)->minus($line->debit_base));
        }

        return $this->batch($companyId, $reversalId, $reversal->source_type, (int) $reversal->source_id, $expected);
    }
}
