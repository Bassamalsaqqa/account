<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyLanguage;
use App\Models\DocumentSequence;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Services\Audit\AuditService;
use App\Services\Posting\AccountingPostingService;
use App\Services\Purchasing\PayableBookValue;
use App\Services\Purchasing\PurchaseIdentitySnapshot;
use App\Services\Purchasing\PurchasePostingCommandBuilder;
use App\Services\Purchasing\VendorPaymentHistoryCommands;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use App\Services\Purchasing\VendorPaymentPostingCapability;
use App\Services\Purchasing\VendorPaymentPostingScope;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Sales\ReceiptRequestValues;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PostVendorPaymentAction
{
    private ?VendorPaymentPostingScope $activePostingScope = null;

    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected AccountingPostingService $accountingPostingService,
        protected VendorPaymentPostingScope $postingScope,
        protected VendorPaymentPostedIntegrityValidator $integrityValidator,
    ) {}

    public function ownsPostingScope(VendorPaymentPostingScope $scope): bool
    {
        return $this->activePostingScope === $scope;
    }

    /**
     * @param array{
     *     vendor_id: int,
     *     money_account_id: int,
     *     payment_date: string,
     *     payment_method: string,
     *     document_locale?: string,
     *     amount: string|BigDecimal,
     *     exchange_rate: string|BigDecimal,
     *     reference_number?: ?string,
     *     notes?: ?string,
     *     idempotency_key?: ?string,
     *     allocations?: list<array{
     *         purchase_id: int,
     *         allocated_amount: string|BigDecimal,
     *     }>
     * } $data
     */
    public function execute(Company $company, User $user, array $data): VendorPayment
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $company->id) {
            throw new NoActiveCompanyException("Active company context does not match company [{$company->id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $user->id) {
            throw new AuthorizationException('Actor does not match authenticated user.');
        }

        if (! $user->belongsToCompany($company->id)) {
            throw new AuthorizationException("User does not belong to company [{$company->id}].");
        }

        setPermissionsTeamId($company->id);

        if (! $user->hasPermissionTo('money.vendor_payment.create') || ! $user->hasPermissionTo('purchasing.cost.view')) {
            throw new AuthorizationException('User does not have permission to create vendor payments.');
        }

        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey === null || trim((string) $idempotencyKey) === '') {
            throw new InvalidArgumentException('Idempotency key is required for vendor payment posting.');
        }

        $idempotencyKey = ReceiptRequestValues::key($idempotencyKey);
        app(SalesDocumentRules::class)->date((string) $data['payment_date']);

        foreach (['vendor_id', 'money_account_id'] as $field) {
            $data[$field] = ReceiptRequestValues::id($data[$field]);
        }
        foreach (['amount' => 6, 'exchange_rate' => 10] as $field => $scale) {
            $data[$field] = ReceiptRequestValues::decimal($data[$field], $scale);
        }

        $method = (string) $data['payment_method'];
        if (! in_array($method, [VendorPayment::METHOD_CASH, VendorPayment::METHOD_BANK], true)) {
            throw new InvalidArgumentException("Payment method [{$method}] is not supported. Only cash and bank transfers are accepted in this phase. Checks and cards are strictly prohibited.");
        }

        // Deduplicate and group allocations by purchase_id
        $grouped = [];
        foreach ($data['allocations'] ?? [] as $allocation) {
            $id = ReceiptRequestValues::id($allocation['purchase_id']);
            $amount = BigDecimal::of(ReceiptRequestValues::decimal($allocation['allocated_amount'], 6));
            if (! $amount->isPositive()) {
                throw new InvalidArgumentException(__('purchasing.credit_amount_positive'));
            }
            $grouped[$id] = ($grouped[$id] ?? BigDecimal::zero())->plus($amount);
        }
        ksort($grouped, SORT_NUMERIC);
        $allocationsForHash = [];
        foreach ($grouped as $id => $amount) {
            $allocationsForHash[] = ['purchase_id' => $id, 'allocated_amount' => (string) $amount->toScale(6)];
        }
        $data['allocations'] = $allocationsForHash;

        $canonicalData = [
            'company_id' => (int) $company->id,
            'actor_id' => (int) $user->id,
            'vendor_id' => (int) $data['vendor_id'],
            'money_account_id' => (int) $data['money_account_id'],
            'payment_date' => (string) $data['payment_date'],
            'payment_method' => $method,
            'amount' => (string) BigDecimal::of((string) $data['amount'])->toScale(6, RoundingMode::HALF_UP),
            'exchange_rate' => (string) BigDecimal::of((string) $data['exchange_rate'])->toScale(10, RoundingMode::HALF_UP),
            'reference_number' => isset($data['reference_number']) && trim((string) $data['reference_number']) !== '' ? trim((string) $data['reference_number']) : null,
            'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            'allocations' => $allocationsForHash,
            'document_locale' => $data['document_locale'] ?? null,
        ];

        $requestHash = hash('sha256', json_encode($canonicalData, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($company, $user, $data, $method, $idempotencyKey, $requestHash): VendorPayment {
            // 1. Lock Company FOR UPDATE
            /** @var Company $lockedCompany */
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $user, 'money.vendor_payment.create');
            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $user, 'purchasing.cost.view');

            if (! $user->fresh()->belongsToCompany($lockedCompany->id)) {
                throw new AuthorizationException("User does not belong to company [{$lockedCompany->id}].");
            }

            // 2. Check idempotency under Company lock BEFORE active master-data checks
            $existing = VendorPayment::where('company_id', $lockedCompany->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->request_hash === null || $existing->request_hash !== $requestHash) {
                    throw new IdempotencyConflictException("Idempotency key [{$idempotencyKey}] was already used with a different request payload.");
                }

                // Strictly validate historical integrity of existing payment
                $this->integrityValidator->validate($existing);

                return $existing->load(['allocations', 'postingBatch', 'vendor', 'moneyAccount']);
            }

            // 3. Lock Vendor FOR UPDATE (including soft-deleted)
            /** @var Vendor $vendor */
            $vendor = Vendor::withTrashed()
                ->where('company_id', $lockedCompany->id)
                ->where('id', $data['vendor_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $enabledLocales = CompanyLanguage::where('company_id', $lockedCompany->id)
                ->where('enabled', true)
                ->pluck('locale')
                ->all();

            if (isset($data['document_locale']) && trim((string) $data['document_locale']) !== '') {
                $override = (string) $data['document_locale'];
                if (! in_array($override, ['ar', 'en'], true) || ! in_array($override, $enabledLocales, true)) {
                    throw new InvalidArgumentException('Explicit vendor payment document language must be enabled.');
                }
                $documentLocale = $override;
            } else {
                if ($vendor->preferred_locale !== null && in_array($vendor->preferred_locale, $enabledLocales, true)) {
                    $documentLocale = $vendor->preferred_locale;
                } elseif (in_array($lockedCompany->default_locale, $enabledLocales, true)) {
                    $documentLocale = $lockedCompany->default_locale;
                } elseif ($enabledLocales !== []) {
                    $documentLocale = $enabledLocales[0];
                } else {
                    $documentLocale = 'ar';
                }
            }

            // 4. Lock MoneyAccount FOR UPDATE
            /** @var MoneyAccount $moneyAccount */
            $moneyAccount = MoneyAccount::where('company_id', $lockedCompany->id)
                ->where('id', $data['money_account_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $moneyAccount->is_active) {
                throw new InvalidArgumentException("Money account [{$moneyAccount->id}] is inactive.");
            }

            $currency = $moneyAccount->currency_code;
            if (! in_array($moneyAccount->account_type, [MoneyAccount::TYPE_CASH, MoneyAccount::TYPE_BANK], true)
                || (($method === VendorPayment::METHOD_CASH) !== ($moneyAccount->account_type === MoneyAccount::TYPE_CASH))) {
                throw new InvalidArgumentException('Payment method must match its money account type.');
            }

            if (! CompanyCurrency::where('company_id', $lockedCompany->id)->where('currency_code', $currency)->where('enabled', true)->exists()) {
                throw new InvalidArgumentException('Payment currency is not enabled.');
            }

            /** @var LedgerAccount|null $cashLedger */
            $cashLedger = LedgerAccount::where('company_id', $lockedCompany->id)
                ->where('id', $moneyAccount->ledger_account_id)
                ->where('active', true)
                ->where('is_control', false)
                ->where('account_type', LedgerAccount::TYPE_ASSET)
                ->where('normal_balance', LedgerAccount::BALANCE_DEBIT)
                ->lockForUpdate()
                ->first();

            if ($cashLedger === null) {
                throw new InvalidArgumentException('Money account ledger must be an active, non-control asset account with debit normal balance.');
            }

            // Verify cash / bank parent control account
            $parentControl = LedgerAccount::where('company_id', $lockedCompany->id)
                ->where('id', $cashLedger->parent_id)
                ->where('is_control', true)
                ->lockForUpdate()
                ->first();

            if ($method === VendorPayment::METHOD_CASH && $parentControl?->system_key !== 'cash_control') {
                throw new InvalidArgumentException('Cash account ledger must be a child of cash_control.');
            }
            if ($method === VendorPayment::METHOD_BANK && $parentControl?->system_key !== 'bank_control') {
                throw new InvalidArgumentException('Bank account ledger must be a child of bank_control.');
            }

            if ($currency === $lockedCompany->base_currency_code && ! BigDecimal::of((string) $data['exchange_rate'])->isEqualTo(1)) {
                throw new InvalidArgumentException('Base currency rate must equal one.');
            }

            $minorUnits = in_array($currency, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;

            try {
                $totalAmount = BigDecimal::of((string) $data['amount'])->toScale($minorUnits, RoundingMode::UNNECESSARY);
            } catch (RoundingNecessaryException $e) {
                throw new InvalidArgumentException("Payment amount precision exceeds {$minorUnits} decimals.");
            }
            if ($totalAmount->isLessThanOrEqualTo(0)) {
                throw new InvalidArgumentException('Payment amount must be strictly positive.');
            }

            $paymentFx = BigDecimal::of((string) $data['exchange_rate'])->toScale(10, RoundingMode::HALF_UP);
            if ($paymentFx->isLessThanOrEqualTo(0)) {
                throw new InvalidArgumentException('Exchange rate must be strictly positive.');
            }

            $paymentAmountBase = $totalAmount->multipliedBy($paymentFx)->toScale(6, RoundingMode::HALF_UP);
            if ($totalAmount->isPositive() && $paymentAmountBase->isZero()) {
                throw new InvalidArgumentException('Positive payment amount cannot produce zero base amount.');
            }
            $paymentDate = (string) $data['payment_date'];
            $year = (int) substr($paymentDate, 0, 4);

            // 5. Validate and calculate allocations
            $allocationsInput = $data['allocations'];
            $allocatedTotal = BigDecimal::zero();
            $preparedAllocations = [];

            $totalApReliefBase = BigDecimal::zero();
            $totalAllocSettlementBase = BigDecimal::zero();
            $totalRealizedFxGainBase = BigDecimal::zero();
            $totalRealizedFxLossBase = BigDecimal::zero();

            $remainingOutstandingByPurchase = [];

            foreach ($allocationsInput as $allocInput) {
                /** @var Purchase $purchase */
                $purchase = Purchase::where('company_id', $lockedCompany->id)
                    ->where('id', $allocInput['purchase_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                app(PurchasePostingCommandBuilder::class)->validatePosted($purchase);

                if (! $purchase->isPosted()) {
                    throw new InvalidArgumentException("Cannot allocate payment to non-posted purchase [{$purchase->id}].");
                }

                if ((int) $purchase->vendor_id !== (int) $vendor->id) {
                    throw new InvalidArgumentException("Cannot allocate payment to purchase [{$purchase->id}] belonging to another vendor.");
                }

                if ($purchase->currency_code !== $currency) {
                    throw new InvalidArgumentException("Cross-currency allocation is not supported. Payment currency [{$currency}] does not match purchase [{$purchase->id}] currency [{$purchase->currency_code}].");
                }

                if ($paymentDate < $purchase->purchase_date->format('Y-m-d')) {
                    throw new InvalidArgumentException("Direct allocation payment date [{$paymentDate}] cannot precede purchase date [{$purchase->purchase_date->format('Y-m-d')}].");
                }

                try {
                    $allocAmt = BigDecimal::of((string) $allocInput['allocated_amount'])->toScale($minorUnits, RoundingMode::UNNECESSARY);
                } catch (RoundingNecessaryException $e) {
                    throw new InvalidArgumentException("Allocation amount precision exceeds {$minorUnits} decimals.");
                }
                if ($allocAmt->isLessThanOrEqualTo(0)) {
                    continue;
                }

                $purId = (int) $purchase->id;
                if (! isset($remainingOutstandingByPurchase[$purId])) {
                    $remainingOutstandingByPurchase[$purId] = $purchase->calculateOutstanding();
                }

                if ($allocAmt->isGreaterThan($remainingOutstandingByPurchase[$purId])) {
                    throw new InvalidArgumentException("Allocated amount [{$allocAmt}] exceeds purchase [{$purchase->id}] outstanding balance [{$remainingOutstandingByPurchase[$purId]}].");
                }

                $remainingOutstandingByPurchase[$purId] = $remainingOutstandingByPurchase[$purId]->minus($allocAmt);
                $allocatedTotal = $allocatedTotal->plus($allocAmt);

                $purFx = BigDecimal::of((string) $purchase->exchange_rate)->toScale(10, RoundingMode::HALF_UP);
                $apReliefBase = app(PayableBookValue::class)->relief($purchase, $allocAmt);

                $targetSettlement = $allocatedTotal->multipliedBy($paymentFx)->toScale(6, RoundingMode::HALF_UP);
                $settlementBase = $targetSettlement->minus($totalAllocSettlementBase);

                if ($allocAmt->isPositive() && ($apReliefBase->isZero() || $settlementBase->isZero())) {
                    throw new InvalidArgumentException('Positive allocation currency amount cannot produce zero base value.');
                }

                // In AP: delta = S - B
                // delta > 0 is FX LOSS; delta < 0 is FX GAIN
                $delta = $settlementBase->minus($apReliefBase);

                if ($delta->isPositive()) {
                    $totalRealizedFxLossBase = $totalRealizedFxLossBase->plus($delta);
                } elseif ($delta->isNegative()) {
                    $totalRealizedFxGainBase = $totalRealizedFxGainBase->plus($delta->abs());
                }

                $totalApReliefBase = $totalApReliefBase->plus($apReliefBase);
                $totalAllocSettlementBase = $totalAllocSettlementBase->plus($settlementBase);

                $preparedAllocations[] = [
                    'purchase_id' => $purchase->id,
                    'allocated_amount' => (string) $allocAmt->toScale(6),
                    'purchase_exchange_rate' => (string) $purFx->toScale(10),
                    'payment_exchange_rate' => (string) $paymentFx->toScale(10),
                    'base_amount_applied_to_payable' => (string) $apReliefBase->toScale(6),
                    'settlement_base_value' => (string) $settlementBase->toScale(6),
                    'realized_fx_gain_loss_base' => (string) $delta->toScale(6),
                ];
            }

            if ($allocatedTotal->isGreaterThan($totalAmount)) {
                throw new InvalidArgumentException("Total allocated amount [{$allocatedTotal}] exceeds payment total amount [{$totalAmount}].");
            }

            $unallocatedAmount = $totalAmount->minus($allocatedTotal);
            $unallocatedBase = $paymentAmountBase->minus($totalAllocSettlementBase);

            if ($unallocatedAmount->isPositive() && $unallocatedBase->isZero()) {
                throw new InvalidArgumentException('Positive unallocated amount cannot produce zero base amount.');
            }

            // Inactive or soft-deleted vendor policy: can only be settled against existing purchases, no advance allowed
            if ((! $vendor->active || $vendor->trashed()) && $unallocatedAmount->isPositive()) {
                throw new InvalidArgumentException('Inactive or soft-deleted vendor cannot receive unallocated advance.');
            }

            // 6. Generate sequence number
            $paymentNumber = $this->sequenceService->generateNextNumber(
                $lockedCompany->id,
                DocumentSequence::TYPE_VENDOR_PAYMENT,
                $year
            );

            // Snapshots
            $identity = app(PurchaseIdentitySnapshot::class);
            $vendorSnapshot = $identity->vendor($vendor);
            $companySnapshot = $identity->company($lockedCompany);

            $this->activePostingScope = $this->postingScope;
            try {
                return $this->postingScope->withinCanonicalPaymentPosting(
                    $this,
                    (int) $lockedCompany->id,
                    (int) $vendor->id,
                    $user,
                    $paymentNumber,
                    $preparedAllocations,
                    function (VendorPaymentPostingCapability $capability) use (
                        $lockedCompany,
                        $vendor,
                        $moneyAccount,
                        $paymentNumber,
                        $paymentDate,
                        $method,
                        $currency,
                        $totalAmount,
                        $paymentFx,
                        $paymentAmountBase,
                        $data,
                        $idempotencyKey,
                        $requestHash,
                        $user,
                        $documentLocale,
                        $vendorSnapshot,
                        $companySnapshot,
                        $preparedAllocations
                    ): VendorPayment {
                        $payment = VendorPayment::recordProvisionalPayment($capability, [
                            'public_id' => (string) Str::ulid(),
                            'company_id' => $lockedCompany->id,
                            'payment_number' => $paymentNumber,
                            'vendor_id' => $vendor->id,
                            'money_account_id' => $moneyAccount->id,
                            'payment_date' => $paymentDate,
                            'payment_method' => $method,
                            'currency_code' => $currency,
                            'base_currency_code' => $lockedCompany->base_currency_code,
                            'amount' => (string) $totalAmount->toScale(6),
                            'exchange_rate' => (string) $paymentFx->toScale(10),
                            'amount_base' => (string) $paymentAmountBase->toScale(6),
                            'reference_number' => $data['reference_number'] ?? null,
                            'notes' => $data['notes'] ?? null,
                            'document_locale' => $documentLocale,
                            'vendor_snapshot' => $vendorSnapshot,
                            'company_snapshot' => $companySnapshot,
                            'idempotency_key' => $idempotencyKey,
                            'request_hash' => $requestHash,
                            'created_by' => $user->id,
                        ]);

                        foreach ($preparedAllocations as $allocData) {
                            VendorPaymentAllocation::appendInitialAllocation($capability, $payment, $allocData);
                        }

                        $cmd = app(VendorPaymentHistoryCommands::class)->payment($payment);

                        $batch = $this->accountingPostingService->post($cmd);
                        $payment->completeCanonicalPost($batch, $user, $capability);

                        // Safe nonmonetary audit event
                        app(AuditService::class)->log(
                            (int) $lockedCompany->id,
                            'vendor_payment.posted',
                            'Vendor payment posted',
                            (int) $user->id,
                            $payment,
                            null,
                            null,
                            [
                                'payment_id' => (int) $payment->id,
                                'payment_number' => $paymentNumber,
                                'vendor_id' => (int) $vendor->id,
                                'lifecycle' => 'posted',
                            ],
                        );

                        return $payment->fresh(['allocations', 'vendor', 'moneyAccount', 'postingBatch']);
                    }
                );
            } finally {
                $this->activePostingScope = null;
            }
        });
    }
}
