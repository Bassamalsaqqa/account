<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Application\Platform\TenantWriteAccess;
use App\Domain\Accounting\Exceptions\LegacyGlOpeningOverlapException;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Domain\Posting\Exceptions\IdempotencyConflictException;
use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\OpeningTradeItem;
use App\Models\User;
use App\Models\Vendor;
use App\Rules\PositiveExactDecimal;
use App\Services\Accounting\OpeningTradeEventScope;
use App\Services\Accounting\OpeningTradePostingCapability;
use App\Services\Audit\AuditService;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Money\MoneyValues;
use App\Services\Posting\AccountingPostingService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PostOpeningTradeItemAction
{
    public static function hasLegacyOverlap(int $companyId, string $side): bool
    {
        $systemKey = $side === OpeningTradeItem::SIDE_CUSTOMER ? 'accounts_receivable' : 'accounts_payable';
        $controlAccount = LedgerAccount::where('company_id', $companyId)
            ->where('system_key', $systemKey)
            ->where('active', true)
            ->first();

        if ($controlAccount === null) {
            return false;
        }

        return DB::table('posting_lines')
            ->join('posting_batches', 'posting_batches.id', '=', 'posting_lines.posting_batch_id')
            ->where('posting_lines.company_id', $companyId)
            ->where('posting_lines.ledger_account_id', $controlAccount->id)
            ->where('posting_batches.source_type', 'opening_balance')
            ->where('posting_batches.status', 'posted')
            ->exists();
    }

    private ?OpeningTradeEventScope $activeEventScope = null;

    public function ownsEventScope(OpeningTradeEventScope $scope): bool
    {
        return $this->activeEventScope === $scope;
    }

    public function __construct(
        protected AccountingPostingService $postingService,
        protected OpeningTradeEventScope $eventScope,
        protected AuditService $auditService,
    ) {}

    /**
     * Post a single opening trade item (used by standalone API or individual calls).
     *
     * @param  array<string, mixed>|null  $rowsetContext
     */
    public function execute(
        Company $company,
        string $side,
        int $partyId,
        DateTimeInterface|string $openingDate,
        string $amount,
        string $currencyCode,
        ?string $exchangeRate = null,
        ?User $actor = null,
        ?string $idempotencyKey = null,
        ?string $originalReference = null,
        DateTimeInterface|string|null $dueDate = null,
        ?string $notes = null,
        string $sourceIdentity = 'manual',
        ?array $rowsetContext = null,
    ): OpeningTradeItem {
        $context = app(CompanyContext::class);

        if (! $context->hasCompany()) {
            throw new NoActiveCompanyException('Cannot post opening trade item without an active company context.');
        }

        if ((int) $context->companyId() !== (int) $company->id) {
            throw new CompanyReassignmentException("Cannot post opening trade item for company [{$company->id}] when active company is [{$context->companyId()}].");
        }

        $actingUser = $actor ?? $context->user();
        if ($actingUser === null) {
            throw new InvalidArgumentException('A valid active user must be specified.');
        }

        $authUser = auth()->user();
        if ($authUser !== null && (int) $authUser->id !== (int) $actingUser->id) {
            throw new AuthorizationException("Authenticated user does not match action actor [{$actingUser->id}].");
        }

        if ($side === OpeningTradeItem::SIDE_CUSTOMER) {
            if (! $actingUser->hasPermissionTo('customers.manage') || ! $actingUser->hasPermissionTo('settings.accounting.manage')) {
                throw new AuthorizationException('Posting opening customer receivables requires customers.manage and settings.accounting.manage permissions.');
            }
        } elseif ($side === OpeningTradeItem::SIDE_VENDOR) {
            if (! $actingUser->hasPermissionTo('vendors.manage') || ! $actingUser->hasPermissionTo('settings.accounting.manage') || ! $actingUser->hasPermissionTo('purchasing.cost.view')) {
                throw new AuthorizationException('Posting opening supplier payables requires vendors.manage, settings.accounting.manage, and purchasing.cost.view permissions.');
            }
        } else {
            throw new InvalidArgumentException("Invalid side [{$side}]. Must be 'customer' or 'vendor'.");
        }

        $amountDec = BigDecimal::of($amount);
        if ($amountDec->isNegative() || $amountDec->isZero()) {
            throw new InvalidArgumentException('Opening trade item amount must be strictly positive.');
        }
        $amountStr = (string) $amountDec->toScale(6);

        $companyBase = strtoupper((string) $company->base_currency_code);
        $currencyCode = strtoupper(trim($currencyCode));

        if ($currencyCode === $companyBase) {
            if ($exchangeRate !== null && ! BigDecimal::of($exchangeRate)->isEqualTo(1)) {
                throw new InvalidArgumentException("Exchange rate for base currency [{$companyBase}] must be exactly 1.");
            }
            $rateStr = '1.0000000000';
        } else {
            if ($exchangeRate === null) {
                throw new InvalidArgumentException("Exchange rate is required for foreign currency [{$currencyCode}].");
            }
            $rateDec = BigDecimal::of($exchangeRate);
            if ($rateDec->isNegative() || $rateDec->isZero()) {
                throw new InvalidArgumentException("Exchange rate for foreign currency [{$currencyCode}] must be strictly positive.");
            }
            $rateStr = (string) $rateDec->toScale(10);
        }

        $carryingBaseStr = (string) BigDecimal::of($amountStr)->multipliedBy(BigDecimal::of($rateStr))->toScale(6, RoundingMode::HALF_UP);

        $openingDateStr = $openingDate instanceof DateTimeInterface ? $openingDate->format('Y-m-d') : (string) $openingDate;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $openingDateStr)) {
            throw new InvalidArgumentException('Opening date must be in YYYY-MM-DD format.');
        }
        if ($openingDateStr > now($company->timezone)->toDateString()) {
            throw new InvalidArgumentException('Opening date cannot be in the future.');
        }

        $dueDateStr = null;
        if ($dueDate !== null) {
            $dueDateStr = $dueDate instanceof DateTimeInterface ? $dueDate->format('Y-m-d') : (string) $dueDate;
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dueDateStr)) {
                throw new InvalidArgumentException('Due date must be in YYYY-MM-DD format.');
            }
        }

        $cleanRef = $originalReference !== null && trim($originalReference) !== '' ? trim($originalReference) : null;
        $idempotencyKey = $idempotencyKey ?? "oti:{$company->id}:{$side}:{$partyId}:{$openingDateStr}:".hash('sha256', ($cleanRef ?? '').$amountStr.$currencyCode);

        $payloadForHash = [
            'company_id' => (int) $company->id,
            'side' => $side,
            'party_id' => $partyId,
            'opening_date' => $openingDateStr,
            'currency_code' => $currencyCode,
            'amount_currency' => $amountStr,
            'exchange_rate' => $rateStr,
            'original_reference' => $cleanRef,
            'due_date' => $dueDateStr,
        ];
        if ($rowsetContext !== null) {
            $payloadForHash['rowset'] = $rowsetContext;
        }
        $payloadHash = hash('sha256', json_encode($payloadForHash, JSON_THROW_ON_ERROR));

        return app(TenantWriteAccess::class)->transaction((int) $company->id, function () use (
            $company,
            $side,
            $partyId,
            $openingDateStr,
            $cleanRef,
            $dueDateStr,
            $currencyCode,
            $amountStr,
            $rateStr,
            $carryingBaseStr,
            $actingUser,
            $idempotencyKey,
            $payloadHash,
            $sourceIdentity,
            $notes,
            $rowsetContext,
        ): OpeningTradeItem {
            /** @var Company $lockedCompany */
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            if ($lockedCompany->status !== 'active') {
                throw new InvalidArgumentException("Cannot post opening trade items on inactive company [{$lockedCompany->id}].");
            }

            $member = CompanyUser::where('company_id', $lockedCompany->id)
                ->where('user_id', $actingUser->id)
                ->lockForUpdate()
                ->first();
            if ($member === null || $member->status !== 'active') {
                throw new AuthorizationException("User [{$actingUser->id}] is not an active member of company [{$lockedCompany->id}].");
            }

            /** @var OpeningTradeItem|null $existing */
            $existing = OpeningTradeItem::where('company_id', $lockedCompany->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->idempotency_payload_hash !== $payloadHash) {
                    throw new IdempotencyConflictException("Opening trade item idempotency key [{$idempotencyKey}] was already used with a different economic payload.");
                }

                return $existing;
            }

            if ($rowsetContext !== null) {
                $formKey = (string) ($rowsetContext['form_key'] ?? '');
                $expectedRowsetHash = (string) ($rowsetContext['rowset_hash'] ?? '');
                if ($formKey !== '' && $expectedRowsetHash !== '') {
                    $divergentItem = OpeningTradeItem::where('company_id', $lockedCompany->id)
                        ->where(function ($q) use ($formKey): void {
                            $q->where('idempotency_key', $formKey)
                                ->orWhere('idempotency_key', 'like', $formKey.':%');
                        })
                        ->where(function ($q) use ($expectedRowsetHash): void {
                            $q->whereNull('control_route->rowset->rowset_hash')
                                ->orWhere('control_route->rowset->rowset_hash', '!=', $expectedRowsetHash);
                        })
                        ->first();

                    if ($divergentItem !== null) {
                        throw new IdempotencyConflictException("Opening trade form [{$formKey}] has committed items with a different row set identity.");
                    }
                }
            }

            try {
                $scale = app(CurrencyMinorUnitsResolver::class)->resolveFreshForCompany($lockedCompany, $currencyCode, lockForUpdate: true);
                PositiveExactDecimal::parse($amountStr, $scale);
                MoneyValues::amount($amountStr, $currencyCode);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException("Opening trade item amount [{$amountStr}] violates currency minor unit precision for [{$currencyCode}]: {$e->getMessage()}", 0, $e);
            }

            if ($side === OpeningTradeItem::SIDE_CUSTOMER) {
                /** @var Customer|null $customer */
                $customer = Customer::where('company_id', $lockedCompany->id)
                    ->where('id', $partyId)
                    ->lockForUpdate()
                    ->first();

                if ($customer === null || $customer->status !== 'active') {
                    throw new InvalidArgumentException("Customer [{$partyId}] does not exist, is inactive, or does not belong to company [{$lockedCompany->id}].");
                }

                $partySnapshot = [
                    'id' => $customer->id,
                    'name_ar' => $customer->name_ar,
                    'name_en' => $customer->name_en,
                    'currency_code' => $customer->default_currency_code,
                    'code' => $customer->code,
                ];
            } else {
                /** @var Vendor|null $vendor */
                $vendor = Vendor::where('company_id', $lockedCompany->id)
                    ->where('id', $partyId)
                    ->lockForUpdate()
                    ->first();

                if ($vendor === null || $vendor->status !== 'active') {
                    throw new InvalidArgumentException("Vendor [{$partyId}] does not exist, is inactive, or does not belong to company [{$lockedCompany->id}].");
                }

                $partySnapshot = [
                    'id' => $vendor->id,
                    'name_ar' => $vendor->name_ar,
                    'name_en' => $vendor->name_en,
                    'currency_code' => $vendor->default_currency_code,
                    'code' => $vendor->code,
                ];
            }

            $obeAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                ->where('system_key', 'opening_balance_equity')
                ->where('active', true)
                ->first();

            if ($obeAccount === null) {
                throw new InvalidArgumentException("Active Opening Balance Equity account not found for company [{$lockedCompany->id}].");
            }

            if ($side === OpeningTradeItem::SIDE_CUSTOMER) {
                $controlAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                    ->where('system_key', 'accounts_receivable')
                    ->where('active', true)
                    ->firstOrFail();

                if (self::hasLegacyOverlap((int) $lockedCompany->id, $side)) {
                    throw new LegacyGlOpeningOverlapException('تعذر قيد الرصيد (تداخل مع الأستاذ العام): General ledger already contains unreviewed generic opening balances for Accounts Receivable. Ambiguous legacy entries must be reviewed before opening customer receivables can be recognized.');
                }
            } else {
                $controlAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                    ->where('system_key', 'accounts_payable')
                    ->where('active', true)
                    ->firstOrFail();

                if (self::hasLegacyOverlap((int) $lockedCompany->id, $side)) {
                    throw new LegacyGlOpeningOverlapException('تعذر قيد الرصيد (تداخل مع الأستاذ العام): General ledger already contains unreviewed generic opening balances for Accounts Payable. Ambiguous legacy entries must be reviewed before opening supplier payables can be recognized.');
                }
            }

            $publicId = (string) Str::ulid();
            $companySnapshot = [
                'id' => $lockedCompany->id,
                'name_ar' => $lockedCompany->name_ar,
                'name_en' => $lockedCompany->name_en,
                'base_currency_code' => $lockedCompany->base_currency_code,
            ];

            $controlRoute = [
                'control_account_id' => $controlAccount->id,
                'control_account_key' => $controlAccount->system_key,
                'offset_account_id' => $obeAccount->id,
                'offset_account_key' => $obeAccount->system_key,
            ];
            if ($rowsetContext !== null) {
                $controlRoute['rowset'] = $rowsetContext;
            }

            $lines = [];
            $lineCurrency = $currencyCode;
            $lineAmount = MoneyAmount::from($amountStr);
            $lineFx = ExchangeRate::from($rateStr);
            $partyLabel = (string) ($partySnapshot['name_ar'] ?? $partySnapshot['name_en'] ?? $partySnapshot['code'] ?? "ID {$partyId}");

            if ($side === OpeningTradeItem::SIDE_CUSTOMER) {
                $lines[] = PostingLineCommand::debit(
                    lineNumber: 1,
                    ledgerAccountId: $controlAccount->id,
                    amount: MoneyAmount::from($carryingBaseStr),
                    description: "Opening customer receivable: {$partyLabel}".($cleanRef ? " [Ref: {$cleanRef}]" : ''),
                    transactionCurrencyCode: $lineCurrency,
                    transactionAmount: $lineAmount,
                    exchangeRate: $lineFx,
                );
                $lines[] = PostingLineCommand::credit(
                    lineNumber: 2,
                    ledgerAccountId: $obeAccount->id,
                    amount: MoneyAmount::from($carryingBaseStr),
                    description: "Opening balance equity offset: {$partyLabel}",
                    transactionCurrencyCode: $lineCurrency,
                    transactionAmount: $lineAmount,
                    exchangeRate: $lineFx,
                );
            } else {
                $lines[] = PostingLineCommand::debit(
                    lineNumber: 1,
                    ledgerAccountId: $obeAccount->id,
                    amount: MoneyAmount::from($carryingBaseStr),
                    description: "Opening balance equity offset: {$partyLabel}",
                    transactionCurrencyCode: $lineCurrency,
                    transactionAmount: $lineAmount,
                    exchangeRate: $lineFx,
                );
                $lines[] = PostingLineCommand::credit(
                    lineNumber: 2,
                    ledgerAccountId: $controlAccount->id,
                    amount: MoneyAmount::from($carryingBaseStr),
                    description: "Opening supplier payable: {$partyLabel}".($cleanRef ? " [Ref: {$cleanRef}]" : ''),
                    transactionCurrencyCode: $lineCurrency,
                    transactionAmount: $lineAmount,
                    exchangeRate: $lineFx,
                );
            }

            $this->activeEventScope = $this->eventScope;
            $capability = new OpeningTradePostingCapability($lockedCompany->id, $actingUser->id, $side, $partyId);
            try {
                $item = $this->eventScope->withPostingCapability(
                    $this,
                    $capability,
                    function () use (
                        $lockedCompany,
                        $publicId,
                        $side,
                        $partyId,
                        $openingDateStr,
                        $cleanRef,
                        $dueDateStr,
                        $currencyCode,
                        $amountStr,
                        $rateStr,
                        $carryingBaseStr,
                        $partySnapshot,
                        $companySnapshot,
                        $controlRoute,
                        $actingUser,
                        $sourceIdentity,
                        $idempotencyKey,
                        $payloadHash,
                        $notes,
                        $lines,
                        $lineFx,
                        $partyLabel,
                        $capability
                    ): OpeningTradeItem {
                        $item = new OpeningTradeItem([
                            'company_id' => $lockedCompany->id,
                            'public_id' => $publicId,
                            'side' => $side,
                            'customer_id' => $side === OpeningTradeItem::SIDE_CUSTOMER ? $partyId : null,
                            'vendor_id' => $side === OpeningTradeItem::SIDE_VENDOR ? $partyId : null,
                            'opening_date' => $openingDateStr,
                            'original_reference' => $cleanRef,
                            'due_date' => $dueDateStr,
                            'currency_code' => $currencyCode,
                            'amount_currency' => $amountStr,
                            'exchange_rate' => $rateStr,
                            'carrying_value_base' => $carryingBaseStr,
                            'party_snapshot' => $partySnapshot,
                            'company_snapshot' => $companySnapshot,
                            'control_route' => $controlRoute,
                            'created_by' => $actingUser->id,
                            'source_identity' => $sourceIdentity,
                            'idempotency_key' => $idempotencyKey,
                            'idempotency_payload_hash' => $payloadHash,
                            'status' => OpeningTradeItem::STATUS_POSTED,
                            'notes' => $notes,
                        ]);
                        $capability->authorizeItem($item);
                        $item->save();

                        $postingCommand = new PostingCommand(
                            company: $lockedCompany,
                            postingDate: Carbon::parse($openingDateStr),
                            sourceType: 'opening_trade_item',
                            sourceId: $item->id,
                            transactionCurrencyCode: $currencyCode,
                            baseCurrencyCode: $lockedCompany->base_currency_code,
                            exchangeRate: $lineFx,
                            idempotencyKey: "oti-gl:{$item->id}",
                            postedBy: $actingUser,
                            description: "Opening {$side} balance: {$partyLabel}",
                            lines: $lines,
                        );

                        $batch = $this->postingService->post($postingCommand);
                        if ($batch->source_type !== 'opening_trade_item' || (int) $batch->source_id !== (int) $item->id) {
                            throw new InvalidArgumentException('Original journal source identity must match opening trade item.');
                        }

                        $capability->authorizeBatchLink((int) $item->id, (int) $batch->id);
                        $item->posting_batch_id = $batch->id;
                        $item->save();

                        return $item;
                    }
                );
            } finally {
                $this->activeEventScope = null;
            }

            $this->auditService->log(
                companyId: (int) $lockedCompany->id,
                eventKey: 'accounting.opening_trade_item.posted',
                summary: "Posted opening {$side} trade balance #{$item->public_id}",
                actorUserId: (int) $actingUser->id,
                subject: $item,
                after: [
                    'public_id' => $item->public_id,
                    'side' => $item->side,
                    'party_id' => $partyId,
                    'amount' => $item->amount_currency,
                    'currency' => $item->currency_code,
                    'exchange_rate' => $item->exchange_rate,
                    'carrying_value_base' => $item->carrying_value_base,
                    'posting_batch_id' => $item->posting_batch_id,
                ],
            );

            return $item;
        });
    }

    /**
     * Post a complete opening trade balance form atomically under a single company lock.
     *
     * @param  array<int, array{amount: string, original_reference?: ?string, due_date?: ?string, notes?: ?string}>  $rows
     * @return list<OpeningTradeItem>
     */
    public function executeForm(
        Company $company,
        string $side,
        int $partyId,
        DateTimeInterface|string $openingDate,
        string $currencyCode,
        ?string $exchangeRate,
        array $rows,
        User $actor,
        string $formIdempotencyKey,
        string $sourceIdentity = 'manual_web',
    ): array {
        $context = app(CompanyContext::class);

        if (! $context->hasCompany()) {
            throw new NoActiveCompanyException('Cannot post opening trade form without an active company context.');
        }

        if ((int) $context->companyId() !== (int) $company->id) {
            throw new CompanyReassignmentException("Cannot post opening trade form for company [{$company->id}] when active company is [{$context->companyId()}].");
        }

        $authUser = auth()->user();
        if ($authUser !== null && (int) $authUser->id !== (int) $actor->id) {
            throw new AuthorizationException("Authenticated user does not match action actor [{$actor->id}].");
        }

        if ($side === OpeningTradeItem::SIDE_CUSTOMER) {
            if (! $actor->hasPermissionTo('customers.manage') || ! $actor->hasPermissionTo('settings.accounting.manage')) {
                throw new AuthorizationException('Posting opening customer receivables requires customers.manage and settings.accounting.manage permissions.');
            }
        } elseif ($side === OpeningTradeItem::SIDE_VENDOR) {
            if (! $actor->hasPermissionTo('vendors.manage') || ! $actor->hasPermissionTo('settings.accounting.manage') || ! $actor->hasPermissionTo('purchasing.cost.view')) {
                throw new AuthorizationException('Posting opening supplier payables requires vendors.manage, settings.accounting.manage, and purchasing.cost.view permissions.');
            }
        } else {
            throw new InvalidArgumentException("Invalid side [{$side}]. Must be 'customer' or 'vendor'.");
        }

        if (empty($rows)) {
            throw new InvalidArgumentException('Opening trade form must contain at least one row.');
        }

        $openingDateStr = $openingDate instanceof DateTimeInterface ? $openingDate->format('Y-m-d') : (string) $openingDate;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $openingDateStr)) {
            throw new InvalidArgumentException('Opening date must be in YYYY-MM-DD format.');
        }
        if ($openingDateStr > now($company->timezone)->toDateString()) {
            throw new InvalidArgumentException('Opening date cannot be in the future.');
        }

        return app(TenantWriteAccess::class)->transaction((int) $company->id, function () use (
            $company,
            $side,
            $partyId,
            $openingDateStr,
            $currencyCode,
            $exchangeRate,
            $rows,
            $actor,
            $formIdempotencyKey,
            $sourceIdentity,
        ): array {
            /** @var Company $lockedCompany */
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            if ($lockedCompany->status !== 'active') {
                throw new InvalidArgumentException("Cannot post opening trade form on inactive company [{$lockedCompany->id}].");
            }

            $member = CompanyUser::where('company_id', $lockedCompany->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();
            if ($member === null || $member->status !== 'active') {
                throw new AuthorizationException("User [{$actor->id}] is not an active member of company [{$lockedCompany->id}].");
            }

            $companyBase = strtoupper((string) $lockedCompany->base_currency_code);
            $cleanCurrency = strtoupper(trim($currencyCode));
            $isBase = $cleanCurrency === $companyBase;

            if ($isBase) {
                if ($exchangeRate !== null && ! BigDecimal::of($exchangeRate)->isEqualTo(1)) {
                    throw new InvalidArgumentException("Exchange rate for base currency [{$companyBase}] must be exactly 1.");
                }
                $rateStr = '1.0000000000';
            } else {
                if ($exchangeRate === null) {
                    throw new InvalidArgumentException("Exchange rate is required for foreign currency [{$cleanCurrency}].");
                }
                $rateDec = BigDecimal::of($exchangeRate);
                if ($rateDec->isNegative() || $rateDec->isZero()) {
                    throw new InvalidArgumentException("Exchange rate for foreign currency [{$cleanCurrency}] must be strictly positive.");
                }
                $rateStr = (string) $rateDec->toScale(10);
            }
            $rateDec = BigDecimal::of($rateStr);
            $lineFx = ExchangeRate::from($rateStr);
            $totalRows = count($rows);

            // Check existing items committed under this form key (lost-ack replay)
            $existingItems = OpeningTradeItem::where('company_id', $lockedCompany->id)
                ->where(function ($q) use ($formIdempotencyKey): void {
                    $q->where('idempotency_key', $formIdempotencyKey)
                        ->orWhere('idempotency_key', 'like', $formIdempotencyKey.':line:%')
                        ->orWhere('control_route->form->form_key', $formIdempotencyKey);
                })
                ->lockForUpdate()
                ->get();

            if ($existingItems->isNotEmpty()) {
                if ($existingItems->count() !== $totalRows) {
                    throw new IdempotencyConflictException("Form idempotency key [{$formIdempotencyKey}] was already used with a different row set count: committed {$existingItems->count()} rows, received {$totalRows}.");
                }

                $sortedExisting = $existingItems->sortBy(function (OpeningTradeItem $item): int {
                    return (int) ($item->control_route['form']['row_index'] ?? 0);
                })->values();

                foreach ($rows as $index => $row) {
                    $childKey = $index === 0 ? $formIdempotencyKey : "{$formIdempotencyKey}:line:".($index - 1);
                    /** @var OpeningTradeItem|null $exItem */
                    $exItem = $sortedExisting->firstWhere('idempotency_key', $childKey);
                    if ($exItem === null) {
                        throw new IdempotencyConflictException("Opening trade item idempotency key [{$childKey}] was already used with a different economic payload.");
                    }

                    $rawAmt = (string) $row['amount'];
                    $amtDec = BigDecimal::of($rawAmt);
                    $rawRef = $row['original_reference'] ?? null;
                    $cleanRef = $rawRef !== null && trim((string) $rawRef) !== '' ? trim((string) $rawRef) : null;
                    $rawDue = $row['due_date'] ?? null;
                    $dueDateStr = $rawDue !== null && trim((string) $rawDue) !== '' ? trim((string) $rawDue) : null;
                    $rawNotes = $row['notes'] ?? null;
                    $cleanNotes = $rawNotes !== null && trim((string) $rawNotes) !== '' ? trim((string) $rawNotes) : null;

                    $matches = $exItem->side === $side
                        && (int) ($exItem->customer_id ?? $exItem->vendor_id) === $partyId
                        && $exItem->opening_date->toDateString() === $openingDateStr
                        && $exItem->currency_code === $cleanCurrency
                        && BigDecimal::of($exItem->amount_currency)->isEqualTo($amtDec)
                        && BigDecimal::of($exItem->exchange_rate)->isEqualTo($rateDec)
                        && ($exItem->original_reference ?: null) === ($cleanRef ?: null)
                        && ($exItem->due_date?->toDateString() ?: null) === ($dueDateStr ?: null)
                        && ($exItem->notes ?: null) === ($cleanNotes ?: null);

                    if (! $matches) {
                        throw new IdempotencyConflictException("Opening trade item idempotency key [{$childKey}] was already used with a different economic payload.");
                    }
                }

                return $sortedExisting->all();
            }

            // Validate and normalize all rows using locked minor unit scale
            $scale = app(CurrencyMinorUnitsResolver::class)->resolveFreshForCompany($lockedCompany, $cleanCurrency, lockForUpdate: true);
            $normalizedRows = [];
            foreach ($rows as $index => $row) {
                $rawAmt = (string) $row['amount'];
                try {
                    $amtDec = PositiveExactDecimal::parse($rawAmt, $scale);
                    if ($amtDec->isNegative() || $amtDec->isZero()) {
                        throw new InvalidArgumentException("Row {$index} amount must be strictly positive.");
                    }
                    MoneyValues::amount($rawAmt, $cleanCurrency);
                } catch (\Throwable $e) {
                    throw new InvalidArgumentException("Row {$index} amount [{$rawAmt}] violates currency minor unit precision for [{$cleanCurrency}]: {$e->getMessage()}", 0, $e);
                }
                $amountStr = (string) $amtDec->toScale(6);

                $rawRef = $row['original_reference'] ?? null;
                $cleanRef = $rawRef !== null && trim((string) $rawRef) !== '' ? trim((string) $rawRef) : null;
                if ($cleanRef !== null && mb_strlen($cleanRef) > 100) {
                    throw new InvalidArgumentException("Row {$index} original reference cannot exceed 100 characters.");
                }

                $rawDue = $row['due_date'] ?? null;
                $dueDateStr = null;
                if ($rawDue !== null && trim((string) $rawDue) !== '') {
                    $dueDateStr = trim((string) $rawDue);
                    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dueDateStr)) {
                        throw new InvalidArgumentException("Row {$index} due date must be in YYYY-MM-DD format.");
                    }
                }

                $rawNotes = $row['notes'] ?? null;
                $cleanNotes = $rawNotes !== null && trim((string) $rawNotes) !== '' ? trim((string) $rawNotes) : null;
                if ($cleanNotes !== null && mb_strlen($cleanNotes) > 500) {
                    throw new InvalidArgumentException("Row {$index} notes cannot exceed 500 characters.");
                }

                $carryingBaseStr = (string) BigDecimal::of($amountStr)->multipliedBy($rateDec)->toScale(6, RoundingMode::HALF_UP);

                $normalizedRows[] = [
                    'row_index' => $index,
                    'amount' => $amountStr,
                    'carrying_value_base' => $carryingBaseStr,
                    'original_reference' => $cleanRef,
                    'due_date' => $dueDateStr,
                    'notes' => $cleanNotes,
                ];
            }

            $formPayloadHash = hash('sha256', json_encode([
                'company_id' => (int) $lockedCompany->id,
                'side' => $side,
                'party_id' => $partyId,
                'opening_date' => $openingDateStr,
                'currency_code' => $cleanCurrency,
                'exchange_rate' => $rateStr,
                'total_rows' => $totalRows,
                'rows' => $normalizedRows,
            ], JSON_THROW_ON_ERROR));

            // Party validation
            if ($side === OpeningTradeItem::SIDE_CUSTOMER) {
                /** @var Customer|null $customer */
                $customer = Customer::where('company_id', $lockedCompany->id)
                    ->where('id', $partyId)
                    ->lockForUpdate()
                    ->first();

                if ($customer === null || $customer->status !== 'active') {
                    throw new InvalidArgumentException("Customer [{$partyId}] does not exist, is inactive, or does not belong to company [{$lockedCompany->id}].");
                }

                $partySnapshot = [
                    'id' => $customer->id,
                    'name_ar' => $customer->name_ar,
                    'name_en' => $customer->name_en,
                    'currency_code' => $customer->default_currency_code,
                    'code' => $customer->code,
                ];
            } else {
                /** @var Vendor|null $vendor */
                $vendor = Vendor::where('company_id', $lockedCompany->id)
                    ->where('id', $partyId)
                    ->lockForUpdate()
                    ->first();

                if ($vendor === null || $vendor->status !== 'active') {
                    throw new InvalidArgumentException("Vendor [{$partyId}] does not exist, is inactive, or does not belong to company [{$lockedCompany->id}].");
                }

                $partySnapshot = [
                    'id' => $vendor->id,
                    'name_ar' => $vendor->name_ar,
                    'name_en' => $vendor->name_en,
                    'currency_code' => $vendor->default_currency_code,
                    'code' => $vendor->code,
                ];
            }

            $obeAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                ->where('system_key', 'opening_balance_equity')
                ->where('active', true)
                ->first();

            if ($obeAccount === null) {
                throw new InvalidArgumentException("Active Opening Balance Equity account not found for company [{$lockedCompany->id}].");
            }

            if ($side === OpeningTradeItem::SIDE_CUSTOMER) {
                $controlAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                    ->where('system_key', 'accounts_receivable')
                    ->where('active', true)
                    ->firstOrFail();

                if (self::hasLegacyOverlap((int) $lockedCompany->id, $side)) {
                    throw new LegacyGlOpeningOverlapException('تعذر قيد الرصيد (تداخل مع الأستاذ العام): General ledger already contains unreviewed generic opening balances for Accounts Receivable. Ambiguous legacy entries must be reviewed before opening customer receivables can be recognized.');
                }
            } else {
                $controlAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                    ->where('system_key', 'accounts_payable')
                    ->where('active', true)
                    ->firstOrFail();

                if (self::hasLegacyOverlap((int) $lockedCompany->id, $side)) {
                    throw new LegacyGlOpeningOverlapException('تعذر قيد الرصيد (تداخل مع الأستاذ العام): General ledger already contains unreviewed generic opening balances for Accounts Payable. Ambiguous legacy entries must be reviewed before opening supplier payables can be recognized.');
                }
            }

            $companySnapshot = [
                'id' => $lockedCompany->id,
                'name_ar' => $lockedCompany->name_ar,
                'name_en' => $lockedCompany->name_en,
                'base_currency_code' => $lockedCompany->base_currency_code,
            ];

            $partyLabel = (string) ($partySnapshot['name_ar'] ?? $partySnapshot['name_en'] ?? $partySnapshot['code'] ?? "ID {$partyId}");

            $capability = new OpeningTradePostingCapability($lockedCompany->id, $actor->id, $side, $partyId);
            $this->activeEventScope = $this->eventScope;
            $postedItems = [];

            try {
                $postedItems = $this->eventScope->withPostingCapability(
                    $this,
                    $capability,
                    function () use (
                        $lockedCompany,
                        $side,
                        $partyId,
                        $openingDateStr,
                        $cleanCurrency,
                        $rateStr,
                        $lineFx,
                        $partySnapshot,
                        $companySnapshot,
                        $controlAccount,
                        $obeAccount,
                        $actor,
                        $sourceIdentity,
                        $formIdempotencyKey,
                        $formPayloadHash,
                        $totalRows,
                        $normalizedRows,
                        $capability,
                        $partyLabel
                    ): array {
                        $items = [];
                        foreach ($normalizedRows as $nRow) {
                            $idx = $nRow['row_index'];
                            $itemPublicId = (string) Str::ulid();
                            $childKey = $idx === 0 ? $formIdempotencyKey : "{$formIdempotencyKey}:line:".($idx - 1);
                            $controlRoute = [
                                'control_account_id' => $controlAccount->id,
                                'control_account_key' => $controlAccount->system_key,
                                'offset_account_id' => $obeAccount->id,
                                'offset_account_key' => $obeAccount->system_key,
                                'form' => [
                                    'form_key' => $formIdempotencyKey,
                                    'row_index' => $idx,
                                    'total_rows' => $totalRows,
                                    'form_payload_hash' => $formPayloadHash,
                                ],
                            ];

                            $item = new OpeningTradeItem([
                                'company_id' => $lockedCompany->id,
                                'public_id' => $itemPublicId,
                                'side' => $side,
                                'customer_id' => $side === OpeningTradeItem::SIDE_CUSTOMER ? $partyId : null,
                                'vendor_id' => $side === OpeningTradeItem::SIDE_VENDOR ? $partyId : null,
                                'opening_date' => $openingDateStr,
                                'original_reference' => $nRow['original_reference'],
                                'due_date' => $nRow['due_date'],
                                'currency_code' => $cleanCurrency,
                                'amount_currency' => $nRow['amount'],
                                'exchange_rate' => $rateStr,
                                'carrying_value_base' => $nRow['carrying_value_base'],
                                'party_snapshot' => $partySnapshot,
                                'company_snapshot' => $companySnapshot,
                                'control_route' => $controlRoute,
                                'created_by' => $actor->id,
                                'source_identity' => $sourceIdentity,
                                'idempotency_key' => $childKey,
                                'idempotency_payload_hash' => hash('sha256', json_encode($nRow, JSON_THROW_ON_ERROR)),
                                'status' => OpeningTradeItem::STATUS_POSTED,
                                'notes' => $nRow['notes'],
                            ]);

                            $capability->authorizeItem($item);
                            $item->save();

                            $lines = [];
                            $lineAmount = MoneyAmount::from($nRow['amount']);
                            $carryingAmount = MoneyAmount::from($nRow['carrying_value_base']);
                            $cleanRef = $nRow['original_reference'];

                            if ($side === OpeningTradeItem::SIDE_CUSTOMER) {
                                $lines[] = PostingLineCommand::debit(
                                    lineNumber: 1,
                                    ledgerAccountId: $controlAccount->id,
                                    amount: $carryingAmount,
                                    transactionCurrencyCode: $cleanCurrency,
                                    transactionAmount: $lineAmount,
                                    exchangeRate: $lineFx,
                                    description: "Opening customer receivable: {$partyLabel}".($cleanRef ? " [Ref: {$cleanRef}]" : ''),
                                );
                                $lines[] = PostingLineCommand::credit(
                                    lineNumber: 2,
                                    ledgerAccountId: $obeAccount->id,
                                    amount: $carryingAmount,
                                    transactionCurrencyCode: $cleanCurrency,
                                    transactionAmount: $lineAmount,
                                    exchangeRate: $lineFx,
                                    description: "Opening balance equity offset: {$partyLabel}",
                                );
                            } else {
                                $lines[] = PostingLineCommand::debit(
                                    lineNumber: 1,
                                    ledgerAccountId: $obeAccount->id,
                                    amount: $carryingAmount,
                                    transactionCurrencyCode: $cleanCurrency,
                                    transactionAmount: $lineAmount,
                                    exchangeRate: $lineFx,
                                    description: "Opening balance equity offset: {$partyLabel}",
                                );
                                $lines[] = PostingLineCommand::credit(
                                    lineNumber: 2,
                                    ledgerAccountId: $controlAccount->id,
                                    amount: $carryingAmount,
                                    transactionCurrencyCode: $cleanCurrency,
                                    transactionAmount: $lineAmount,
                                    exchangeRate: $lineFx,
                                    description: "Opening supplier payable: {$partyLabel}".($cleanRef ? " [Ref: {$cleanRef}]" : ''),
                                );
                            }

                            $postingCommand = new PostingCommand(
                                company: $lockedCompany,
                                postingDate: Carbon::parse($openingDateStr),
                                sourceType: 'opening_trade_item',
                                sourceId: $item->id,
                                transactionCurrencyCode: $cleanCurrency,
                                baseCurrencyCode: $lockedCompany->base_currency_code,
                                exchangeRate: $lineFx,
                                idempotencyKey: "oti-gl:{$item->id}",
                                postedBy: $actor,
                                description: "Opening {$side} balance: {$partyLabel}",
                                lines: $lines,
                            );

                            $batch = $this->postingService->post($postingCommand);
                            if ($batch->source_type !== 'opening_trade_item' || (int) $batch->source_id !== (int) $item->id) {
                                throw new InvalidArgumentException('Original journal source identity must match opening trade item.');
                            }

                            $capability->authorizeBatchLink((int) $item->id, (int) $batch->id);
                            $item->posting_batch_id = $batch->id;
                            $item->save();

                            $items[] = $item;
                        }

                        return $items;
                    }
                );
            } finally {
                $this->activeEventScope = null;
            }

            foreach ($postedItems as $item) {
                $this->auditService->log(
                    companyId: (int) $lockedCompany->id,
                    eventKey: 'accounting.opening_trade_item.posted',
                    summary: "Posted opening {$side} trade balance #{$item->public_id}",
                    actorUserId: (int) $actor->id,
                    subject: $item,
                    after: [
                        'public_id' => $item->public_id,
                        'side' => $item->side,
                        'party_id' => $partyId,
                        'amount' => $item->amount_currency,
                        'currency' => $item->currency_code,
                        'exchange_rate' => $item->exchange_rate,
                        'carrying_value_base' => $item->carrying_value_base,
                        'posting_batch_id' => $item->posting_batch_id,
                    ],
                );
            }

            return $postedItems;
        });
    }
}
