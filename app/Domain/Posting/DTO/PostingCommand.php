<?php

declare(strict_types=1);

namespace App\Domain\Posting\DTO;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\Exceptions\PostingValidationException;
use App\Models\Company;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class PostingCommand
{
    /**
     * @param  list<PostingLineCommand>  $lines
     */
    public function __construct(
        public Company $company,
        public DateTimeInterface $postingDate,
        public string $sourceType,
        public int $sourceId,
        public string $transactionCurrencyCode,
        public string $baseCurrencyCode,
        public ExchangeRate $exchangeRate,
        public string $idempotencyKey,
        public ?User $postedBy = null,
        public ?string $description = null,
        public ?string $batchNumber = null,
        public array $lines = [],
    ) {
        if ($this->sourceId <= 0) {
            throw new InvalidArgumentException("sourceId must be a positive integer. Given: {$this->sourceId}.");
        }

        if ($this->description !== null && mb_strlen($this->description) > 512) {
            throw new InvalidArgumentException('Description cannot exceed 512 characters.');
        }

        if ($this->batchNumber !== null && mb_strlen($this->batchNumber) > 64) {
            throw new InvalidArgumentException('Batch number cannot exceed 64 characters.');
        }

        if (count($this->lines) < 2) {
            throw PostingValidationException::minimumLinesRequired();
        }

        $seenLineNumbers = [];
        $totalDebit = BigDecimal::zero();
        $totalCredit = BigDecimal::zero();

        foreach ($this->lines as $line) {
            if (isset($seenLineNumbers[$line->lineNumber])) {
                throw PostingValidationException::duplicateLineNumbers();
            }
            $seenLineNumbers[$line->lineNumber] = true;

            $totalDebit = $totalDebit->plus($line->debitBase->getAmount());
            $totalCredit = $totalCredit->plus($line->creditBase->getAmount());
        }

        if (! $totalDebit->isEqualTo($totalCredit)) {
            throw PostingValidationException::debitsDoNotEqualCredits(
                (string) $totalDebit->toScale(6),
                (string) $totalCredit->toScale(6)
            );
        }
    }

    /**
     * Verify whether an existing persisted PostingBatch exactly matches this command's financial payload.
     */
    public function matchesBatch(PostingBatch $batch, ?int $reversalOfId = null): bool
    {
        if ($batch->source_type !== $this->sourceType) {
            return false;
        }

        if ((int) $batch->source_id !== $this->sourceId) {
            return false;
        }

        if ($batch->posting_date->format('Y-m-d') !== $this->postingDate->format('Y-m-d')) {
            return false;
        }

        if (strtoupper($batch->transaction_currency_code) !== strtoupper($this->transactionCurrencyCode)) {
            return false;
        }

        if (strtoupper($batch->base_currency_code) !== strtoupper($this->baseCurrencyCode)) {
            return false;
        }

        if (! ExchangeRate::from((string) $batch->exchange_rate)->equals($this->exchangeRate)) {
            return false;
        }

        if ($batch->description !== $this->description) {
            return false;
        }

        if ($batch->batch_number !== $this->batchNumber) {
            return false;
        }

        if ((int) ($batch->reversal_of_id ?? 0) !== (int) ($reversalOfId ?? 0)) {
            return false;
        }

        $existingLines = $batch->lines()->get();
        if ($existingLines->count() !== count($this->lines)) {
            return false;
        }

        /** @var array<int, PostingLine> $dbLinesByNumber */
        $dbLinesByNumber = [];
        foreach ($existingLines as $dbLine) {
            $dbLinesByNumber[(int) $dbLine->line_number] = $dbLine;
        }

        foreach ($this->lines as $cmdLine) {
            $dbLine = $dbLinesByNumber[$cmdLine->lineNumber] ?? null;
            if ($dbLine === null) {
                return false;
            }

            if ((int) $dbLine->ledger_account_id !== $cmdLine->ledgerAccountId) {
                return false;
            }

            if (! MoneyAmount::from((string) $dbLine->debit_base)->equals($cmdLine->debitBase)) {
                return false;
            }

            if (! MoneyAmount::from((string) $dbLine->credit_base)->equals($cmdLine->creditBase)) {
                return false;
            }

            if ($dbLine->description !== $cmdLine->description) {
                return false;
            }

            // Symmetric comparison of transaction_currency_code
            if (($dbLine->transaction_currency_code === null) !== ($cmdLine->transactionCurrencyCode === null)) {
                return false;
            }
            if ($cmdLine->transactionCurrencyCode !== null && strtoupper((string) $dbLine->transaction_currency_code) !== strtoupper($cmdLine->transactionCurrencyCode)) {
                return false;
            }

            // Symmetric comparison of transaction_amount
            if (($dbLine->transaction_amount === null) !== ($cmdLine->transactionAmount === null)) {
                return false;
            }
            if ($cmdLine->transactionAmount !== null) {
                if (! MoneyAmount::from((string) $dbLine->transaction_amount)->equals($cmdLine->transactionAmount)) {
                    return false;
                }
            }

            // Symmetric comparison of exchange_rate
            if (($dbLine->exchange_rate === null) !== ($cmdLine->exchangeRate === null)) {
                return false;
            }
            if ($cmdLine->exchangeRate !== null) {
                if (! ExchangeRate::from((string) $dbLine->exchange_rate)->equals($cmdLine->exchangeRate)) {
                    return false;
                }
            }
        }

        return true;
    }
}
