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
use Brick\Math\Exception\MathException;
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
            'amount' => $amountStr,
            'currency_code' => $currencyCode,
            'exchange_rate' => $rateStr,
            'carrying_value_base' => $carryingBaseStr,
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
            $dueDateStr,
            $cleanRef,
            $amount,
            $amountStr,
            $currencyCode,
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
                throw new InvalidArgumentException("Cannot post opening trade items for inactive company [{$lockedCompany->id}].");
            }

            $member = CompanyUser::where('company_id', $lockedCompany->id)
                ->where('user_id', $actingUser->id)
                ->lockForUpdate()
                ->first();
            if ($member === null || $member->status !== 'active') {
                throw new AuthorizationException("User [{$actingUser->id}] is not an active member of company [{$lockedCompany->id}].");
            }

            // Durable idempotency check under lock
            $existing = OpeningTradeItem::where('company_id', $lockedCompany->id)
                ->where('idempotency_key', $idempotencyKey)
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
                                ->orWhere('idempotency_key', 'like', $formKey.':line:%');
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

            // New events use locked, fresh currency policy; identical receipts above
            // retain their original economics even if that policy has since changed.
            try {
                $scale = app(CurrencyMinorUnitsResolver::class)->resolveFreshForCompany($lockedCompany, $currencyCode);
                PositiveExactDecimal::parse($amount, $scale);
                MoneyValues::amount($amount, $currencyCode);
            } catch (MathException $e) {
                throw new InvalidArgumentException("Amount [{$amount}] exceeds minor unit precision for currency [{$currencyCode}].", 0, $e);
            }

            // Validate party and lock
            if ($side === OpeningTradeItem::SIDE_CUSTOMER) {
                /** @var Customer|null $customer */
                $customer = Customer::where('company_id', $lockedCompany->id)->where('id', $partyId)->lockForUpdate()->first();
                if ($customer === null) {
                    throw new InvalidArgumentException("Customer [{$partyId}] not found in company [{$lockedCompany->id}].");
                }
                if ($customer->status !== 'active') {
                    throw new InvalidArgumentException("Customer [{$partyId}] is inactive.");
                }
                $partySnapshot = [
                    'id' => $customer->id,
                    'public_id' => $customer->public_id,
                    'name_ar' => $customer->name_ar,
                    'name_en' => $customer->name_en,
                    'code' => $customer->code,
                    'tax_number' => $customer->tax_number,
                    'phone' => $customer->phone,
                ];
            } else {
                /** @var Vendor|null $vendor */
                $vendor = Vendor::where('company_id', $lockedCompany->id)->where('id', $partyId)->lockForUpdate()->first();
                if ($vendor === null) {
                    throw new InvalidArgumentException("Vendor [{$partyId}] not found in company [{$lockedCompany->id}].");
                }
                if ($vendor->status !== 'active') {
                    throw new InvalidArgumentException("Vendor [{$partyId}] is inactive.");
                }
                $partySnapshot = [
                    'id' => $vendor->id,
                    'public_id' => $vendor->public_id,
                    'name_ar' => $vendor->name_ar,
                    'name_en' => $vendor->name_en,
                    'code' => $vendor->code,
                    'tax_number' => $vendor->tax_number,
                    'phone' => $vendor->phone,
                ];
            }

            // System accounts & preflight legacy GL overlap guard
            $obeAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                ->where('system_key', 'opening_balance_equity')
                ->where('active', true)
                ->firstOrFail();

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
            try {
                $item = $this->eventScope->withPostingCapability(
                    $this,
                    new OpeningTradePostingCapability($lockedCompany->id, $actingUser->id, $side, $partyId),
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
                        $partyLabel
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
}
