<?php

declare(strict_types=1);

namespace App\Domain\Posting\DTO;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\Exceptions\PostingValidationException;

final readonly class PostingLineCommand
{
    public function __construct(
        public int $lineNumber,
        public int $ledgerAccountId,
        public MoneyAmount $debitBase,
        public MoneyAmount $creditBase,
        public ?string $transactionCurrencyCode = null,
        public ?MoneyAmount $transactionAmount = null,
        public ?ExchangeRate $exchangeRate = null,
        public ?string $description = null,
    ) {
        if ($this->lineNumber < 1) {
            throw new PostingValidationException("Line number must be >= 1. Given: {$this->lineNumber}");
        }

        if ($this->description !== null && mb_strlen($this->description) > 512) {
            throw new \InvalidArgumentException('Line description cannot exceed 512 characters.');
        }

        $debitPositive = $this->debitBase->isPositive();
        $creditPositive = $this->creditBase->isPositive();

        // Exactly one direction must be positive, neither can be negative
        if ($this->debitBase->isNegative() || $this->creditBase->isNegative() || ($debitPositive && $creditPositive) || (! $debitPositive && ! $creditPositive)) {
            throw PostingValidationException::lineMustHaveSingleDirection($this->lineNumber);
        }
    }

    public static function debit(
        int $lineNumber,
        int $ledgerAccountId,
        MoneyAmount|string|int $amount,
        ?string $transactionCurrencyCode = null,
        MoneyAmount|string|int|null $transactionAmount = null,
        ExchangeRate|string|int|null $exchangeRate = null,
        ?string $description = null,
    ): self {
        $debitVo = $amount instanceof MoneyAmount ? $amount : MoneyAmount::from($amount);
        $txVo = $transactionAmount !== null ? ($transactionAmount instanceof MoneyAmount ? $transactionAmount : MoneyAmount::from($transactionAmount)) : null;
        $rateVo = $exchangeRate !== null ? ($exchangeRate instanceof ExchangeRate ? $exchangeRate : ExchangeRate::from($exchangeRate)) : null;

        return new self(
            lineNumber: $lineNumber,
            ledgerAccountId: $ledgerAccountId,
            debitBase: $debitVo,
            creditBase: MoneyAmount::zero(),
            transactionCurrencyCode: $transactionCurrencyCode,
            transactionAmount: $txVo,
            exchangeRate: $rateVo,
            description: $description,
        );
    }

    public static function credit(
        int $lineNumber,
        int $ledgerAccountId,
        MoneyAmount|string|int $amount,
        ?string $transactionCurrencyCode = null,
        MoneyAmount|string|int|null $transactionAmount = null,
        ExchangeRate|string|int|null $exchangeRate = null,
        ?string $description = null,
    ): self {
        $creditVo = $amount instanceof MoneyAmount ? $amount : MoneyAmount::from($amount);
        $txVo = $transactionAmount !== null ? ($transactionAmount instanceof MoneyAmount ? $transactionAmount : MoneyAmount::from($transactionAmount)) : null;
        $rateVo = $exchangeRate !== null ? ($exchangeRate instanceof ExchangeRate ? $exchangeRate : ExchangeRate::from($exchangeRate)) : null;

        return new self(
            lineNumber: $lineNumber,
            ledgerAccountId: $ledgerAccountId,
            debitBase: MoneyAmount::zero(),
            creditBase: $creditVo,
            transactionCurrencyCode: $transactionCurrencyCode,
            transactionAmount: $txVo,
            exchangeRate: $rateVo,
            description: $description,
        );
    }
}
