<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyLanguage;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\DocumentSequence;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Posting\AccountingPostingService;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Sales\ReceiptRequestValues;
use App\Services\Sales\ReceivableBookValue;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use App\Services\Sales\SalesPostingLines;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PostCustomerPaymentAction
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected AccountingPostingService $accountingPostingService,
    ) {}

    /**
     * @param  array{
     *     customer_id: int,
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
     *         sales_invoice_id: int,
     *         allocated_amount: string|BigDecimal,
     *     }>
     * }  $data
     */
    public function execute(Company $company, User $user, array $data): CustomerPayment
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

        if (! $user->hasPermissionTo('money.receipt.create')) {
            throw new AuthorizationException('User does not have permission to create customer receipts.');
        }

        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Idempotency key is required for customer receipt posting.');
        }

        $idempotencyKey = ReceiptRequestValues::key($idempotencyKey);
        app(SalesDocumentRules::class)->date((string) $data['payment_date']);
        foreach (['customer_id', 'money_account_id'] as $field) {
            $data[$field] = ReceiptRequestValues::id($data[$field]);
        }
        foreach (['amount' => 6, 'exchange_rate' => 10] as $field => $scale) {
            $data[$field] = ReceiptRequestValues::decimal($data[$field], $scale);
        }
        $method = $data['payment_method'];
        if (! in_array($method, [CustomerPayment::METHOD_CASH, CustomerPayment::METHOD_BANK], true)) {
            throw new InvalidArgumentException("Payment method [{$method}] is not supported. Only cash and bank transfers are accepted in this phase. Checks are strictly prohibited.");
        }

        $grouped = [];
        foreach ($data['allocations'] ?? [] as $allocation) {
            $id = ReceiptRequestValues::id($allocation['sales_invoice_id']);
            $amount = BigDecimal::of(ReceiptRequestValues::decimal($allocation['allocated_amount'], 6));
            $grouped[$id] = ($grouped[$id] ?? BigDecimal::zero())->plus($amount);
        }
        ksort($grouped, SORT_NUMERIC);
        $allocationsForHash = [];
        foreach ($grouped as $id => $amount) {
            $allocationsForHash[] = ['sales_invoice_id' => $id, 'allocated_amount' => (string) $amount->toScale(6)];
        }
        $data['allocations'] = $allocationsForHash;

        $canonicalData = [
            'company_id' => (int) $company->id,
            'actor_id' => (int) $user->id,
            'customer_id' => (int) $data['customer_id'],
            'money_account_id' => (int) $data['money_account_id'],
            'payment_date' => (string) $data['payment_date'],
            'payment_method' => (string) $method,
            'amount' => (string) BigDecimal::of((string) $data['amount'])->toScale(6, RoundingMode::HALF_UP),
            'exchange_rate' => (string) BigDecimal::of((string) $data['exchange_rate'])->toScale(10, RoundingMode::HALF_UP),
            'reference_number' => isset($data['reference_number']) && trim((string) $data['reference_number']) !== '' ? trim((string) $data['reference_number']) : null,
            'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            'allocations' => $allocationsForHash,
            'document_locale' => $data['document_locale'] ?? null,
        ];

        $requestHash = hash('sha256', json_encode($canonicalData, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($company, $user, $data, $method, $idempotencyKey, $requestHash): CustomerPayment {
            // 1. Lock Company FOR UPDATE
            /** @var Company $lockedCompany */
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $user, 'money.receipt.create');

            if (! $user->fresh()->belongsToCompany($lockedCompany->id)) {
                throw new AuthorizationException("User does not belong to company [{$lockedCompany->id}].");
            }

            // Check idempotency under the company lock
            $existing = CustomerPayment::where('company_id', $lockedCompany->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->request_hash !== null && $existing->request_hash !== $requestHash) {
                    throw new IdempotencyConflictException("Idempotency key [{$idempotencyKey}] was already used with a different request payload.");
                }

                return $existing->load(['allocations', 'postingBatch', 'customer', 'moneyAccount']);
            }

            // 2. Lock Customer FOR UPDATE
            /** @var Customer $customer */
            $customer = Customer::where('company_id', $lockedCompany->id)->where('id', $data['customer_id'])->lockForUpdate()->firstOrFail();
            if (! $customer->active) {
                throw new InvalidArgumentException("Cannot create receipt for inactive customer [{$customer->id}].");
            }

            $documentLocale = $data['document_locale'] ?? $customer->preferred_locale ?? $lockedCompany->default_locale;
            if (! in_array($documentLocale, ['ar', 'en'], true) || ! CompanyLanguage::where('company_id', $lockedCompany->id)->where('locale', $documentLocale)->where('enabled', true)->exists()) {
                throw new InvalidArgumentException('Receipt document language must be enabled.');
            }

            // 3. Lock MoneyAccount FOR UPDATE
            /** @var MoneyAccount $moneyAccount */
            $moneyAccount = MoneyAccount::where('company_id', $lockedCompany->id)->where('id', $data['money_account_id'])->lockForUpdate()->firstOrFail();
            if (! $moneyAccount->is_active) {
                throw new InvalidArgumentException("Money account [{$moneyAccount->id}] is inactive.");
            }

            $currency = $moneyAccount->currency_code;
            if (($method === CustomerPayment::METHOD_CASH) !== ($moneyAccount->account_type === MoneyAccount::TYPE_CASH)) {
                throw new InvalidArgumentException('Receipt method must match its money account type.');
            }
            if (! CompanyCurrency::where('company_id', $lockedCompany->id)->where('currency_code', $currency)->where('enabled', true)->exists()) {
                throw new InvalidArgumentException('Receipt currency is not enabled.');
            }
            $cashLedger = LedgerAccount::where('company_id', $lockedCompany->id)->where('id', $moneyAccount->ledger_account_id)->where('active', true)->where('is_control', false)->lockForUpdate()->firstOrFail();
            if ($currency === $lockedCompany->base_currency_code && ! BigDecimal::of($data['exchange_rate'])->isEqualTo(1)) {
                throw new InvalidArgumentException('Base currency rate must equal one.');
            }
            $minorUnits = in_array($currency, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;

            $totalAmount = BigDecimal::of((string) $data['amount'])->toScale($minorUnits, RoundingMode::UNNECESSARY);
            if ($totalAmount->isLessThanOrEqualTo(0)) {
                throw new InvalidArgumentException('Receipt amount must be strictly positive.');
            }

            $paymentFx = BigDecimal::of((string) $data['exchange_rate'])->toScale(10, RoundingMode::HALF_UP);
            if ($paymentFx->isLessThanOrEqualTo(0)) {
                throw new InvalidArgumentException('Exchange rate must be strictly positive.');
            }

            $paymentAmountBase = $totalAmount->multipliedBy($paymentFx)->toScale(6, RoundingMode::HALF_UP);

            $paymentDate = $data['payment_date'];
            $year = (int) substr($paymentDate, 0, 4);

            // 4. Validate and calculate allocations
            $allocationsInput = $data['allocations'];
            $allocatedTotal = BigDecimal::zero();
            $preparedAllocations = [];

            $totalReceivableReliefBase = BigDecimal::zero();
            $totalAllocSettlementBase = BigDecimal::zero();
            $totalRealizedFxGainBase = BigDecimal::zero();
            $totalRealizedFxLossBase = BigDecimal::zero();

            // Track remaining outstanding per invoice in memory to prevent duplicate allocation overpayments
            $remainingOutstandingByInvoice = [];

            foreach ($allocationsInput as $allocInput) {
                /** @var SalesInvoice $invoice */
                $invoice = SalesInvoice::where('company_id', $lockedCompany->id)
                    ->where('id', $allocInput['sales_invoice_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $invoice->isPosted()) {
                    throw new InvalidArgumentException("Cannot allocate payment to non-posted invoice [{$invoice->id}].");
                }

                if ((int) $invoice->customer_id !== (int) $customer->id) {
                    throw new InvalidArgumentException("Cannot allocate payment to invoice [{$invoice->id}] belonging to another customer.");
                }

                if ($invoice->currency_code !== $currency) {
                    throw new InvalidArgumentException("Cross-currency allocation is not supported. Payment currency [{$currency}] does not match invoice [{$invoice->id}] currency [{$invoice->currency_code}].");
                }

                $allocAmt = BigDecimal::of((string) $allocInput['allocated_amount'])->toScale($minorUnits, RoundingMode::UNNECESSARY);
                if ($allocAmt->isLessThanOrEqualTo(0)) {
                    continue;
                }

                $invId = (int) $invoice->id;
                if (! isset($remainingOutstandingByInvoice[$invId])) {
                    $remainingOutstandingByInvoice[$invId] = $invoice->calculateOutstanding();
                }

                if ($allocAmt->isGreaterThan($remainingOutstandingByInvoice[$invId])) {
                    throw new InvalidArgumentException("Allocated amount [{$allocAmt}] exceeds invoice [{$invoice->id}] outstanding balance [{$remainingOutstandingByInvoice[$invId]}].");
                }

                $remainingOutstandingByInvoice[$invId] = $remainingOutstandingByInvoice[$invId]->minus($allocAmt);
                $allocatedTotal = $allocatedTotal->plus($allocAmt);

                $invFx = BigDecimal::of((string) $invoice->exchange_rate)->toScale(10, RoundingMode::HALF_UP);
                $receivableReliefBase = app(ReceivableBookValue::class)->relief($invoice, $allocAmt);
                $settlementBase = $allocatedTotal->multipliedBy($paymentFx)->toScale(6, RoundingMode::HALF_UP)->minus($totalAllocSettlementBase);
                $realizedFx = $settlementBase->minus($receivableReliefBase);

                if ($realizedFx->isPositive()) {
                    $totalRealizedFxGainBase = $totalRealizedFxGainBase->plus($realizedFx);
                } elseif ($realizedFx->isNegative()) {
                    $totalRealizedFxLossBase = $totalRealizedFxLossBase->plus($realizedFx->abs());
                }

                $totalReceivableReliefBase = $totalReceivableReliefBase->plus($receivableReliefBase);
                $totalAllocSettlementBase = $totalAllocSettlementBase->plus($settlementBase);

                $preparedAllocations[] = [
                    'sales_invoice_id' => $invoice->id,
                    'allocated_amount' => (string) $allocAmt->toScale(6),
                    'invoice_exchange_rate' => (string) $invFx->toScale(10),
                    'payment_exchange_rate' => (string) $paymentFx->toScale(10),
                    'base_amount_applied_to_receivable' => (string) $receivableReliefBase->toScale(6),
                    'settlement_base_value' => (string) $settlementBase->toScale(6),
                    'realized_fx_gain_loss_base' => (string) $realizedFx->toScale(6),
                ];
            }

            if ($allocatedTotal->isGreaterThan($totalAmount)) {
                throw new InvalidArgumentException("Total allocated amount [{$allocatedTotal}] exceeds receipt total amount [{$totalAmount}].");
            }

            $unallocatedAmount = $totalAmount->minus($allocatedTotal);
            $unallocatedBase = $paymentAmountBase->minus($totalAllocSettlementBase);

            // 5. Generate permanent payment sequence number
            $paymentNumber = $this->sequenceService->generateNextNumber(
                $lockedCompany->id,
                DocumentSequence::TYPE_CUSTOMER_PAYMENT,
                $year
            );

            // 6. Double-entry GL Posting
            $cashLedgerAccount = $cashLedger;
            $arAccount = LedgerAccount::where('company_id', $lockedCompany->id)->where('system_key', 'accounts_receivable')->firstOrFail();
            $fxGainAccount = LedgerAccount::where('company_id', $lockedCompany->id)->where('system_key', 'fx_gain')->firstOrFail();
            $fxLossAccount = LedgerAccount::where('company_id', $lockedCompany->id)->where('system_key', 'fx_loss')->firstOrFail();

            $postingLines = [];
            $lineNum = 1;

            // Dr Money Account (Cash/Bank)
            app(SalesPostingLines::class)->append($postingLines,
                lineNumber: $lineNum++,
                ledgerAccountId: $cashLedgerAccount->id,
                debitBase: MoneyAmount::from((string) $paymentAmountBase->toScale(6)),
                creditBase: MoneyAmount::from('0.000000'),
                transactionCurrencyCode: $currency,
                transactionAmount: MoneyAmount::from((string) $totalAmount->toScale(6)),
                exchangeRate: ExchangeRate::from((string) $paymentFx->toScale(10)),
                description: "Customer Receipt {$paymentNumber} - {$customer->displayName()}",
            );

            // Cr Accounts Receivable (Allocated per invoice at invoice historical rate)
            foreach ($preparedAllocations as $pAlloc) {
                $allocAmt = BigDecimal::of($pAlloc['allocated_amount']);
                $allocBase = BigDecimal::of($pAlloc['base_amount_applied_to_receivable']);
                $invFx = ExchangeRate::from($pAlloc['invoice_exchange_rate']);

                app(SalesPostingLines::class)->append($postingLines,
                    lineNumber: $lineNum++,
                    ledgerAccountId: $arAccount->id,
                    debitBase: MoneyAmount::from('0.000000'),
                    creditBase: MoneyAmount::from((string) $allocBase->toScale(6)),
                    transactionCurrencyCode: $currency,
                    transactionAmount: MoneyAmount::from((string) $allocAmt->toScale(6)),
                    exchangeRate: $invFx,
                    description: "Customer Receipt {$paymentNumber} AR Relief Inv #{$pAlloc['sales_invoice_id']}",
                );
            }

            // Cr Accounts Receivable (Unallocated advance at payment rate)
            if ($unallocatedAmount->isPositive()) {
                app(SalesPostingLines::class)->append($postingLines,
                    lineNumber: $lineNum++,
                    ledgerAccountId: $arAccount->id,
                    debitBase: MoneyAmount::from('0.000000'),
                    creditBase: MoneyAmount::from((string) $unallocatedBase->toScale(6)),
                    transactionCurrencyCode: $currency,
                    transactionAmount: MoneyAmount::from((string) $unallocatedAmount->toScale(6)),
                    exchangeRate: ExchangeRate::from((string) $paymentFx->toScale(10)),
                    description: "Customer Receipt {$paymentNumber} Advance/Unallocated",
                );
            }

            // Realized FX Gain (Credit)
            if ($totalRealizedFxGainBase->isPositive()) {
                app(SalesPostingLines::class)->append($postingLines,
                    lineNumber: $lineNum++,
                    ledgerAccountId: $fxGainAccount->id,
                    debitBase: MoneyAmount::from('0.000000'),
                    creditBase: MoneyAmount::from((string) $totalRealizedFxGainBase->toScale(6)),
                    description: "Customer Receipt {$paymentNumber} Realized FX Gain",
                );
            }

            // Realized FX Loss (Debit)
            if ($totalRealizedFxLossBase->isPositive()) {
                app(SalesPostingLines::class)->append($postingLines,
                    lineNumber: $lineNum++,
                    ledgerAccountId: $fxLossAccount->id,
                    debitBase: MoneyAmount::from((string) $totalRealizedFxLossBase->toScale(6)),
                    creditBase: MoneyAmount::from('0.000000'),
                    description: "Customer Receipt {$paymentNumber} Realized FX Loss",
                );
            }

            // Create payment record first to obtain authoritative ID
            $payment = CustomerPayment::create([
                'public_id' => (string) Str::ulid(),
                'company_id' => $lockedCompany->id,
                'payment_number' => $paymentNumber,
                'customer_id' => $customer->id,
                'money_account_id' => $moneyAccount->id,
                'payment_date' => $paymentDate,
                'payment_method' => $method,
                'currency_code' => $currency,
                'amount' => (string) $totalAmount->toScale(6),
                'exchange_rate' => (string) $paymentFx->toScale(10),
                'amount_base' => (string) $paymentAmountBase->toScale(6),
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'created_by' => $user->id,
                'company_snapshot' => $lockedCompany->only(['name_ar', 'name_en', 'phone', 'email', 'address_ar', 'address_en', 'tax_number']),
                'customer_snapshot' => $customer->only(['name_ar', 'name_en', 'business_name_ar', 'business_name_en', 'business_name', 'address_line_1_ar', 'address_line_1_en', 'phone', 'email', 'address_ar', 'address_en']),
                'money_account_snapshot' => $moneyAccount->only(['name_ar', 'name_en']),
                'document_locale' => $documentLocale,
            ]);

            // Save allocations
            foreach ($preparedAllocations as $allocData) {
                CustomerPaymentAllocation::create(array_merge($allocData, [
                    'company_id' => $lockedCompany->id,
                    'customer_payment_id' => $payment->id,
                ]));
            }

            // Now post GL with accurate sourceId and idempotency key
            $finalPostingCmd = new PostingCommand(
                company: $lockedCompany,
                postingDate: Carbon::parse($paymentDate),
                sourceType: 'customer_payment',
                sourceId: $payment->id,
                transactionCurrencyCode: $currency,
                baseCurrencyCode: $lockedCompany->base_currency_code,
                exchangeRate: ExchangeRate::from((string) $paymentFx->toScale(10)),
                idempotencyKey: "customer_payment_{$payment->id}_posting",
                postedBy: $user,
                description: "Customer Receipt {$paymentNumber}",
                lines: $postingLines,
            );

            $batch = $this->accountingPostingService->post($finalPostingCmd);

            $payment->completeCanonicalPost($batch, $user);

            return $payment->fresh(['allocations', 'customer', 'moneyAccount', 'postingBatch']);
        });
    }
}
