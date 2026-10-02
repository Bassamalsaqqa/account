<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingLineCommand;
use Brick\Math\BigDecimal;

/** Explicit base-only residual on the same account; never fabricated FX metadata or FX income. */
final class SalesPostingLines
{
    /** @param list<PostingLineCommand> $lines */
    public function append(array &$lines, int $lineNumber, int $ledgerAccountId, MoneyAmount $debitBase, MoneyAmount $creditBase, ?string $transactionCurrencyCode = null, ?MoneyAmount $transactionAmount = null, ?ExchangeRate $exchangeRate = null, ?string $description = null): void
    {
        $debit = $debitBase->isPositive();
        $target = $debit ? $debitBase : $creditBase;
        $computed = $transactionAmount !== null && $exchangeRate !== null ? $exchangeRate->toBase($transactionAmount) : $target;
        if ($computed->isPositive()) {
            $lines[] = new PostingLineCommand(count($lines) + 1, $ledgerAccountId, $debit ? $computed : MoneyAmount::zero(), $debit ? MoneyAmount::zero() : $computed,
                $transactionCurrencyCode, $transactionAmount, $exchangeRate, $description);
        }
        $residual = BigDecimal::of($target->toDecimalString())->minus($computed->toDecimalString());
        if (! $residual->isZero()) {
            $residualDebit = $residual->isPositive() ? $debit : ! $debit;
            $money = MoneyAmount::from($residual->abs());
            $lines[] = new PostingLineCommand(count($lines) + 1, $ledgerAccountId, $residualDebit ? $money : MoneyAmount::zero(), $residualDebit ? MoneyAmount::zero() : $money,
                description: 'Exact cumulative rounding residual');
        }
    }
}
