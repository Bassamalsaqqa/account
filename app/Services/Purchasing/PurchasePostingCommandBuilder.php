<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Sales\SalesPostingLines;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class PurchasePostingCommandBuilder
{
    public function build(Company $company, Purchase $purchase, string $number, User $actor, bool $requirePosted = false): PostingCommand
    {
        $inventoryBase = BigDecimal::zero();
        $inventoryCurrency = BigDecimal::zero();
        $taxes = [];
        $movementCount = 0;
        $purchase->setRelation('lines', $purchase->lines()->withoutGlobalScopes()->get());
        foreach ($purchase->lines as $line) {
            if ((int) $line->company_id !== (int) $purchase->company_id) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }
            $line->setRelation('lots', $line->lots()->withoutGlobalScopes()->get());
            foreach ($line->lots as $lot) {
                if ((int) $lot->company_id !== (int) $purchase->company_id) {
                    throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
                }
            }
        }
        if ($purchase->lines->isEmpty()) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }
        foreach ($purchase->lines as $line) {
            $expected = app(PurchaseAcquisitionValue::class)->line($line, $line->purchase_tax_account_id);
            if ($line->stock_movement_id === null || $line->inventory_unit_cost_base === null
                || ! BigDecimal::of($line->inventory_unit_cost_base)->isEqualTo(app(PurchaseAcquisitionValue::class)->unitCost($line, $expected))) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }
            $movements = StockMovement::where('company_id', $purchase->company_id)->where('source_type', 'purchase')
                ->where('source_id', $purchase->id)->where('source_line_id', $line->id)->orderBy('id')->get();
            if ($movements->isEmpty() || (int) $movements->first()->id !== (int) $line->stock_movement_id
                || $movements->count() !== max(1, $line->lots->count())) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }
            $actual = BigDecimal::zero();
            $movementCount += $movements->count();
            foreach ($movements as $movement) {
                $actual = $actual->plus(app(PurchaseStockProvenance::class)->value($movement, $requirePosted));
            }
            if (! $actual->isEqualTo($expected)) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }
            $inventoryBase = $inventoryBase->plus($actual);
            $inventoryCurrency = $inventoryCurrency->plus(BigDecimal::of($line->line_total)->minus($line->purchase_tax_account_id === null ? '0' : $line->line_tax));
            if ($line->purchase_tax_account_id !== null) {
                $id = (int) $line->purchase_tax_account_id;
                $taxes[$id] ??= ['base' => BigDecimal::zero(), 'currency' => BigDecimal::zero()];
                $taxes[$id]['base'] = $taxes[$id]['base']->plus($line->line_tax_base);
                $taxes[$id]['currency'] = $taxes[$id]['currency']->plus($line->line_tax);
            }
        }
        $totalBase = $inventoryBase;
        $totalCurrency = $inventoryCurrency;
        foreach ($taxes as $tax) {
            $totalBase = $totalBase->plus($tax['base']);
            $totalCurrency = $totalCurrency->plus($tax['currency']);
        }
        if (! $totalBase->isEqualTo($purchase->grand_total_base) || ! $totalCurrency->isEqualTo($purchase->grand_total_currency)
            || StockMovement::where('company_id', $purchase->company_id)->where('source_type', 'purchase')->where('source_id', $purchase->id)->count() !== $movementCount) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }
        $inventory = LedgerAccount::where('company_id', $company->id)->where('system_key', 'inventory')->firstOrFail();
        $payable = LedgerAccount::where('company_id', $company->id)->where('system_key', 'accounts_payable')->firstOrFail();
        $rate = ExchangeRate::from($purchase->exchange_rate);
        $lines = [];
        $append = app(SalesPostingLines::class);
        $append->append($lines, 1, (int) $inventory->id, MoneyAmount::from($inventoryBase), MoneyAmount::zero(),
            $purchase->currency_code, MoneyAmount::from($inventoryCurrency), $rate, 'Purchase inventory');
        ksort($taxes);
        foreach ($taxes as $accountId => $tax) {
            $append->append($lines, count($lines) + 1, $accountId, MoneyAmount::from($tax['base']), MoneyAmount::zero(),
                $purchase->currency_code, MoneyAmount::from($tax['currency']), $rate, 'Purchase Input Tax');
        }
        $append->append($lines, count($lines) + 1, (int) $payable->id, MoneyAmount::zero(), MoneyAmount::from($totalBase),
            $purchase->currency_code, MoneyAmount::from($totalCurrency), $rate, 'Purchase supplier liability');
        $currencyComponents = [(int) $inventory->id => $inventoryCurrency, (int) $payable->id => $totalCurrency->negated()];
        foreach ($taxes as $accountId => $tax) {
            $currencyComponents[$accountId] = $tax['currency'];
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
                // Canonical GL lines cannot carry a zero-base foreign component.
                // Reject instead of silently dropping its transaction metadata.
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }
        }

        return new PostingCommand($company, $purchase->purchase_date, 'purchase', (int) $purchase->id,
            $purchase->currency_code, $purchase->base_currency_code, $rate, 'purchase_'.$purchase->id.'_posting',
            $actor, 'Purchase '.$number, $number, $lines);
    }

    public function validatePosted(Purchase $purchase): void
    {
        $batch = $purchase->postingBatch;
        if ($purchase->status !== Purchase::STATUS_POSTED || ! $purchase->purchase_number || $purchase->posted_at === null
            || $purchase->posted_by === null || $batch === null || $batch->status !== 'posted'
            || (int) $batch->company_id !== (int) $purchase->company_id || (int) $batch->posted_by !== (int) $purchase->posted_by
            || $batch->idempotency_key !== 'purchase_'.$purchase->id.'_posting') {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }
        $company = Company::findOrFail($purchase->company_id);
        $actor = User::findOrFail($purchase->posted_by);
        if (! $this->build($company, $purchase, $purchase->purchase_number, $actor, true)->matchesBatch($batch)) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }
    }
}
