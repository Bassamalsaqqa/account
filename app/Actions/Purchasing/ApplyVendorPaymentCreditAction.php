<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Exceptions\IdempotencyConflictException;
use App\Models\CompanyCurrency;
use App\Models\LedgerAccount;
use App\Models\Purchase;
use App\Models\User;
use App\Models\VendorPayment;
use App\Models\VendorPaymentApplicationEvent;
use App\Services\Audit\AuditService;
use App\Services\Money\PaymentAllocationIntent;
use App\Services\Posting\AccountingPostingService;
use App\Services\Purchasing\PayableBookValue;
use App\Services\Purchasing\PurchasePostingCommandBuilder;
use App\Services\Purchasing\VendorPaymentApplicationCapability;
use App\Services\Purchasing\VendorPaymentApplicationIntegrityValidator;
use App\Services\Purchasing\VendorPaymentApplicationScope;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use App\Services\Purchasing\VendorPaymentValidationException;
use App\Services\Sales\ReceiptRequestValues;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use App\Services\Sales\SalesPostingLines;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class ApplyVendorPaymentCreditAction
{
    private ?VendorPaymentApplicationScope $activeApplicationScope = null;

    public function __construct(
        protected VendorPaymentApplicationScope $applicationScope,
        protected AccountingPostingService $accountingPostingService,
    ) {}

    public function ownsApplicationScope(VendorPaymentApplicationScope $scope): bool
    {
        return $this->activeApplicationScope === $scope;
    }

    /**
     * @param array{
     *     application_date: string,
     *     idempotency_key: string,
     *     allocations: list<array{purchase_id: int, allocated_amount: string|BigDecimal}>
     * } $data
     */
    public function execute(VendorPayment $payment, User $actor, array $data): VendorPaymentApplicationEvent
    {
        $key = ReceiptRequestValues::key($data['idempotency_key']);
        app(SalesDocumentRules::class)->date($data['application_date']);

        $version = PaymentAllocationIntent::version($data['allocations']);
        $intent = PaymentAllocationIntent::normalize($data['allocations'], 'purchase_id');
        if ($intent === []) {
            throw new \InvalidArgumentException('Application requires a positive allocation.');
        }
        $hash = self::requestHash((int) $payment->company_id, (int) $payment->id, (int) $actor->id, (string) $data['application_date'], $intent);

        return DB::transaction(function () use ($payment, $actor, $data, $key, $hash, $intent, $version): VendorPaymentApplicationEvent {
            $company = app(SalesActorGuard::class)->lockAndAuthorize((int) $payment->company_id, $actor, 'money.vendor_payment.allocate');
            app(SalesActorGuard::class)->lockAndAuthorize((int) $payment->company_id, $actor, 'purchasing.cost.view');

            /** @var VendorPayment $locked */
            $locked = VendorPayment::where('company_id', $company->id)->lockForUpdate()->findOrFail($payment->id);

            $existing = VendorPaymentApplicationEvent::where('company_id', $company->id)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->request_hash === null || $existing->request_hash !== $hash) {
                    throw new IdempotencyConflictException('Vendor advance application key already used with different request payload.');
                }

                app(VendorPaymentApplicationIntegrityValidator::class)->validate($existing);

                return $existing->load('allocations');
            }

            if ($locked->is_reversed || $locked->posting_batch_id === null) {
                throw new VendorPaymentValidationException('purchasing.advance_not_available', 'Cannot apply advance from a reversed or unposted vendor payment.');
            }

            // Strictly validate locked payment integrity
            app(VendorPaymentPostedIntegrityValidator::class)->validate($locked);

            // Enabled currency check for new application
            if (! CompanyCurrency::where('company_id', $company->id)->where('currency_code', $locked->currency_code)->where('enabled', true)->exists()) {
                throw new VendorPaymentValidationException('purchasing.disabled_currency');
            }

            $appDate = (string) $data['application_date'];
            $paymentDate = $locked->payment_date instanceof Carbon ? $locked->payment_date->format('Y-m-d') : (string) $locked->payment_date;
            if ($appDate < $paymentDate) {
                throw new VendorPaymentValidationException('purchasing.application_date_too_early', 'Advance application date cannot precede vendor payment date or cannot precede purchase date.');
            }

            VendorPaymentApplicationEvent::where('vendor_payment_id', $locked->id)->orderBy('id')->lockForUpdate()->get();

            $prepared = $this->prepare($locked, $intent, $appDate);

            $accounts = LedgerAccount::where('company_id', $company->id)
                ->whereIn('system_key', ['accounts_payable', 'fx_gain', 'fx_loss'])
                ->get()
                ->keyBy('system_key');

            $lines = [];
            foreach ($prepared as $allocation) {
                $fx = BigDecimal::of($allocation['realized_fx_gain_loss_base']); // delta = S - B
                if ($fx->isZero()) {
                    continue;
                }
                $zero = MoneyAmount::from('0.000000');
                $amount = MoneyAmount::from((string) $fx->abs()->toScale(6));

                // delta > 0: Dr FX Loss delta / Cr AP delta
                // delta < 0: Dr AP abs(delta) / Cr FX Gain abs(delta)
                if ($fx->isPositive()) {
                    // Dr FX Loss delta
                    app(SalesPostingLines::class)->append(
                        $lines,
                        count($lines) + 1,
                        $accounts['fx_loss']->id,
                        debitBase: $amount,
                        creditBase: $zero,
                        description: 'Vendor advance application realized FX loss'
                    );
                    // Cr AP delta
                    app(SalesPostingLines::class)->append(
                        $lines,
                        count($lines) + 1,
                        $accounts['accounts_payable']->id,
                        debitBase: $zero,
                        creditBase: $amount,
                        description: 'Vendor advance application AP delta'
                    );
                } else {
                    // Dr AP abs(delta)
                    app(SalesPostingLines::class)->append(
                        $lines,
                        count($lines) + 1,
                        $accounts['accounts_payable']->id,
                        debitBase: $amount,
                        creditBase: $zero,
                        description: 'Vendor advance application AP delta'
                    );
                    // Cr FX Gain abs(delta)
                    app(SalesPostingLines::class)->append(
                        $lines,
                        count($lines) + 1,
                        $accounts['fx_gain']->id,
                        debitBase: $zero,
                        creditBase: $amount,
                        description: 'Vendor advance application realized FX gain'
                    );
                }
            }

            $this->activeApplicationScope = $this->applicationScope;
            try {
                return $this->activeApplicationScope->withinCanonicalApplication(
                    $this,
                    (int) $company->id,
                    (int) $locked->id,
                    $actor,
                    $prepared,
                    function (VendorPaymentApplicationCapability $capability) use ($company, $locked, $actor, $appDate, $key, $hash, $prepared, $lines, $version): VendorPaymentApplicationEvent {
                        $event = VendorPaymentApplicationEvent::recordCanonicalApplication(
                            $locked,
                            $actor,
                            ['application_date' => $appDate, 'idempotency_key' => $key, 'request_hash' => $hash, 'allocation_version' => $version],
                            $prepared,
                            $capability
                        );

                        $batch = $lines === [] ? null : $this->accountingPostingService->post(new PostingCommand(
                            company: $company,
                            postingDate: Carbon::parse($appDate),
                            sourceType: 'vendor_payment_application',
                            sourceId: $event->id,
                            transactionCurrencyCode: $locked->currency_code,
                            baseCurrencyCode: $company->base_currency_code,
                            exchangeRate: ExchangeRate::from($locked->exchange_rate),
                            idempotencyKey: "vendor-credit:{$event->id}:apply:v1",
                            postedBy: $actor,
                            description: 'Vendor advance application',
                            lines: $lines
                        ));

                        $event->completeCanonicalApplication($batch, $actor, $capability);

                        // Safe nonmonetary audit event
                        app(AuditService::class)->log(
                            (int) $company->id,
                            'vendor_payment.credit_applied',
                            'Vendor payment credit applied',
                            (int) $actor->id,
                            $locked,
                            null,
                            null,
                            [
                                'payment_id' => (int) $locked->id,
                                'event_id' => (int) $event->id,
                                'vendor_id' => (int) $locked->vendor_id,
                                'lifecycle' => 'credit_applied',
                            ],
                        );

                        return $event->load('allocations');
                    }
                );
            } finally {
                $this->activeApplicationScope = null;
            }
        });
    }

    /**
     * @param  list<array{purchase_id: int, allocated_amount: string}>  $intent
     * @return list<array<string, mixed>>
     */
    public function prepare(VendorPayment $payment, array $intent, ?string $applicationDate = null): array
    {
        $usedAmount = BigDecimal::of((string) $payment->amount)->minus(BigDecimal::of((string) $payment->unallocated_amount));
        $usedBase = BigDecimal::of((string) $payment->amount_base)->minus(BigDecimal::of((string) $payment->unallocated_amount_base));
        $remaining = BigDecimal::of((string) $payment->unallocated_amount);
        $minor = in_array($payment->currency_code, ['JOD', 'KWD', 'BHD', 'OMR'], true) ? 3 : 2;
        $prepared = [];

        foreach ($intent as $input) {
            /** @var Purchase|null $purchase */
            $purchase = Purchase::where('company_id', $payment->company_id)->lockForUpdate()->find($input['purchase_id']);
            if ($purchase === null || ! $purchase->isPosted() || (int) $purchase->vendor_id !== (int) $payment->vendor_id) {
                throw new VendorPaymentValidationException('purchasing.application_purchase_invalid');
            }

            app(PurchasePostingCommandBuilder::class)->validatePosted($purchase);

            if ($applicationDate !== null) {
                $purchaseDate = $purchase->purchase_date->format('Y-m-d');
                if ($applicationDate < $purchaseDate) {
                    throw new VendorPaymentValidationException('purchasing.application_date_too_early', 'Advance application date cannot precede vendor payment date or cannot precede purchase date.');
                }
            }

            if (CompanyCurrency::where('company_id', $payment->company_id)->whereIn('currency_code', array_unique([$payment->currency_code, $purchase->currency_code]))->where('enabled', true)->count() !== count(array_unique([$payment->currency_code, $purchase->currency_code]))) {
                throw new \InvalidArgumentException('Payment and document currencies must be enabled.');
            }
            $amounts = PaymentAllocationIntent::amounts($input, $purchase->currency_code, $payment->currency_code);
            $amount = $amounts['document'];
            $consumed = $amounts['payment'];
            if ($consumed->isGreaterThan($remaining) || $amount->isGreaterThan($purchase->calculateOutstanding())) {
                throw new \InvalidArgumentException('Application exceeds payment credit or document outstanding.');
            }
            $book = app(PayableBookValue::class)->relief($purchase, $amount);
            $usedAmount = $usedAmount->plus($consumed);

            $targetBase = $usedAmount->isEqualTo(BigDecimal::of((string) $payment->amount))
                ? BigDecimal::of((string) $payment->amount_base)
                : $usedAmount->multipliedBy(BigDecimal::of((string) $payment->exchange_rate))->toScale(6, RoundingMode::HALF_UP);

            $settlement = $targetBase->minus($usedBase);

            if ($book->isZero() || $settlement->isZero()) {
                throw new VendorPaymentValidationException('purchasing.payment_component_unrepresentable');
            }

            $delta = $settlement->minus($book); // S - B

            $prepared[] = [
                'purchase_id' => $purchase->id,
                'allocated_amount' => (string) $amount->toScale(6), 'payment_currency_amount' => (string) $consumed->toScale(6),
                'purchase_exchange_rate' => (string) $purchase->exchange_rate,
                'payment_exchange_rate' => (string) $payment->exchange_rate,
                'base_amount_applied_to_payable' => (string) $book->toScale(6),
                'settlement_base_value' => (string) $settlement->toScale(6),
                'realized_fx_gain_loss_base' => (string) $delta->toScale(6),
            ];

            $usedBase = $targetBase;
            $remaining = $remaining->minus($consumed);
        }

        return $prepared;
    }

    /**
     * @param  list<array{purchase_id: int, allocated_amount: string}>  $intent
     */
    public static function requestHash(int $companyId, int $paymentId, int $actorId, string $date, array $intent): string
    {
        return hash('sha256', json_encode([
            'company_id' => $companyId,
            'payment_id' => $paymentId,
            'actor_id' => $actorId,
            'application_date' => $date,
            'allocations' => $intent,
        ], JSON_THROW_ON_ERROR));
    }
}
