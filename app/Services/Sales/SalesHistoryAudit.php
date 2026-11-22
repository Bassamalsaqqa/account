<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Services\Inventory\HistoricalSaleCost;
use App\Services\Money\PaymentAllocationIntent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
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
                $docLines = DB::table($lineTable)->where($parentKey, $document->id)->get();
                foreach ($docLines as $line) {
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
                    if (! $tax->isZero()) {
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

                $grandCurr = BigDecimal::of($document->grand_total_currency);
                $subtotalCurr = $grandCurr->minus(BigDecimal::of($document->tax_total_currency));
                $curr = (string) $document->currency_code;
                $rate = (string) $document->exchange_rate;

                /** @var array<string, BigDecimal> $debitComponents */
                $debitComponents = [];
                /** @var array<string, BigDecimal> $creditComponents */
                $creditComponents = [];
                /** @var array<int, BigDecimal> $baseDebitAdjustments */
                $baseDebitAdjustments = [];
                /** @var array<int, BigDecimal> $baseCreditAdjustments */
                $baseCreditAdjustments = [];

                if ($source === 'sales_invoice') {
                    $arAcc = (int) ($accounts['accounts_receivable'] ?? 0);
                    $revAcc = (int) ($accounts['sales_revenue'] ?? 0);
                    $grandTotalBase = BigDecimal::of((string) $document->grand_total_base);
                    $revenueBase = BigDecimal::of((string) $document->grand_total_base)->minus($document->tax_total_base);
                    $arComputed = $grandCurr->isZero() ? BigDecimal::zero() : $grandCurr->abs()->multipliedBy($document->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                    $revComputed = $subtotalCurr->isZero() ? BigDecimal::zero() : $subtotalCurr->abs()->multipliedBy($document->exchange_rate)->toScale(6, RoundingMode::HALF_UP);

                    $arResidual = $grandTotalBase->abs()->minus($arComputed);
                    if ($grandTotalBase->isPositive()) {
                        if ($arComputed->isPositive()) {
                            $debitComponents["{$arAcc}:{$curr}:{$rate}"] = $grandCurr->abs();
                        }
                        if (! $arResidual->isZero()) {
                            if ($arResidual->isPositive()) {
                                $baseDebitAdjustments[$arAcc] = ($baseDebitAdjustments[$arAcc] ?? BigDecimal::zero())->plus($arResidual);
                            } else {
                                $baseCreditAdjustments[$arAcc] = ($baseCreditAdjustments[$arAcc] ?? BigDecimal::zero())->plus($arResidual->abs());
                            }
                        }
                    } elseif ($grandTotalBase->isNegative()) {
                        if ($arComputed->isPositive()) {
                            $creditComponents["{$arAcc}:{$curr}:{$rate}"] = $grandCurr->abs();
                        }
                        if (! $arResidual->isZero()) {
                            if ($arResidual->isPositive()) {
                                $baseCreditAdjustments[$arAcc] = ($baseCreditAdjustments[$arAcc] ?? BigDecimal::zero())->plus($arResidual);
                            } else {
                                $baseDebitAdjustments[$arAcc] = ($baseDebitAdjustments[$arAcc] ?? BigDecimal::zero())->plus($arResidual->abs());
                            }
                        }
                    }

                    $revResidual = $revenueBase->abs()->minus($revComputed);
                    if ($revenueBase->isPositive()) {
                        if ($revComputed->isPositive()) {
                            $creditComponents["{$revAcc}:{$curr}:{$rate}"] = $subtotalCurr->abs();
                        }
                        if (! $revResidual->isZero()) {
                            if ($revResidual->isPositive()) {
                                $baseCreditAdjustments[$revAcc] = ($baseCreditAdjustments[$revAcc] ?? BigDecimal::zero())->plus($revResidual);
                            } else {
                                $baseDebitAdjustments[$revAcc] = ($baseDebitAdjustments[$revAcc] ?? BigDecimal::zero())->plus($revResidual->abs());
                            }
                        }
                    } elseif ($revenueBase->isNegative()) {
                        if ($revComputed->isPositive()) {
                            $debitComponents["{$revAcc}:{$curr}:{$rate}"] = $subtotalCurr->abs();
                        }
                        if (! $revResidual->isZero()) {
                            if ($revResidual->isPositive()) {
                                $baseDebitAdjustments[$revAcc] = ($baseDebitAdjustments[$revAcc] ?? BigDecimal::zero())->plus($revResidual);
                            } else {
                                $baseCreditAdjustments[$revAcc] = ($baseCreditAdjustments[$revAcc] ?? BigDecimal::zero())->plus($revResidual->abs());
                            }
                        }
                    }

                    $groups = count($taxGroups);
                    $rem = $tax;
                    foreach ($taxGroups as $taxAccId => $amount) {
                        $groups--;
                        $taxGroupBase = $groups === 0 ? $rem : $amount->multipliedBy($document->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                        $rem = $rem->minus($taxGroupBase);
                        $computedBase = $amount->multipliedBy($document->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                        if ($computedBase->isPositive()) {
                            $creditComponents["{$taxAccId}:{$curr}:{$rate}"] = ($creditComponents["{$taxAccId}:{$curr}:{$rate}"] ?? BigDecimal::zero())->plus($amount);
                        }
                        $baseDiff = $taxGroupBase->minus($computedBase);
                        if (! $baseDiff->isZero()) {
                            if ($baseDiff->isPositive()) {
                                $baseCreditAdjustments[(int) $taxAccId] = ($baseCreditAdjustments[(int) $taxAccId] ?? BigDecimal::zero())->plus($baseDiff);
                            } else {
                                $baseDebitAdjustments[(int) $taxAccId] = ($baseDebitAdjustments[(int) $taxAccId] ?? BigDecimal::zero())->plus($baseDiff->abs());
                            }
                        }
                    }

                    $cogsAcc = (int) ($accounts['cogs'] ?? 0);
                    $invAcc = (int) ($accounts['inventory'] ?? 0);
                    if ($cogs->isPositive()) {
                        $baseDebitAdjustments[$cogsAcc] = ($baseDebitAdjustments[$cogsAcc] ?? BigDecimal::zero())->plus($cogs);
                        $baseCreditAdjustments[$invAcc] = ($baseCreditAdjustments[$invAcc] ?? BigDecimal::zero())->plus($cogs);
                    }
                } else {
                    $arAcc = (int) ($accounts['accounts_receivable'] ?? 0);
                    $retAcc = (int) ($accounts['sales_returns'] ?? 0);
                    $grandTotalBase = BigDecimal::of((string) $document->grand_total_base);
                    $returnBase = BigDecimal::of((string) $document->grand_total_base)->minus($document->tax_total_base);
                    $arComputed = $grandCurr->isZero() ? BigDecimal::zero() : $grandCurr->abs()->multipliedBy($document->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                    $retComputed = $subtotalCurr->isZero() ? BigDecimal::zero() : $subtotalCurr->abs()->multipliedBy($document->exchange_rate)->toScale(6, RoundingMode::HALF_UP);

                    $arResidual = $grandTotalBase->abs()->minus($arComputed);
                    if ($grandTotalBase->isPositive()) {
                        if ($arComputed->isPositive()) {
                            $creditComponents["{$arAcc}:{$curr}:{$rate}"] = $grandCurr->abs();
                        }
                        if (! $arResidual->isZero()) {
                            if ($arResidual->isPositive()) {
                                $baseCreditAdjustments[$arAcc] = ($baseCreditAdjustments[$arAcc] ?? BigDecimal::zero())->plus($arResidual);
                            } else {
                                $baseDebitAdjustments[$arAcc] = ($baseDebitAdjustments[$arAcc] ?? BigDecimal::zero())->plus($arResidual->abs());
                            }
                        }
                    } elseif ($grandTotalBase->isNegative()) {
                        if ($arComputed->isPositive()) {
                            $debitComponents["{$arAcc}:{$curr}:{$rate}"] = $grandCurr->abs();
                        }
                        if (! $arResidual->isZero()) {
                            if ($arResidual->isPositive()) {
                                $baseDebitAdjustments[$arAcc] = ($baseDebitAdjustments[$arAcc] ?? BigDecimal::zero())->plus($arResidual);
                            } else {
                                $baseCreditAdjustments[$arAcc] = ($baseCreditAdjustments[$arAcc] ?? BigDecimal::zero())->plus($arResidual->abs());
                            }
                        }
                    }

                    $retResidual = $returnBase->abs()->minus($retComputed);
                    if ($returnBase->isPositive()) {
                        if ($retComputed->isPositive()) {
                            $debitComponents["{$retAcc}:{$curr}:{$rate}"] = $subtotalCurr->abs();
                        }
                        if (! $retResidual->isZero()) {
                            if ($retResidual->isPositive()) {
                                $baseDebitAdjustments[$retAcc] = ($baseDebitAdjustments[$retAcc] ?? BigDecimal::zero())->plus($retResidual);
                            } else {
                                $baseCreditAdjustments[$retAcc] = ($baseCreditAdjustments[$retAcc] ?? BigDecimal::zero())->plus($retResidual->abs());
                            }
                        }
                    } elseif ($returnBase->isNegative()) {
                        if ($retComputed->isPositive()) {
                            $creditComponents["{$retAcc}:{$curr}:{$rate}"] = $subtotalCurr->abs();
                        }
                        if (! $retResidual->isZero()) {
                            if ($retResidual->isPositive()) {
                                $baseCreditAdjustments[$retAcc] = ($baseCreditAdjustments[$retAcc] ?? BigDecimal::zero())->plus($retResidual);
                            } else {
                                $baseDebitAdjustments[$retAcc] = ($baseDebitAdjustments[$retAcc] ?? BigDecimal::zero())->plus($retResidual->abs());
                            }
                        }
                    }

                    $taxComponents = [];
                    foreach ($docLines as $dLine) {
                        $t = BigDecimal::of($dLine->line_tax);
                        if (! $t->isZero()) {
                            $origLine = DB::table('sales_invoice_lines')->where('company_id', $companyId)->where('id', $dLine->sales_invoice_line_id)->first();
                            $taxAccId = $origLine !== null ? (int) $origLine->sales_tax_account_id : 0;
                            if ($taxAccId > 0) {
                                $taxComponents[$taxAccId] ??= ['pos' => BigDecimal::zero(), 'neg' => BigDecimal::zero(), 'net' => BigDecimal::zero()];
                                if ($t->isPositive()) {
                                    $taxComponents[$taxAccId]['pos'] = $taxComponents[$taxAccId]['pos']->plus($t);
                                } else {
                                    $taxComponents[$taxAccId]['neg'] = $taxComponents[$taxAccId]['neg']->plus($t);
                                }
                                $taxComponents[$taxAccId]['net'] = $taxComponents[$taxAccId]['net']->plus($t);
                            }
                        }
                    }

                    $taxRemBase = $tax;
                    $remGroups = count($taxComponents);
                    $isHistorical = $document->posted_at !== null && Carbon::parse($document->posted_at)->lt(Carbon::parse('2026-11-21 00:00:00+00:00'));
                    foreach ($taxComponents as $tAccId => $tComp) {
                        $remGroups--;
                        $gBase = $remGroups === 0 ? $taxRemBase : $tComp['net']->multipliedBy($document->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                        $taxRemBase = $taxRemBase->minus($gBase);

                        $signedBaseSum = BigDecimal::zero();
                        if ($tComp['pos']->isPositive()) {
                            $posBase = $tComp['pos']->multipliedBy($document->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                            if ($posBase->isPositive()) {
                                $debitComponents["{$tAccId}:{$curr}:{$rate}"] = ($debitComponents["{$tAccId}:{$curr}:{$rate}"] ?? BigDecimal::zero())->plus($tComp['pos']);
                                $signedBaseSum = $signedBaseSum->plus($posBase);
                            } elseif (! $isHistorical) {
                                $errors[] = "$label unrepresentable currency tax component with zero base.";
                            }
                        }
                        if ($tComp['neg']->isNegative()) {
                            $negBase = $tComp['neg']->abs()->multipliedBy($document->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                            if ($negBase->isPositive()) {
                                $creditComponents["{$tAccId}:{$curr}:{$rate}"] = ($creditComponents["{$tAccId}:{$curr}:{$rate}"] ?? BigDecimal::zero())->plus($tComp['neg']->abs());
                                $signedBaseSum = $signedBaseSum->minus($negBase);
                            } elseif (! $isHistorical) {
                                $errors[] = "$label unrepresentable currency tax component with zero base.";
                            }
                        }
                        $bResidual = $gBase->minus($signedBaseSum);
                        if (! $bResidual->isZero()) {
                            if ($bResidual->isPositive()) {
                                $baseDebitAdjustments[$tAccId] = ($baseDebitAdjustments[$tAccId] ?? BigDecimal::zero())->plus($bResidual);
                            } else {
                                $baseCreditAdjustments[$tAccId] = ($baseCreditAdjustments[$tAccId] ?? BigDecimal::zero())->plus($bResidual->abs());
                            }
                        }
                    }

                    $cogsAcc = (int) ($accounts['cogs'] ?? 0);
                    $invAcc = (int) ($accounts['inventory'] ?? 0);
                    if ($cogs->isPositive()) {
                        $baseDebitAdjustments[$invAcc] = ($baseDebitAdjustments[$invAcc] ?? BigDecimal::zero())->plus($cogs);
                        $baseCreditAdjustments[$cogsAcc] = ($baseCreditAdjustments[$cogsAcc] ?? BigDecimal::zero())->plus($cogs);
                    }
                }

                $expectedCurrency = [
                    'debit_components' => $debitComponents,
                    'credit_components' => $creditComponents,
                    'base_debit_adjustments' => $baseDebitAdjustments,
                    'base_credit_adjustments' => $baseCreditAdjustments,
                ];

                $errors = array_merge($errors, $this->batch($companyId, (int) $document->posting_batch_id, $source, (int) $document->id, $expected, $expectedCurrency));
                if ($document->status === 'void') {
                    $errors = array_merge($errors, $this->reversal($companyId, (int) $document->posting_batch_id, (int) $document->void_posting_batch_id));
                }
            }
        }
        foreach (DB::table('customer_payments')->where('company_id', $companyId)->get() as $payment) {
            $expected = [];
            $account = DB::table('money_accounts')->where('company_id', $companyId)->where('id', $payment->money_account_id)->first();
            $check = $payment->payment_method === 'check' ? DB::table('checks')->where('company_id', $companyId)->where('id', $payment->check_id)->first() : null;
            $base = BigDecimal::of($payment->amount)->multipliedBy($payment->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
            if (($check === null && $account === null) || ! $base->isEqualTo($payment->amount_base)
                || ($check !== null && ($check->direction !== 'incoming' || (int) $check->customer_id !== (int) $payment->customer_id || $payment->money_account_id !== null
                    || $check->currency_code !== $payment->currency_code || ! BigDecimal::of($check->amount)->isEqualTo($payment->amount)))) {
                $errors[] = "Payment [{$payment->id}] money account/amount mismatch.";
            }
            $this->add($expected, (int) ($check !== null ? ($accounts['checks_in_hand'] ?? 0) : ($account->ledger_account_id ?? 0)), $base);
            $applied = BigDecimal::zero();
            $settled = BigDecimal::zero();
            $amount = BigDecimal::zero();
            $gain = BigDecimal::zero();
            $loss = BigDecimal::zero();
            foreach (DB::table('customer_payment_allocations')->where('customer_payment_id', $payment->id)->whereNull('application_event_id')->orderBy('id')->get() as $allocation) {
                $invoice = DB::table('sales_invoices')->where('company_id', $companyId)->where('id', $allocation->sales_invoice_id)->first();
                if ($invoice === null || (int) $allocation->company_id !== $companyId || (int) $invoice->customer_id !== (int) $payment->customer_id) {
                    $errors[] = "Payment [{$payment->id}] allocation provenance mismatch.";

                    continue;
                }
                try {
                    PaymentAllocationIntent::amounts(['allocated_amount' => $allocation->allocated_amount, 'payment_currency_amount' => $allocation->payment_currency_amount], $invoice->currency_code, $payment->currency_code);
                    if ((int) $payment->allocation_version === 1 && ! BigDecimal::of($allocation->allocated_amount)->isEqualTo($allocation->payment_currency_amount)) {
                        throw new \InvalidArgumentException('Legacy allocation amount mismatch.');
                    }
                } catch (\Throwable $exception) {
                    $errors[] = 'Payment dual-currency allocation precision/provenance mismatch: '.$exception->getMessage();

                    continue;
                }
                $prior = app(ReceivableReliefHistory::class)->before($companyId, (int) $invoice->id, (int) $allocation->prior_posting_batch_id, (int) $allocation->id);
                $targetAmount = $prior['amount']->plus($allocation->allocated_amount);
                $targetBase = $targetAmount->isEqualTo($invoice->grand_total_currency) ? BigDecimal::of($invoice->grand_total_base) : $targetAmount->multipliedBy($invoice->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                $book = $targetBase->minus($prior['base']);
                $amount = $amount->plus($allocation->payment_currency_amount);
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

    /**
     * @param  array<int, BigDecimal>  $expected
     * @param  array<string|int, mixed>  $expectedCurrency
     * @return list<string>
     */
    public function batch(int $companyId, int $id, string $source, int $sourceId, array $expected, array $expectedCurrency = []): array
    {
        $errors = [];
        $batch = DB::table('posting_batches')->where('company_id', $companyId)->where('id', $id)->first();
        if ($batch === null || $batch->source_type !== $source || (int) $batch->source_id !== $sourceId) {
            return ["$source [$sourceId] canonical GL provenance mismatch."];
        }
        $actual = [];
        $actualDebitComponents = [];
        $actualCreditComponents = [];
        $actualBaseDebits = [];
        $actualBaseCredits = [];
        $actualCurrencyFlat = [];

        foreach (DB::table('posting_lines')->where('posting_batch_id', $id)->get() as $line) {
            if ((int) $line->company_id !== $companyId || ! DB::table('ledger_accounts')->where('company_id', $companyId)->where('id', $line->ledger_account_id)->exists()) {
                $errors[] = "$source [$sourceId] foreign posting line/account.";
            }
            $net = BigDecimal::of($line->debit_base)->minus($line->credit_base);
            $this->add($actual, (int) $line->ledger_account_id, $net);

            $hasCurrency = $line->transaction_currency_code !== null;
            $hasAmount = $line->transaction_amount !== null;
            $hasRate = $line->exchange_rate !== null;

            if (($hasCurrency || $hasAmount || $hasRate) && ! ($hasCurrency && $hasAmount && $hasRate)) {
                $errors[] = "$source [$sourceId] posting currency metadata mismatch.";
            } elseif ($hasCurrency) {
                $amt = BigDecimal::of($line->transaction_amount);
                if ($amt->isNegative() || $amt->isZero()) {
                    $errors[] = "$source [$sourceId] non-positive transaction amount.";
                }
                $debit = BigDecimal::of($line->debit_base);
                $credit = BigDecimal::of($line->credit_base);
                $key = ((int) $line->ledger_account_id).':'.$line->transaction_currency_code.':'.$line->exchange_rate;

                if ($debit->isPositive()) {
                    $actualDebitComponents[$key] = ($actualDebitComponents[$key] ?? BigDecimal::zero())->plus($amt);
                    $this->add($actualCurrencyFlat, (int) $line->ledger_account_id, $amt);
                    if (! $debit->isEqualTo($amt->multipliedBy($line->exchange_rate)->toScale(6, RoundingMode::HALF_UP))) {
                        $errors[] = "$source [$sourceId] posting currency metadata mismatch.";
                    }
                } elseif ($credit->isPositive()) {
                    $actualCreditComponents[$key] = ($actualCreditComponents[$key] ?? BigDecimal::zero())->plus($amt);
                    $this->add($actualCurrencyFlat, (int) $line->ledger_account_id, $amt->negated());
                    if (! $credit->isEqualTo($amt->multipliedBy($line->exchange_rate)->toScale(6, RoundingMode::HALF_UP))) {
                        $errors[] = "$source [$sourceId] posting currency metadata mismatch.";
                    }
                } else {
                    $errors[] = "$source [$sourceId] posting currency line with zero base.";
                }
            } else {
                $deb = BigDecimal::of($line->debit_base);
                $cred = BigDecimal::of($line->credit_base);
                if ($deb->isPositive()) {
                    $actualBaseDebits[(int) $line->ledger_account_id] = ($actualBaseDebits[(int) $line->ledger_account_id] ?? BigDecimal::zero())->plus($deb);
                }
                if ($cred->isPositive()) {
                    $actualBaseCredits[(int) $line->ledger_account_id] = ($actualBaseCredits[(int) $line->ledger_account_id] ?? BigDecimal::zero())->plus($cred);
                }
            }
        }

        foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $account) {
            if (! ($expected[$account] ?? BigDecimal::zero())->isEqualTo($actual[$account] ?? BigDecimal::zero())) {
                $errors[] = "$source [$sourceId] GL account [$account] amount mismatch.";
            }
        }

        if (isset($expectedCurrency['debit_components']) || isset($expectedCurrency['credit_components']) || isset($expectedCurrency['base_debit_adjustments']) || isset($expectedCurrency['base_credit_adjustments']) || isset($expectedCurrency['base_adjustments'])) {
            /** @var array<string, BigDecimal> $expDebits */
            $expDebits = $expectedCurrency['debit_components'] ?? [];
            /** @var array<string, BigDecimal> $expCredits */
            $expCredits = $expectedCurrency['credit_components'] ?? [];
            /** @var array<int, BigDecimal> $expBaseDebits */
            $expBaseDebits = $expectedCurrency['base_debit_adjustments'] ?? [];
            /** @var array<int, BigDecimal> $expBaseCredits */
            $expBaseCredits = $expectedCurrency['base_credit_adjustments'] ?? [];

            foreach (array_unique(array_merge(array_keys($expDebits), array_keys($actualDebitComponents))) as $key) {
                if (! ($expDebits[$key] ?? BigDecimal::zero())->isEqualTo($actualDebitComponents[$key] ?? BigDecimal::zero())) {
                    $errors[] = "$source [$sourceId] debit currency component [$key] mismatch.";
                }
            }
            foreach (array_unique(array_merge(array_keys($expCredits), array_keys($actualCreditComponents))) as $key) {
                if (! ($expCredits[$key] ?? BigDecimal::zero())->isEqualTo($actualCreditComponents[$key] ?? BigDecimal::zero())) {
                    $errors[] = "$source [$sourceId] credit currency component [$key] mismatch.";
                }
            }
            if (! empty($expBaseDebits) || ! empty($actualBaseDebits)) {
                foreach (array_unique(array_merge(array_keys($expBaseDebits), array_keys($actualBaseDebits))) as $account) {
                    if (! ($expBaseDebits[$account] ?? BigDecimal::zero())->isEqualTo($actualBaseDebits[$account] ?? BigDecimal::zero())) {
                        $errors[] = "$source [$sourceId] base debit adjustment account [$account] mismatch.";
                    }
                }
            }
            if (! empty($expBaseCredits) || ! empty($actualBaseCredits)) {
                foreach (array_unique(array_merge(array_keys($expBaseCredits), array_keys($actualBaseCredits))) as $account) {
                    if (! ($expBaseCredits[$account] ?? BigDecimal::zero())->isEqualTo($actualBaseCredits[$account] ?? BigDecimal::zero())) {
                        $errors[] = "$source [$sourceId] base credit adjustment account [$account] mismatch.";
                    }
                }
            }
        } elseif (! empty($expectedCurrency)) {
            foreach (array_unique(array_merge(array_keys($expectedCurrency), array_keys($actualCurrencyFlat))) as $account) {
                if (! ($expectedCurrency[$account] ?? BigDecimal::zero())->isEqualTo($actualCurrencyFlat[$account] ?? BigDecimal::zero())) {
                    $errors[] = "$source [$sourceId] GL account [$account] transaction currency mismatch.";
                }
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
        $debitComponents = [];
        $creditComponents = [];
        $baseAdjustments = [];

        foreach (DB::table('posting_lines')->where('posting_batch_id', $originalId)->get() as $line) {
            $accId = (int) $line->ledger_account_id;
            $deb = BigDecimal::of($line->debit_base);
            $cred = BigDecimal::of($line->credit_base);
            $this->add($expected, $accId, $cred->minus($deb));

            $hasCurrency = $line->transaction_currency_code !== null;
            $hasAmount = $line->transaction_amount !== null;
            $hasRate = $line->exchange_rate !== null;

            if (($hasCurrency || $hasAmount || $hasRate) && ! ($hasCurrency && $hasAmount && $hasRate)) {
                return ["Reversal batch [{$reversalId}] line [{$line->id}] posting currency metadata mismatch."];
            } elseif ($hasCurrency) {
                $amt = BigDecimal::of($line->transaction_amount);
                $key = $accId.':'.$line->transaction_currency_code.':'.$line->exchange_rate;
                if ($deb->isPositive()) {
                    $creditComponents[$key] = ($creditComponents[$key] ?? BigDecimal::zero())->plus($amt);
                } elseif ($cred->isPositive()) {
                    $debitComponents[$key] = ($debitComponents[$key] ?? BigDecimal::zero())->plus($amt);
                }
            } else {
                $baseAdjustments[$accId] = ($baseAdjustments[$accId] ?? BigDecimal::zero())->plus($cred->minus($deb));
            }
        }

        $expectedCurrency = [
            'debit_components' => $debitComponents,
            'credit_components' => $creditComponents,
            'base_adjustments' => $baseAdjustments,
        ];

        return $this->batch($companyId, $reversalId, $reversal->source_type, (int) $reversal->source_id, $expected, $expectedCurrency);
    }
}
