<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Application\Platform\TenantWriteAccess;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\OpeningTradeItem;
use App\Models\PostingBatch;
use App\Models\User;
use App\Services\Accounting\GenericOpeningPostingScope;
use App\Services\Posting\AccountingPostingService;
use App\Support\Tenancy\CompanyContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PostOpeningBalancesAction
{
    private ?GenericOpeningPostingScope $activeEventScope = null;

    public function ownsEventScope(GenericOpeningPostingScope $scope): bool
    {
        return $this->activeEventScope === $scope;
    }

    public function __construct(
        protected AccountingPostingService $postingService
    ) {}

    /**
     * Post balanced double-entry opening balances against Opening Balance Equity.
     *
     * @param  array<int, array{account_id: int, amount: string|MoneyAmount, description?: ?string}>  $assetBalances
     * @param  array<int, array{account_id: int, amount: string|MoneyAmount, description?: ?string}>  $liabilityBalances
     * @param  array<int, array{account_id: int, amount: string|MoneyAmount, description?: ?string}>  $equityBalances
     */
    public function execute(
        Company $company,
        DateTimeInterface $date,
        array $assetBalances = [],
        array $liabilityBalances = [],
        array $equityBalances = [],
        ?User $postedBy = null,
        ?string $idempotencyKey = null
    ): PostingBatch {
        $context = app(CompanyContext::class);

        if (! $context->hasCompany()) {
            throw new NoActiveCompanyException('Cannot post opening balances without an active company context.');
        }

        if ($context->companyId() !== $company->id) {
            throw new CompanyReassignmentException("Cannot post opening balances for company [{$company->id}] when active company is [{$context->companyId()}].");
        }

        $actingUser = $postedBy ?? $context->user();
        if ($actingUser === null) {
            throw new InvalidArgumentException('A valid active user must be specified to post opening balances.');
        }

        if ($assetBalances === [] && $liabilityBalances === [] && $equityBalances === []) {
            throw new InvalidArgumentException('At least one positive opening balance entry is required to post opening balances.');
        }

        return app(TenantWriteAccess::class)->transaction((int) $company->id, function () use (
            $company,
            $date,
            $assetBalances,
            $liabilityBalances,
            $equityBalances,
            $actingUser,
            $idempotencyKey
        ): PostingBatch {
            /** @var Company $lockedCompany */
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            if ($lockedCompany->status !== 'active') {
                throw new InvalidArgumentException("Cannot post opening balances for inactive company [{$lockedCompany->id}].");
            }

            // 1. Validate Opening Balance Equity account exists and is active
            /** @var LedgerAccount|null $obeAccount */
            $obeAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                ->where('system_key', 'opening_balance_equity')
                ->where('active', true)
                ->first();

            if ($obeAccount === null) {
                throw new InvalidArgumentException("Active Opening Balance Equity account not found for company [{$lockedCompany->id}].");
            }

            // 2. Validate all amounts are strictly positive
            foreach ($assetBalances as $entry) {
                $amount = $entry['amount'] instanceof MoneyAmount ? $entry['amount'] : MoneyAmount::from($entry['amount']);
                if ($amount->isNegative() || $amount->isZero()) {
                    throw new InvalidArgumentException("Opening balance amount for asset account [{$entry['account_id']}] must be strictly positive.");
                }
            }

            foreach ($liabilityBalances as $entry) {
                $amount = $entry['amount'] instanceof MoneyAmount ? $entry['amount'] : MoneyAmount::from($entry['amount']);
                if ($amount->isNegative() || $amount->isZero()) {
                    throw new InvalidArgumentException("Opening balance amount for liability account [{$entry['account_id']}] must be strictly positive.");
                }
            }

            foreach ($equityBalances as $entry) {
                $amount = $entry['amount'] instanceof MoneyAmount ? $entry['amount'] : MoneyAmount::from($entry['amount']);
                if ($amount->isNegative() || $amount->isZero()) {
                    throw new InvalidArgumentException("Opening balance amount for equity account [{$entry['account_id']}] must be strictly positive.");
                }
                if ((int) $entry['account_id'] === (int) $obeAccount->id) {
                    throw new InvalidArgumentException("Opening Balance Equity account [{$obeAccount->id}] cannot be used as an equity balance target.");
                }
            }

            // 3. Validate accounts exist, are active, belong to the company, and match their bucket type
            $assetAccountIds = array_unique(array_map(fn ($e) => (int) $e['account_id'], $assetBalances));
            $liabilityAccountIds = array_unique(array_map(fn ($e) => (int) $e['account_id'], $liabilityBalances));
            $equityAccountIds = array_unique(array_map(fn ($e) => (int) $e['account_id'], $equityBalances));

            $allAccountIds = array_unique(array_merge($assetAccountIds, $liabilityAccountIds, $equityAccountIds));

            /** @var Collection<int, LedgerAccount> $accounts */
            $accounts = LedgerAccount::where('company_id', $lockedCompany->id)
                ->whereIn('id', $allAccountIds)
                ->where('active', true)
                ->get()
                ->keyBy('id');

            foreach ($assetAccountIds as $accId) {
                $account = $accounts->get($accId);
                if ($account === null) {
                    throw new InvalidArgumentException("Asset account [{$accId}] does not exist, is inactive, or does not belong to company [{$lockedCompany->id}].");
                }
                if ($account->account_type !== LedgerAccount::TYPE_ASSET) {
                    throw new InvalidArgumentException("Account [{$accId}] has account type [{$account->account_type}], expected [asset].");
                }
            }

            foreach ($liabilityAccountIds as $accId) {
                $account = $accounts->get($accId);
                if ($account === null) {
                    throw new InvalidArgumentException("Liability account [{$accId}] does not exist, is inactive, or does not belong to company [{$lockedCompany->id}].");
                }
                if ($account->account_type !== LedgerAccount::TYPE_LIABILITY) {
                    throw new InvalidArgumentException("Account [{$accId}] has account type [{$account->account_type}], expected [liability].");
                }
            }

            foreach ($equityAccountIds as $accId) {
                $account = $accounts->get($accId);
                if ($account === null) {
                    throw new InvalidArgumentException("Equity account [{$accId}] does not exist, is inactive, or does not belong to company [{$lockedCompany->id}].");
                }
                if ($account->account_type !== LedgerAccount::TYPE_EQUITY) {
                    throw new InvalidArgumentException("Account [{$accId}] has account type [{$account->account_type}], expected [equity].");
                }
            }

            // Prevent future duplicate GL opening AR/AP under lock when new workflow enabled, while preserving generic posted history/retries
            $effectiveIdempotencyKey = $idempotencyKey ?? "opening-balance-{$lockedCompany->id}-{$date->format('Y-m-d')}";
            $existingBatch = DB::table('posting_batches')
                ->where('company_id', $lockedCompany->id)
                ->where('idempotency_key', $effectiveIdempotencyKey)
                ->first();

            if ($existingBatch === null) {
                foreach ($assetAccountIds as $accId) {
                    $account = $accounts->get($accId);
                    if ($account !== null && $account->system_key === 'accounts_receivable') {
                        if (OpeningTradeItem::where('company_id', $lockedCompany->id)->where('side', OpeningTradeItem::SIDE_CUSTOMER)->exists()) {
                            throw new InvalidArgumentException("Cannot post generic opening balance to accounts_receivable when customer opening trade items exist for company [{$lockedCompany->id}]. Use Opening Trade Items.");
                        }
                    }
                }

                foreach ($liabilityAccountIds as $accId) {
                    $account = $accounts->get($accId);
                    if ($account !== null && $account->system_key === 'accounts_payable') {
                        if (OpeningTradeItem::where('company_id', $lockedCompany->id)->where('side', OpeningTradeItem::SIDE_VENDOR)->exists()) {
                            throw new InvalidArgumentException("Cannot post generic opening balance to accounts_payable when vendor opening trade items exist for company [{$lockedCompany->id}]. Use Opening Trade Items.");
                        }
                    }
                }
            }

            $lines = [];
            $lineNumber = 1;

            // Assets: Dr Asset / Cr Opening Balance Equity
            foreach ($assetBalances as $entry) {
                $amount = $entry['amount'] instanceof MoneyAmount ? $entry['amount'] : MoneyAmount::from($entry['amount']);
                $lines[] = PostingLineCommand::debit(
                    lineNumber: $lineNumber++,
                    ledgerAccountId: (int) $entry['account_id'],
                    amount: $amount,
                    description: $entry['description'] ?? 'Opening balance asset',
                );
                $lines[] = PostingLineCommand::credit(
                    lineNumber: $lineNumber++,
                    ledgerAccountId: $obeAccount->id,
                    amount: $amount,
                    description: $entry['description'] ?? 'Opening balance offset',
                );
            }

            // Liabilities: Dr Opening Balance Equity / Cr Liability
            foreach ($liabilityBalances as $entry) {
                $amount = $entry['amount'] instanceof MoneyAmount ? $entry['amount'] : MoneyAmount::from($entry['amount']);
                $lines[] = PostingLineCommand::debit(
                    lineNumber: $lineNumber++,
                    ledgerAccountId: $obeAccount->id,
                    amount: $amount,
                    description: $entry['description'] ?? 'Opening balance offset',
                );
                $lines[] = PostingLineCommand::credit(
                    lineNumber: $lineNumber++,
                    ledgerAccountId: (int) $entry['account_id'],
                    amount: $amount,
                    description: $entry['description'] ?? 'Opening balance liability',
                );
            }

            // Other Equity: Dr Opening Balance Equity / Cr Equity
            foreach ($equityBalances as $entry) {
                $amount = $entry['amount'] instanceof MoneyAmount ? $entry['amount'] : MoneyAmount::from($entry['amount']);
                $lines[] = PostingLineCommand::debit(
                    lineNumber: $lineNumber++,
                    ledgerAccountId: $obeAccount->id,
                    amount: $amount,
                    description: $entry['description'] ?? 'Opening balance offset',
                );
                $lines[] = PostingLineCommand::credit(
                    lineNumber: $lineNumber++,
                    ledgerAccountId: (int) $entry['account_id'],
                    amount: $amount,
                    description: $entry['description'] ?? 'Opening balance equity',
                );
            }

            $command = new PostingCommand(
                company: $lockedCompany,
                postingDate: $date,
                sourceType: 'opening_balance',
                sourceId: $lockedCompany->id,
                transactionCurrencyCode: $lockedCompany->base_currency_code,
                baseCurrencyCode: $lockedCompany->base_currency_code,
                exchangeRate: ExchangeRate::one(),
                idempotencyKey: $effectiveIdempotencyKey,
                postedBy: $actingUser,
                description: "Opening balances as of {$date->format('Y-m-d')}",
                lines: $lines,
            );

            $scope = app(GenericOpeningPostingScope::class);
            $this->activeEventScope = $scope;
            try {
                return $scope->withPostingCapability(
                    $this,
                    (int) $lockedCompany->id,
                    fn () => $this->postingService->post($command)
                );
            } finally {
                $this->activeEventScope = null;
            }
        });
    }
}
