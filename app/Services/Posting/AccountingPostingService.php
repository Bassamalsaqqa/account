<?php

declare(strict_types=1);

namespace App\Services\Posting;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\Exceptions\IdempotencyConflictException;
use App\Domain\Posting\Exceptions\PostingValidationException;
use App\Domain\Posting\Exceptions\ReversalException;
use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyUser;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\User;
use App\Services\Money\MoneyEventScope;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AccountingPostingService
{
    /**
     * Canonical double-entry posting service.
     * Sole authorized entrypoint for persisting posting batches and lines.
     */
    public function post(PostingCommand $command): PostingBatch
    {
        if (in_array($command->sourceType, ['money_transfer', 'check_event'], true)) {
            app(MoneyEventScope::class)->assertCommand($command);
        }
        if (in_array($command->sourceType, ['customer_payment', 'vendor_payment'], true) && DB::table($command->sourceType === 'customer_payment' ? 'customer_payments' : 'vendor_payments')->where('company_id', $command->company->id)->where('id', $command->sourceId)->whereNotNull('check_id')->exists()) {
            app(MoneyEventScope::class)->assertCommand($command);
        }
        // Reversal batches cannot be created via post(). Use AccountingReversalService.
        if ($command->sourceType === 'reversal') {
            throw PostingValidationException::reversalOnlyAllowedViaService();
        }

        return DB::transaction(function () use ($command): PostingBatch {
            $context = app(CompanyContext::class);

            // 1. Tenancy Enforcement
            if (! $context->hasCompany()) {
                throw new NoActiveCompanyException('Cannot post accounting transaction without an active company context.');
            }

            if ($context->companyId() !== $command->company->id) {
                throw new CompanyReassignmentException("Cannot post for company [{$command->company->id}] when active company is [{$context->companyId()}].");
            }

            // 2. Consistent lock hierarchy: Lock Company first
            /** @var Company $lockedCompany */
            $lockedCompany = Company::where('id', $command->company->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCompany->status !== 'active') {
                throw PostingValidationException::companyInactive($lockedCompany->id);
            }

            // 3. Check idempotency key BEFORE checking mutable configuration!
            // An exact retry for a previously posted payload returns existing batch even after currency disabled / account deactivated
            $existing = PostingBatch::where('company_id', $lockedCompany->id)
                ->where('idempotency_key', $command->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->isReversed()) {
                    throw IdempotencyConflictException::alreadyReversed(
                        $command->idempotencyKey,
                        $existing->id
                    );
                }

                if (! $command->matchesBatch($existing, null)) {
                    throw IdempotencyConflictException::mismatchedPayload(
                        $command->idempotencyKey,
                        "existing batch #{$existing->id} ({$existing->source_type}:{$existing->source_id})",
                        "command ({$command->sourceType}:{$command->sourceId})"
                    );
                }

                return $existing;
            }

            // 4. For NEW postings: validate mutable configuration under lock
            // 4a. Poster membership
            if ($command->postedBy === null) {
                throw PostingValidationException::invalidPoster(
                    0,
                    $lockedCompany->id,
                    'Posted by user is required for normal posting operations.'
                );
            }

            /** @var CompanyUser|null $posterMembership */
            $posterMembership = CompanyUser::where('company_id', $lockedCompany->id)
                ->where('user_id', $command->postedBy->id)
                ->lockForUpdate()
                ->first();

            if ($posterMembership === null || $posterMembership->status !== 'active') {
                throw PostingValidationException::invalidPoster(
                    $command->postedBy->id,
                    $lockedCompany->id
                );
            }

            // 4b. Base currency matches locked company
            $companyBase = strtoupper($lockedCompany->base_currency_code);
            if (strtoupper($command->baseCurrencyCode) !== $companyBase) {
                throw PostingValidationException::invalidCurrency(
                    $command->baseCurrencyCode,
                    "Base currency must match company base currency [{$companyBase}]"
                );
            }

            // 4c. Lock and validate all CompanyCurrency rows and base currency invariants
            /** @var Collection<string, CompanyCurrency> $allCompanyCurrencies */
            $allCompanyCurrencies = CompanyCurrency::where('company_id', $lockedCompany->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('currency_code');

            $standardCurrencies = ['ILS', 'USD', 'JOD'];
            foreach ($standardCurrencies as $sc) {
                if (! isset($allCompanyCurrencies[$sc])) {
                    throw PostingValidationException::invalidCurrency(
                        $sc,
                        "Standard currency [{$sc}] is missing from company [{$lockedCompany->id}] configuration."
                    );
                }
            }

            $baseCurrencies = $allCompanyCurrencies->filter(fn (CompanyCurrency $c) => (bool) $c->is_base);
            if ($baseCurrencies->count() !== 1) {
                throw PostingValidationException::invalidCurrency(
                    $companyBase,
                    "Company currency configuration is invalid: exactly one base currency must be configured for company [{$lockedCompany->id}], found {$baseCurrencies->count()}."
                );
            }

            $baseRow = $allCompanyCurrencies->get($companyBase);
            if ($baseRow === null || ! $baseRow->enabled || ! (bool) $baseRow->is_base) {
                throw PostingValidationException::invalidCurrency(
                    $companyBase,
                    "Base currency [{$companyBase}] is not properly configured or enabled for company [{$lockedCompany->id}]."
                );
            }

            $batchTxCurrency = strtoupper($command->transactionCurrencyCode);
            if (! isset($allCompanyCurrencies[$batchTxCurrency]) || ! $allCompanyCurrencies[$batchTxCurrency]->enabled) {
                throw PostingValidationException::invalidCurrency(
                    $batchTxCurrency,
                    "Currency is not enabled for company [{$lockedCompany->id}]"
                );
            }

            if ($batchTxCurrency === $companyBase && ! $command->exchangeRate->isOne()) {
                throw PostingValidationException::invalidCurrency(
                    $batchTxCurrency,
                    'Exchange rate for company base currency must be exactly 1.0000000000'
                );
            }

            // Line metadata currencies: verify enabled, and any base-currency rate is exactly 1
            foreach ($command->lines as $line) {
                if ($line->transactionCurrencyCode !== null) {
                    $lineCurr = strtoupper($line->transactionCurrencyCode);
                    if (! isset($allCompanyCurrencies[$lineCurr]) || ! $allCompanyCurrencies[$lineCurr]->enabled) {
                        throw PostingValidationException::invalidCurrency(
                            $lineCurr,
                            "Currency is not enabled for company [{$lockedCompany->id}]"
                        );
                    }
                    if ($lineCurr === $companyBase && $line->exchangeRate !== null && ! $line->exchangeRate->isOne()) {
                        throw PostingValidationException::invalidCurrency(
                            $lineCurr,
                            'Exchange rate for company base currency must be exactly 1.0000000000'
                        );
                    }
                }
            }

            // 4d. Lock and validate Ledger Accounts
            $accountIds = array_unique(array_map(
                fn ($line) => $line->ledgerAccountId,
                $command->lines
            ));

            /** @var Collection<int, LedgerAccount> $validAccounts */
            $validAccounts = LedgerAccount::where('company_id', $lockedCompany->id)
                ->whereIn('id', $accountIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($accountIds as $accountId) {
                $account = $validAccounts->get($accountId);
                if ($account === null || ! $account->active) {
                    throw PostingValidationException::accountNotFoundOrInactive(
                        $accountId,
                        $lockedCompany->id
                    );
                }
            }

            // MoneyAccount currency is authoritative for new transaction metadata.
            // Legacy base-only opening balances remain accepted; exact residuals need a truthful paired currency line.
            $moneyAccounts = MoneyAccount::withTrashed()->where('company_id', $lockedCompany->id)->whereIn('ledger_account_id', $accountIds)->get()->keyBy('ledger_account_id');
            foreach ($moneyAccounts as $ledgerId => $moneyAccount) {
                $hasCurrencyLine = false;
                foreach ($command->lines as $line) {
                    if ($line->ledgerAccountId !== $ledgerId || $line->transactionCurrencyCode === null) {
                        continue;
                    }
                    if ($line->transactionCurrencyCode !== $moneyAccount->currency_code) {
                        throw new InvalidArgumentException('MoneyAccount posting currency must match the configured account currency.');
                    }
                    $hasCurrencyLine = true;
                }
                if (in_array($command->sourceType, ['money_transfer', 'customer_payment', 'vendor_payment', 'check_event'], true) && ! $hasCurrencyLine) {
                    throw new InvalidArgumentException('Canonical Money movement requires truthful transaction-currency metadata.');
                }
            }

            // 5. Persistence within atomic transaction with race-safe idempotency backstop
            try {
                $batch = PostingBatch::create([
                    'public_id' => (string) Str::ulid(),
                    'company_id' => $lockedCompany->id,
                    'batch_number' => $command->batchNumber,
                    'posting_date' => $command->postingDate,
                    'status' => PostingBatch::STATUS_POSTED,
                    'source_type' => $command->sourceType,
                    'source_id' => $command->sourceId,
                    'transaction_currency_code' => $command->transactionCurrencyCode,
                    'base_currency_code' => $command->baseCurrencyCode,
                    'exchange_rate' => $command->exchangeRate->toDecimalString(),
                    'description' => $command->description,
                    'idempotency_key' => $command->idempotencyKey,
                    'posted_by' => $command->postedBy->id,
                    'posted_at' => now(),
                    'reversal_of_id' => null,
                ]);

                foreach ($command->lines as $line) {
                    PostingLine::create([
                        'company_id' => $lockedCompany->id,
                        'posting_batch_id' => $batch->id,
                        'ledger_account_id' => $line->ledgerAccountId,
                        'line_number' => $line->lineNumber,
                        'description' => $line->description,
                        'debit_base' => $line->debitBase->toDecimalString(),
                        'credit_base' => $line->creditBase->toDecimalString(),
                        'transaction_currency_code' => $line->transactionCurrencyCode,
                        'transaction_amount' => $line->transactionAmount?->toDecimalString(),
                        'exchange_rate' => $line->exchangeRate?->toDecimalString(),
                        'created_at' => now(),
                    ]);
                }

                return $batch;
            } catch (QueryException $e) {
                // Handle concurrent race-condition on (company_id, idempotency_key)
                if (str_contains($e->getMessage(), 'idempotency_key') || ($e->errorInfo[1] ?? 0) === 1062) {
                    $raceExisting = PostingBatch::where('company_id', $lockedCompany->id)
                        ->where('idempotency_key', $command->idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($raceExisting !== null) {
                        if ($raceExisting->isReversed()) {
                            throw IdempotencyConflictException::alreadyReversed(
                                $command->idempotencyKey,
                                $raceExisting->id
                            );
                        }

                        if (! $command->matchesBatch($raceExisting, null)) {
                            throw IdempotencyConflictException::mismatchedPayload(
                                $command->idempotencyKey,
                                "existing batch #{$raceExisting->id} ({$raceExisting->source_type}:{$raceExisting->source_id})",
                                "command ({$command->sourceType}:{$command->sourceId})"
                            );
                        }

                        return $raceExisting;
                    }
                }

                throw $e;
            }
        });
    }

    /**
     * Authoritative internal double-entry reversal write operation.
     * Persists reversal batch, inverse lines, and reciprocal status linkage in one atomic transaction.
     */
    public function reverse(PostingBatch $original, User $actingUser, ?string $reason = null, ?string $postingDate = null): PostingBatch
    {
        if (in_array($original->source_type, ['customer_payment', 'vendor_payment'], true) && DB::table($original->source_type === 'customer_payment' ? 'customer_payments' : 'vendor_payments')->where('company_id', $original->company_id)->where('id', $original->source_id)->whereNotNull('check_id')->exists()) {
            app(MoneyEventScope::class)->assertReversal($original, $actingUser, $postingDate);
        }
        if (in_array($original->source_type, ['money_transfer', 'check_event'], true)) {
            app(MoneyEventScope::class)->assertReversal($original, $actingUser, $postingDate);
        }
        // Upfront validation of caller-supplied reason: reject excessive text before any DB writes/transactions
        if ($reason !== null && mb_strlen($reason) > 512) {
            throw new InvalidArgumentException('Reversal reason cannot exceed 512 characters.');
        }

        // Explicit business dates are date-only values, never timezone-converted.
        if ($postingDate !== null && (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $postingDate)
            || ! checkdate((int) substr($postingDate, 5, 2), (int) substr($postingDate, 8, 2), (int) substr($postingDate, 0, 4)))) {
            throw new InvalidArgumentException('Reversal posting date must be a valid YYYY-MM-DD date.');
        }

        return DB::transaction(function () use ($original, $actingUser, $reason, $postingDate): PostingBatch {
            $context = app(CompanyContext::class);

            // 1. Tenancy Enforcement
            if (! $context->hasCompany()) {
                throw new NoActiveCompanyException('Cannot reverse transaction without an active company context.');
            }

            if ($context->companyId() !== $original->company_id) {
                throw new CompanyReassignmentException("Cannot reverse batch for company [{$original->company_id}] when active company is [{$context->companyId()}].");
            }

            // 2. Lock company row first
            /** @var Company $lockedCompany */
            $lockedCompany = Company::where('id', $original->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCompany->status !== 'active') {
                throw ReversalException::companyInactive($lockedCompany->id);
            }

            // 3. Lock original batch
            /** @var PostingBatch $lockedOriginal */
            $lockedOriginal = PostingBatch::where('id', $original->id)
                ->where('company_id', $lockedCompany->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 4. Revalidate active membership within transaction under lock
            /** @var CompanyUser|null $member */
            $member = CompanyUser::where('company_id', $lockedCompany->id)
                ->where('user_id', $actingUser->id)
                ->lockForUpdate()
                ->first();

            if ($member === null || $member->status !== 'active') {
                throw PostingValidationException::invalidPoster(
                    $actingUser->id,
                    $lockedCompany->id,
                    "User [{$actingUser->id}] is not an active member of company [{$lockedCompany->id}]."
                );
            }

            // Repeat reversal idempotency: validate existing reversal reciprocal coherence
            if ($lockedOriginal->isReversed()) {
                if ($lockedOriginal->reversed_by_batch_id === null) {
                    throw ReversalException::incoherentReversalState(
                        "Batch [{$lockedOriginal->id}] is marked reversed but has no reversed_by_batch_id link."
                    );
                }

                /** @var PostingBatch|null $existingReversal */
                $existingReversal = PostingBatch::where('company_id', $lockedOriginal->company_id)
                    ->where('id', $lockedOriginal->reversed_by_batch_id)
                    ->first();

                if ($existingReversal === null) {
                    throw ReversalException::incoherentReversalState(
                        "Batch [{$lockedOriginal->id}] is marked reversed, but linked reversal batch [{$lockedOriginal->reversed_by_batch_id}] does not exist."
                    );
                }

                $this->validateReciprocalReversalBatch($lockedOriginal, $existingReversal);

                return $existingReversal;
            }

            if ($lockedOriginal->reversal_of_id !== null || $lockedOriginal->source_type === 'reversal') {
                throw ReversalException::cannotReverseReversal($lockedOriginal->id);
            }

            if (! $lockedOriginal->isPosted()) {
                throw ReversalException::notPosted($lockedOriginal->id, $lockedOriginal->status);
            }

            $reversalIdempotencyKey = "reversal-batch-{$lockedOriginal->public_id}";

            // Check if reversal batch was already created in a prior interrupted attempt
            /** @var PostingBatch|null $priorReversal */
            $priorReversal = PostingBatch::where('company_id', $lockedOriginal->company_id)
                ->where('idempotency_key', $reversalIdempotencyKey)
                ->first();

            if ($priorReversal !== null) {
                $this->validateReciprocalReversalBatch($lockedOriginal, $priorReversal);

                $affected = DB::table('posting_batches')
                    ->where('id', $lockedOriginal->id)
                    ->where('company_id', $lockedOriginal->company_id)
                    ->where('status', PostingBatch::STATUS_POSTED)
                    ->whereNull('reversed_by_batch_id')
                    ->update([
                        'status' => PostingBatch::STATUS_REVERSED,
                        'reversed_by_batch_id' => $priorReversal->id,
                        'updated_at' => now(),
                    ]);

                if ($affected !== 1) {
                    throw new ImmutableRecordException("Failed to atomically transition batch [{$lockedOriginal->id}] to reversed state.");
                }

                return $priorReversal;
            }

            // Multibyte-safe bounded batch description
            $rawBatchDesc = $reason !== null && trim($reason) !== ''
                ? "Reversal: {$reason}"
                : "Reversal of batch #{$lockedOriginal->public_id}";
            $batchDescription = mb_substr($rawBatchDesc, 0, 512);

            // Persist the reversal batch directly
            $reversalBatch = PostingBatch::create([
                'public_id' => (string) Str::ulid(),
                'company_id' => $lockedOriginal->company_id,
                'batch_number' => null,
                'posting_date' => $postingDate ?? now()->toDateString(),
                'status' => PostingBatch::STATUS_POSTED,
                'source_type' => 'reversal',
                'source_id' => (int) $lockedOriginal->id,
                'transaction_currency_code' => $lockedOriginal->transaction_currency_code,
                'base_currency_code' => $lockedOriginal->base_currency_code,
                'exchange_rate' => $lockedOriginal->exchange_rate,
                'description' => $batchDescription,
                'idempotency_key' => $reversalIdempotencyKey,
                'posted_by' => $actingUser->id,
                'posted_at' => now(),
                'reversal_of_id' => $lockedOriginal->id,
                'reversed_by_batch_id' => null,
            ]);

            // Build and persist opposite lines (Debit becomes Credit, Credit becomes Debit)
            // Reversal of historical lines is valid even if account was subsequently deactivated.
            $originalLines = $lockedOriginal->lines()->orderBy('line_number')->get();
            foreach ($originalLines as $line) {
                // Multibyte-safe bounded line description
                $rawLineDesc = $reason !== null && trim($reason) !== ''
                    ? "Reversal: {$reason}"
                    : ($line->description !== null && trim($line->description) !== '' ? "Reversal: {$line->description}" : "Reversal of line {$line->line_number}");
                $lineDescription = mb_substr($rawLineDesc, 0, 512);

                PostingLine::create([
                    'company_id' => $lockedOriginal->company_id,
                    'posting_batch_id' => $reversalBatch->id,
                    'ledger_account_id' => $line->ledger_account_id,
                    'line_number' => $line->line_number,
                    'description' => $lineDescription,
                    'debit_base' => $line->credit_base,
                    'credit_base' => $line->debit_base,
                    'transaction_currency_code' => $line->transaction_currency_code,
                    'transaction_amount' => $line->transaction_amount,
                    'exchange_rate' => $line->exchange_rate,
                    'created_at' => now(),
                ]);
            }

            // Cross-link original to the newly created reversal batch
            $affected = DB::table('posting_batches')
                ->where('id', $lockedOriginal->id)
                ->where('company_id', $lockedOriginal->company_id)
                ->where('status', PostingBatch::STATUS_POSTED)
                ->whereNull('reversed_by_batch_id')
                ->update([
                    'status' => PostingBatch::STATUS_REVERSED,
                    'reversed_by_batch_id' => $reversalBatch->id,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ImmutableRecordException("Failed to atomically transition batch [{$lockedOriginal->id}] to reversed state.");
            }

            return $reversalBatch;
        });
    }

    /**
     * Validate reciprocal coherence of a candidate reversal batch against original.
     */
    private function validateReciprocalReversalBatch(PostingBatch $original, PostingBatch $reversal): void
    {
        if ((int) $reversal->company_id !== (int) $original->company_id) {
            throw ReversalException::incoherentReversalState(
                "Reversal batch [{$reversal->id}] belongs to company [{$reversal->company_id}], expected [{$original->company_id}]."
            );
        }

        if ((int) $reversal->reversal_of_id !== (int) $original->id) {
            throw ReversalException::conflictingPriorReversal(
                (string) $reversal->idempotency_key,
                (int) $original->id,
                $reversal->reversal_of_id !== null ? (int) $reversal->reversal_of_id : null
            );
        }

        if ($reversal->status !== PostingBatch::STATUS_POSTED) {
            throw ReversalException::incoherentReversalState(
                "Reversal batch [{$reversal->id}] has status [{$reversal->status}], expected [posted]."
            );
        }

        if ($reversal->source_type !== 'reversal' || (int) $reversal->source_id !== (int) $original->id) {
            throw ReversalException::incoherentReversalState(
                "Reversal batch [{$reversal->id}] has invalid source type [{$reversal->source_type}] or source id [{$reversal->source_id}]."
            );
        }

        if ($reversal->reversed_by_batch_id !== null) {
            throw ReversalException::incoherentReversalState(
                "Reversal batch [{$reversal->id}] cannot itself be reversed."
            );
        }

        if ($reversal->base_currency_code !== $original->base_currency_code
            || $reversal->transaction_currency_code !== $original->transaction_currency_code
        ) {
            throw ReversalException::incoherentReversalState(
                "Reversal batch [{$reversal->id}] currencies do not match original batch [{$original->id}]."
            );
        }

        // Exact batch exchange rate equality
        if (! ExchangeRate::from((string) $reversal->exchange_rate)->equals(ExchangeRate::from((string) $original->exchange_rate))) {
            throw ReversalException::incoherentReversalState(
                "Reversal batch [{$reversal->id}] exchange rate [{$reversal->exchange_rate}] does not match original batch rate [{$original->exchange_rate}]."
            );
        }

        // Validate inverse lines
        $origLines = $original->lines()->orderBy('line_number')->get();
        $revLines = $reversal->lines()->orderBy('line_number')->get();

        if ($origLines->count() !== $revLines->count()) {
            throw ReversalException::incoherentReversalState(
                "Reversal batch [{$reversal->id}] line count [{$revLines->count()}] does not match original [{$origLines->count()}]."
            );
        }

        for ($i = 0; $i < $origLines->count(); $i++) {
            $origLine = $origLines[$i];
            $revLine = $revLines[$i];

            if ((int) $revLine->ledger_account_id !== (int) $origLine->ledger_account_id
                || (int) $revLine->line_number !== (int) $origLine->line_number
                || ! MoneyAmount::from((string) $revLine->debit_base)->equals(MoneyAmount::from((string) $origLine->credit_base))
                || ! MoneyAmount::from((string) $revLine->credit_base)->equals(MoneyAmount::from((string) $origLine->debit_base))
            ) {
                throw ReversalException::incoherentReversalState(
                    "Reversal batch [{$reversal->id}] line [{$revLine->line_number}] does not reciprocate original line [{$origLine->line_number}]."
                );
            }

            // Line transaction currency: null symmetry and exact equality
            if (($origLine->transaction_currency_code === null) !== ($revLine->transaction_currency_code === null)) {
                throw ReversalException::incoherentReversalState(
                    "Reversal batch [{$reversal->id}] line [{$revLine->line_number}] transaction currency code presence does not match original."
                );
            }
            if ($origLine->transaction_currency_code !== null && $revLine->transaction_currency_code !== $origLine->transaction_currency_code) {
                throw ReversalException::incoherentReversalState(
                    "Reversal batch [{$reversal->id}] line [{$revLine->line_number}] transaction currency code [{$revLine->transaction_currency_code}] does not match original [{$origLine->transaction_currency_code}]."
                );
            }

            // Line transaction amount: null symmetry and exact equality
            if (($origLine->transaction_amount === null) !== ($revLine->transaction_amount === null)) {
                throw ReversalException::incoherentReversalState(
                    "Reversal batch [{$reversal->id}] line [{$revLine->line_number}] transaction amount presence does not match original."
                );
            }
            if ($origLine->transaction_amount !== null) {
                if (! MoneyAmount::from((string) $revLine->transaction_amount)->equals(MoneyAmount::from((string) $origLine->transaction_amount))) {
                    throw ReversalException::incoherentReversalState(
                        "Reversal batch [{$reversal->id}] line [{$revLine->line_number}] transaction amount does not match original."
                    );
                }
            }

            // Line exchange rate: null symmetry and exact equality
            if (($origLine->exchange_rate === null) !== ($revLine->exchange_rate === null)) {
                throw ReversalException::incoherentReversalState(
                    "Reversal batch [{$reversal->id}] line [{$revLine->line_number}] exchange rate presence does not match original."
                );
            }
            if ($origLine->exchange_rate !== null) {
                if (! ExchangeRate::from((string) $revLine->exchange_rate)->equals(ExchangeRate::from((string) $origLine->exchange_rate))) {
                    throw ReversalException::incoherentReversalState(
                        "Reversal batch [{$reversal->id}] line [{$revLine->line_number}] exchange rate does not match original."
                    );
                }
            }
        }
    }
}
