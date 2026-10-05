<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Sales\SalesPostingLines;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class PurchaseReturnPostingCommandBuilder
{
    public function isZeroValue(PurchaseReturn $return, BigDecimal $actualInventoryRemoved): bool
    {
        $aBase = BigDecimal::of((string) $return->grand_total_base);
        $aCurrency = BigDecimal::of((string) $return->grand_total_currency);

        if (! $aBase->isZero() || ! $aCurrency->isZero()) {
            return false;
        }

        if (! $actualInventoryRemoved->isZero()) {
            return false;
        }

        $totalH = BigDecimal::zero();
        $totalRecoverableTaxBase = BigDecimal::zero();
        $totalRecoverableTaxCurrency = BigDecimal::zero();

        foreach ($return->lines as $line) {
            $recTaxBase = $line->purchase_tax_account_id !== null ? BigDecimal::of((string) $line->line_tax_base) : BigDecimal::zero();
            $recTaxCurrency = $line->purchase_tax_account_id !== null ? BigDecimal::of((string) $line->line_tax) : BigDecimal::zero();
            $totalRecoverableTaxBase = $totalRecoverableTaxBase->plus($recTaxBase);
            $totalRecoverableTaxCurrency = $totalRecoverableTaxCurrency->plus($recTaxCurrency);

            $lineH = BigDecimal::of((string) $line->line_total_base)->minus($recTaxBase);
            $totalH = $totalH->plus($lineH);
        }

        if (! $totalRecoverableTaxBase->isZero() || ! $totalRecoverableTaxCurrency->isZero()) {
            return false;
        }

        if (! $totalH->isZero()) {
            return false;
        }

        $adjustment = $actualInventoryRemoved->minus($totalH);
        if (! $adjustment->isZero()) {
            return false;
        }

        return true;
    }

    public function build(
        Company $company,
        PurchaseReturn $return,
        string $number,
        User $actor,
        bool $requirePosted = false
    ): ?PostingCommand {
        $return->setRelation('lines', $return->lines()->withoutGlobalScopes()->get());

        if ($return->lines->isEmpty()) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }

        $taxes = [];
        $totalActualInventory = BigDecimal::zero();
        $movementCount = 0;

        foreach ($return->lines as $line) {
            if ((int) $line->company_id !== (int) $return->company_id) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            if ($requirePosted) {
                if ($line->stock_movement_id === null
                    || $line->historical_receipt_value_base === null
                    || $line->inventory_value_removed_base === null
                    || $line->valuation_adjustment_base === null) {
                    throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                }
            }

            $line->setRelation('allocations', $line->allocations()->withoutGlobalScopes()->get());
            $lineAllocHistorical = BigDecimal::zero();
            $lineAllocActual = BigDecimal::zero();

            foreach ($line->allocations as $alloc) {
                if ((int) $alloc->company_id !== (int) $return->company_id) {
                    throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                }

                if ($requirePosted) {
                    if ($alloc->stock_movement_id === null
                        || $alloc->historical_value_base === null
                        || $alloc->inventory_value_removed_base === null) {
                        throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                    }
                    $lineAllocHistorical = $lineAllocHistorical->plus($alloc->historical_value_base);
                    $lineAllocActual = $lineAllocActual->plus($alloc->inventory_value_removed_base);
                }
            }

            $movements = StockMovement::where('company_id', $return->company_id)
                ->where('source_type', 'purchase_return')
                ->where('source_id', $return->id)
                ->where('source_line_id', $line->id)
                ->orderBy('id')
                ->get();

            if ($movements->isEmpty()) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            if ($requirePosted && (int) $line->stock_movement_id !== (int) $movements->first()->id) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            $lineActual = BigDecimal::zero();
            $movementCount += $movements->count();

            foreach ($movements as $movement) {
                $lineActual = $lineActual->plus(app(PurchaseReturnStockProvenance::class)->value($movement, $requirePosted));
            }

            if ($requirePosted) {
                if (! BigDecimal::of((string) $line->historical_receipt_value_base)->isEqualTo($lineAllocHistorical)) {
                    throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                }

                if (! BigDecimal::of((string) $line->inventory_value_removed_base)->isEqualTo($lineActual)
                    || ! BigDecimal::of((string) $line->inventory_value_removed_base)->isEqualTo($lineAllocActual)) {
                    throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                }

                $commercialH = BigDecimal::of((string) $line->line_total_base)
                    ->minus($line->purchase_tax_account_id !== null ? (string) $line->line_tax_base : '0');
                $expectedAdjustment = $lineActual->minus($commercialH);

                if (! BigDecimal::of((string) $line->valuation_adjustment_base)->isEqualTo($expectedAdjustment)) {
                    throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                }
            }

            $totalActualInventory = $totalActualInventory->plus($lineActual);

            if ($line->purchase_tax_account_id !== null) {
                $id = (int) $line->purchase_tax_account_id;
                $taxes[$id] ??= ['base' => BigDecimal::zero(), 'currency' => BigDecimal::zero()];
                $taxes[$id]['base'] = $taxes[$id]['base']->plus($line->line_tax_base);
                $taxes[$id]['currency'] = $taxes[$id]['currency']->plus($line->line_tax);
            }
        }

        if (StockMovement::where('company_id', $return->company_id)
            ->where('source_type', 'purchase_return')
            ->where('source_id', $return->id)
            ->count() !== $movementCount) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }

        // Check if zero-value return ($A=0, $T=0, $I=0, $D=0)
        if ($this->isZeroValue($return, $totalActualInventory)) {
            return null;
        }

        $apBase = BigDecimal::of((string) $return->grand_total_base);
        $apCurrency = BigDecimal::of((string) $return->grand_total_currency);

        $recoverableTaxBase = BigDecimal::zero();
        $recoverableTaxCurrency = BigDecimal::zero();
        foreach ($taxes as $tax) {
            $recoverableTaxBase = $recoverableTaxBase->plus($tax['base']);
            $recoverableTaxCurrency = $recoverableTaxCurrency->plus($tax['currency']);
        }

        // H = A - T (historical commercial capitalized inventory amount)
        $hBase = $apBase->minus($recoverableTaxBase);
        $hCurrency = $apCurrency->minus($recoverableTaxCurrency);

        // Fail closed if any positive transaction currency component is unrepresentable in canonical GL
        if ($apBase->isZero() && ! $apCurrency->isZero()) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }
        if ($hBase->isZero() && ! $hCurrency->isZero()) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }
        foreach ($taxes as $tax) {
            if ($tax['base']->isZero() && ! $tax['currency']->isZero()) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }
        }

        // I = actual inventory carrying value removed
        $iBase = $totalActualInventory;

        // D = I - H
        $dBase = $iBase->minus($hBase);

        $payable = LedgerAccount::where('company_id', $company->id)->where('system_key', 'accounts_payable')->firstOrFail();
        $inventory = LedgerAccount::where('company_id', $company->id)->where('system_key', 'inventory')->firstOrFail();
        $cogs = LedgerAccount::where('company_id', $company->id)->where('system_key', 'cogs')->firstOrFail();

        $rate = ExchangeRate::from($return->exchange_rate);
        $lines = [];
        $append = app(SalesPostingLines::class);

        // 1. Dr Accounts Payable (A)
        $append->append(
            $lines,
            1,
            (int) $payable->id,
            MoneyAmount::from($apBase),
            MoneyAmount::zero(),
            $return->currency_code,
            MoneyAmount::from($apCurrency),
            $rate,
            'Purchase return vendor payable reduction'
        );

        // 2. Cr Input Tax (T) grouped by account
        ksort($taxes);
        foreach ($taxes as $accountId => $tax) {
            $append->append(
                $lines,
                count($lines) + 1,
                $accountId,
                MoneyAmount::zero(),
                MoneyAmount::from($tax['base']),
                $return->currency_code,
                MoneyAmount::from($tax['currency']),
                $rate,
                'Purchase return Input Tax reversal'
            );
        }

        // 3. Cr Inventory (H)
        $append->append(
            $lines,
            count($lines) + 1,
            (int) $inventory->id,
            MoneyAmount::zero(),
            MoneyAmount::from($hBase),
            $return->currency_code,
            MoneyAmount::from($hCurrency),
            $rate,
            'Purchase return inventory commercial relief'
        );

        // 4. Valuation difference D = I - H (base only, no transaction currency metadata)
        if ($dBase->isPositive()) {
            // D > 0: Dr COGS D / Cr Inventory D
            $lines[] = new PostingLineCommand(
                lineNumber: count($lines) + 1,
                ledgerAccountId: (int) $cogs->id,
                debitBase: MoneyAmount::from($dBase),
                creditBase: MoneyAmount::zero(),
                description: 'Purchase return inventory valuation difference'
            );
            $lines[] = new PostingLineCommand(
                lineNumber: count($lines) + 1,
                ledgerAccountId: (int) $inventory->id,
                debitBase: MoneyAmount::zero(),
                creditBase: MoneyAmount::from($dBase),
                description: 'Purchase return inventory valuation difference'
            );
        } elseif ($dBase->isNegative()) {
            // D < 0: Dr Inventory abs(D) / Cr COGS abs(D)
            $absD = $dBase->abs();
            $lines[] = new PostingLineCommand(
                lineNumber: count($lines) + 1,
                ledgerAccountId: (int) $inventory->id,
                debitBase: MoneyAmount::from($absD),
                creditBase: MoneyAmount::zero(),
                description: 'Purchase return inventory valuation difference'
            );
            $lines[] = new PostingLineCommand(
                lineNumber: count($lines) + 1,
                ledgerAccountId: (int) $cogs->id,
                debitBase: MoneyAmount::zero(),
                creditBase: MoneyAmount::from($absD),
                description: 'Purchase return inventory valuation difference'
            );
        }

        // Assert exact invariants:
        // Net Inventory GL credit = I
        $inventoryDebit = BigDecimal::zero();
        $inventoryCredit = BigDecimal::zero();
        foreach ($lines as $pl) {
            if ($pl->ledgerAccountId === (int) $inventory->id) {
                $inventoryDebit = $inventoryDebit->plus($pl->debitBase->toDecimalString());
                $inventoryCredit = $inventoryCredit->plus($pl->creditBase->toDecimalString());
            }
        }
        $netInventoryCredit = $inventoryCredit->minus($inventoryDebit);
        if (! $netInventoryCredit->isEqualTo($iBase)) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }

        // Verify currency components
        $currencyComponents = [
            (int) $payable->id => $apCurrency,
            (int) $inventory->id => $hCurrency->negated(),
        ];
        foreach ($taxes as $accountId => $tax) {
            $currencyComponents[$accountId] = $tax['currency']->negated();
        }

        foreach ($currencyComponents as $accountId => $expectedCurrency) {
            $actualCurrency = BigDecimal::zero();
            foreach ($lines as $postingLine) {
                if ($postingLine->ledgerAccountId === $accountId && $postingLine->transactionAmount !== null) {
                    $amount = $postingLine->transactionAmount->getAmount();
                    $actualCurrency = $actualCurrency->plus($postingLine->debitBase->isPositive() ? $amount : $amount->negated());
                }
            }
            if (! $actualCurrency->isEqualTo($expectedCurrency)) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }
        }

        return new PostingCommand(
            company: $company,
            postingDate: $return->return_date,
            sourceType: 'purchase_return',
            sourceId: (int) $return->id,
            transactionCurrencyCode: $return->currency_code,
            baseCurrencyCode: $return->base_currency_code,
            exchangeRate: $rate,
            idempotencyKey: 'purchase_return_'.$return->id.'_posting',
            postedBy: $actor,
            description: 'Purchase return '.$number,
            batchNumber: $number,
            lines: $lines,
        );
    }

    public function validatePosted(PurchaseReturn $return): void
    {
        if ($return->status !== PurchaseReturn::STATUS_POSTED || ! $return->return_number
            || $return->posted_at === null || $return->posted_by === null) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }

        PurchaseReturnStockProvenance::validatePostedReturnIntegrity($return);

        $company = Company::findOrFail($return->company_id);
        $actor = User::findOrFail($return->posted_by);

        $command = $this->build($company, $return, $return->return_number, $actor, true);

        if ($command === null) {
            // Zero-value return must have posting_batch_id NULL
            if ($return->posting_batch_id !== null) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }

            return;
        }

        $batch = $return->postingBatch;
        if ($batch === null || $batch->status !== 'posted'
            || (int) $batch->company_id !== (int) $return->company_id
            || (int) $batch->posted_by !== (int) $return->posted_by
            || $batch->idempotency_key !== 'purchase_return_'.$return->id.'_posting'
            || ! $command->matchesBatch($batch)) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }
    }
}
