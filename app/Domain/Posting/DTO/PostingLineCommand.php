<?php

declare(strict_types=1);

namespace App\Domain\Posting\DTO;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\Exceptions\PostingValidationException;
use InvalidArgumentException;

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
            throw new InvalidArgumentException('Line description cannot exceed 512 characters.');
        }

        $debitPositive = $this->debitBase->isPositive();
        $creditPositive = $this->creditBase->isPositive();

        // Exactly one direction must be positive, neither can be negative
        if ($this->debitBase->isNegative() || $this->creditBase->isNegative() || ($debitPositive && $creditPositive) || (! $debitPositive && ! $creditPositive)) {
            throw PostingValidationException::lineMustHaveSingleDirection($this->lineNumber);
        }

        // Metadata presence check: all 3 must be null, or all 3 must be present
        $hasCurrency = $this->transactionCurrencyCode !== null;
        $hasAmount = $this->transactionAmount !== null;
        $hasRate = $this->exchangeRate !== null;

        if (($hasCurrency || $hasAmount || $hasRate) && ! ($hasCurrency && $hasAmount && $hasRate)) {
            throw new InvalidArgumentException("Line {$this->lineNumber} metadata is incomplete: transactionCurrencyCode, transactionAmount, and exchangeRate must either all be null or all present.");
        }

        if ($hasCurrency && $hasAmount && $hasRate) {
            if (! preg_match('/^[A-Z]{3}$/', (string) $this->transactionCurrencyCode)) {
                throw new InvalidArgumentException("Line {$this->lineNumber} transactionCurrencyCode must be exactly 3 uppercase letters. Given: '{$this->transactionCurrencyCode}'.");
            }

            if (! $this->transactionAmount->isPositive()) {
                throw new InvalidArgumentException("Line {$this->lineNumber} transactionAmount must be positive.");
            }

            if (! $this->exchangeRate->getValue()->isPositive()) {
                throw new InvalidArgumentException("Line {$this->lineNumber} exchangeRate must be positive.");
            }

            $positiveBase = $debitPositive ? $this->debitBase : $this->creditBase;
            $expectedBase = $this->exchangeRate->toBase($this->transactionAmount);

            if (! $positiveBase->equals($expectedBase)) {
                throw new InvalidArgumentException(
                    "Line {$this->lineNumber} conversion mismatch: positive base amount [{$positiveBase->toDecimalString()}] does not equal transactionAmount [{$this->transactionAmount->toDecimalString()}] * exchangeRate [{$this->exchangeRate->toDecimalString()}], expected [{$expectedBase->toDecimalString()}]."
                );
            }
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
