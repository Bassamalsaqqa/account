<?php

declare(strict_types=1);

namespace App\Application\Commands;

use App\Actions\Money\IssueCheckAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Application\Platform\TenantWriteAccess;
use App\Models\Check;
use App\Models\CommandReceipt;
use App\Models\Company;
use App\Models\MoneyAccount;
use App\Models\Purchase;
use App\Models\User;
use App\Models\VendorPayment;
use App\Services\Money\CheckCreation;
use App\Services\Money\CheckHistory;
use App\Services\Money\CheckPaymentSource;
use App\Services\Money\MoneyAccountLedger;
use App\Services\Money\MoneyValues;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Services\Purchasing\PurchaseDraftIntegrity;
use App\Services\Purchasing\PurchaseDraftSnapshot;
use App\Services\Purchasing\PurchasePostingCommandBuilder;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use App\Services\Sales\ReceiptRequestValues;
use App\Services\Sales\SalesActorGuard;
use App\Services\Security\CommandSessionGuard;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Atomic purchase/payment composition used by the reviewed purchase workspace. */
final class ImmediatePurchaseCommands
{
    private const ACTION = 'purchasing.purchase.immediate_settlement';

    public function __construct(
        private PostPurchaseAction $purchases,
        private PostVendorPaymentAction $payments,
    ) {}

    /**
     * Payment choice is explicit. The encrypted confirmation binds both economic legs.
     *
     * @param  array<string, mixed>  $choice
     * @return array<string, mixed>
     */
    public function preview(Purchase $purchase, User $actor, array $choice): array
    {
        return DB::transaction(function () use ($purchase, $actor, $choice): array {
            $this->authorize($purchase, $actor, ($choice['mode'] ?? null) !== 'unpaid', ($choice['payment_method'] ?? null) === 'check');
            $locked = Purchase::where('company_id', $purchase->company_id)->lockForUpdate()->findOrFail($purchase->id);
            $locked->assertMutableDraft();
            $company = Company::findOrFail($locked->company_id);
            app(PurchaseDraftIntegrity::class)->validate($company, $locked);
            $intent = $this->intent($locked, $actor, $choice);
            $terms = $this->paymentTerms($locked, $intent);
            $draftSnapshot = app(PurchaseDraftSnapshot::class)->capture($locked);
            $draftDigest = app(PurchaseDraftSnapshot::class)->digest($draftSnapshot);
            $token = Crypt::encryptString(json_encode([
                'version' => 1,
                'company_id' => (int) $locked->company_id,
                'actor_id' => (int) $actor->id,
                'purchase_id' => (int) $locked->id,
                'purchase_public_id' => $locked->public_id,
                'expires_at' => now()->addMinutes(5)->timestamp,
                'purchase_payload_hash' => $draftDigest,
                'intent' => $intent,
                'payment_terms' => $terms,
            ], JSON_THROW_ON_ERROR));

            return [
                'purchase' => $locked,
                'confirmation_token' => $token,
                'settlement' => $terms,
                'can_execute' => true,
                'blockers' => [],
            ];
        });
    }

    /**
     * @return array{purchase: Purchase, payment: VendorPayment|null}
     */
    public function execute(Purchase $purchase, User $actor, string $token, string $key): array
    {
        $key = ReceiptRequestValues::key($key);
        if ($token === '' || strlen($token) > 16384) {
            throw new InvalidArgumentException('A bounded reviewed purchase confirmation is required.');
        }
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Invalid purchase confirmation.', previous: $exception);
        }
        if (! is_array($payload) || ($payload['version'] ?? null) !== 1
            || ($payload['company_id'] ?? null) !== (int) $purchase->company_id
            || ($payload['actor_id'] ?? null) !== (int) $actor->id
            || ($payload['purchase_id'] ?? null) !== (int) $purchase->id
            || ($payload['purchase_public_id'] ?? null) !== $purchase->public_id
            || ! is_array($payload['intent'] ?? null)) {
            throw new AuthorizationException('Purchase confirmation does not match the company, actor and purchase.');
        }

        return app(TenantWriteAccess::class)->transaction((int) $purchase->company_id, function () use ($purchase, $actor, $token, $key, $payload): array {
            $paid = $payload['intent']['mode'] !== 'unpaid';
            $check = ($payload['intent']['payment_method'] ?? null) === 'check';
            $this->authorize($purchase, $actor, $paid, $check);
            $locked = Purchase::where('company_id', $purchase->company_id)->lockForUpdate()->findOrFail($purchase->id);
            $hash = hash('sha256', $locked->id.'|'.$token);
            $suffix = hash('sha256', $actor->id.'|'.$locked->id.'|'.$key);
            $paymentKey = 'fin1-payment:'.$suffix;
            $receipt = CommandReceipt::where('company_id', $locked->company_id)->where('user_id', $actor->id)
                ->where('action', self::ACTION)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($receipt !== null) {
                if (! hash_equals($receipt->request_hash, $hash)) {
                    throw new HttpException(409, 'Purchase retry differs from its reviewed request.');
                }

                $payment = null;
                if ($paid) {
                    $payments = VendorPayment::where('company_id', $locked->company_id)->where('vendor_id', $locked->vendor_id);
                    if ($check) {
                        $instrument = Check::where('company_id', $locked->company_id)->where('idempotency_key', 'fin1-check:'.$suffix)->firstOrFail();
                        app(CheckHistory::class)->validate($instrument);
                        $payment = $payments->where('check_id', $instrument->id)->firstOrFail();
                    } else {
                        $payment = $payments->where('idempotency_key', $paymentKey)->firstOrFail();
                    }
                    app(VendorPaymentPostedIntegrityValidator::class)->validate($payment);
                }

                app(PurchasePostingCommandBuilder::class)->validatePosted($locked);

                return ['purchase' => $locked, 'payment' => $payment];
            }

            if (($payload['expires_at'] ?? 0) <= now()->timestamp || ! $locked->isDraft()) {
                throw new HttpException(409, 'Purchase confirmation expired or the purchase is no longer a draft.');
            }

            $expectedDraftDigest = $payload['purchase_payload_hash'] ?? null;
            if (! is_string($expectedDraftDigest) || ! app(PurchaseDraftSnapshot::class)->verify($locked, $expectedDraftDigest)) {
                throw new HttpException(409, 'Purchase draft changed since review; review the purchase again.');
            }
            try {
                $intent = $this->intent($locked, $actor, $payload['intent']);
                $terms = $this->paymentTerms($locked, $intent);
            } catch (InvalidArgumentException|ModelNotFoundException $exception) {
                throw new HttpException(409, 'Payment context changed; review the purchase again.', $exception);
            }
            if ($terms !== ($payload['payment_terms'] ?? null)) {
                throw new HttpException(409, 'Payment context changed; review the purchase again.');
            }

            $posted = $this->purchases->execute($locked, $actor);
            $payment = null;

            if ($paid && $check) {
                $instrument = app(IssueCheckAction::class)->execute(Company::findOrFail($locked->company_id), $actor, [
                    'source_type' => 'vendor_payment',
                    'party_id' => (int) $locked->vendor_id,
                    'vendor_id' => (int) $locked->vendor_id,
                    'money_account_id' => $intent['money_account_id'],
                    'date' => $intent['payment_date'],
                    'currency_code' => $intent['currency_code'],
                    'amount' => $intent['amount'],
                    'exchange_rate' => $intent['exchange_rate'],
                    'check_number' => $intent['check_number'],
                    'bank_name' => $intent['bank_name'],
                    'drawer' => $intent['drawer'],
                    'due_date' => $intent['due_date'],
                    'notes' => $intent['notes'],
                    'document_locale' => $locked->document_locale,
                    'idempotency_key' => 'fin1-check:'.$suffix,
                    'allocations' => [[
                        'purchase_id' => (int) $locked->id,
                        'allocated_amount' => $terms['purchase_amount'],
                        'payment_currency_amount' => $terms['payment_currency_amount'],
                    ]],
                ]);
                $payment = $instrument->vendorPayment()->firstOrFail();
            } elseif ($paid) {
                $payment = $this->payments->execute(Company::findOrFail($locked->company_id), $actor, [
                    'vendor_id' => (int) $locked->vendor_id,
                    'money_account_id' => $intent['money_account_id'],
                    'payment_method' => $intent['payment_method'],
                    'payment_date' => $intent['payment_date'],
                    'amount' => $intent['amount'],
                    'exchange_rate' => $intent['exchange_rate'],
                    'reference_number' => $intent['reference_number'],
                    'notes' => $intent['notes'],
                    'document_locale' => $locked->document_locale,
                    'idempotency_key' => $paymentKey,
                    'allocations' => [[
                        'purchase_id' => (int) $locked->id,
                        'allocated_amount' => $terms['purchase_amount'],
                        'payment_currency_amount' => $terms['payment_currency_amount'],
                    ]],
                ]);
            }

            CommandReceipt::create([
                'company_id' => $locked->company_id,
                'user_id' => $actor->id,
                'action' => self::ACTION,
                'idempotency_key' => $key,
                'target_invoice_id' => null,
                'sales_invoice_id' => null,
                'request_hash' => $hash,
            ]);

            return ['purchase' => $posted, 'payment' => $payment];
        });
    }

    private function authorize(Purchase $purchase, User $actor, bool $paid, bool $check = false): void
    {
        Company::whereKey($purchase->company_id)->lockForUpdate()->firstOrFail();
        $guard = app(SalesActorGuard::class);
        $guard->lockAndAuthorize((int) $purchase->company_id, $actor, 'purchasing.purchase.post');
        $guard->lockAndAuthorize((int) $purchase->company_id, $actor, 'purchasing.cost.view');
        if ($paid) {
            $guard->lockAndAuthorize((int) $purchase->company_id, $actor, 'money.vendor_payment.create');
        }
        if ($check) {
            $guard->lockAndAuthorize((int) $purchase->company_id, $actor, 'money.check.outgoing.manage');
        }
        if (request()->attributes->has('phase11_api_session')) {
            app(CommandSessionGuard::class)->authorize($actor);
        }
    }

    /**
     * @param  array<string, mixed>  $choice
     * @return array<string, mixed>
     */
    private function intent(Purchase $purchase, User $actor, array $choice): array
    {
        $mode = $choice['mode'] ?? null;
        if (! in_array($mode, ['unpaid', 'full', 'partial'], true)) {
            throw new InvalidArgumentException('Choose unpaid, full or partial payment explicitly.');
        }
        if ($mode === 'unpaid') {
            if (array_diff(array_keys($choice), ['mode']) !== []) {
                throw new InvalidArgumentException('An unpaid purchase cannot carry payment instructions.');
            }

            return ['mode' => 'unpaid'];
        }
        $check = ($choice['payment_method'] ?? null) === 'check';
        $fields = ['mode', 'payment_method', 'money_account_id', 'payment_date', 'amount', 'exchange_rate', 'purchase_amount', 'payment_currency_amount', 'notes'];
        $fields = [...$fields, ...($check ? ['currency_code', 'check_number', 'bank_name', 'drawer', 'due_date'] : ['reference_number'])];
        if (array_diff(array_keys($choice), $fields) !== []
            || ! in_array($choice['payment_method'] ?? null, [VendorPayment::METHOD_CASH, VendorPayment::METHOD_BANK, 'check'], true)) {
            throw new InvalidArgumentException('Choose a supported payment method and matching instructions.');
        }
        app(PurchaseDocumentRules::class)->date($choice['payment_date'] ?? '');

        $company = Company::findOrFail($purchase->company_id);
        $paymentCurrency = MoneyAccount::where('company_id', $company->id)->whereKey((int) ($choice['money_account_id'] ?? 0))->value('currency_code');
        if ($paymentCurrency === null) {
            throw new InvalidArgumentException('Choose an active company payment account.');
        }
        if ($check) {
            $choice['currency_code'] = $paymentCurrency;
        }
        if ($paymentCurrency === $company->base_currency_code && ($choice['exchange_rate'] ?? '') === '') {
            $choice['exchange_rate'] = '1.0000000000';
        }

        $instrument = [];
        if ($check) {
            $currency = $choice['currency_code'] ?? null;
            if (! is_string($currency)) {
                throw new InvalidArgumentException('Choose the check currency explicitly.');
            }
            $normalized = app(CheckCreation::class)->intent(Company::findOrFail($purchase->company_id), $actor, [
                ...$choice,
                'source_type' => 'vendor_payment',
                'party_id' => (int) $purchase->vendor_id,
                'vendor_id' => (int) $purchase->vendor_id,
                'money_account_id' => (int) $choice['money_account_id'],
                'check_number' => $choice['check_number'] ?? null,
                'bank_name' => $choice['bank_name'] ?? null,
            ], 'outgoing');
            if ($normalized['due_date'] < $choice['payment_date']) {
                throw new InvalidArgumentException('Check due date cannot precede its payment date.');
            }
            $instrument = array_intersect_key($normalized, array_flip(['currency_code', 'check_number', 'bank_name', 'drawer', 'due_date']));
        }

        return [
            'mode' => $mode,
            'money_account_id' => ReceiptRequestValues::id($choice['money_account_id'] ?? null),
            ...($check ? $instrument : []),
            'payment_method' => $choice['payment_method'],
            'payment_date' => $choice['payment_date'],
            'amount' => ReceiptRequestValues::decimal($choice['amount'] ?? null, 6),
            'exchange_rate' => ReceiptRequestValues::decimal($choice['exchange_rate'] ?? null, 10),
            'purchase_amount' => $mode === 'full' ? (string) $purchase->grand_total_currency
                : ReceiptRequestValues::decimal($choice['purchase_amount'] ?? null, 6),
            'payment_currency_amount' => (isset($choice['payment_currency_amount']) && $choice['payment_currency_amount'] !== '')
                ? ReceiptRequestValues::decimal($choice['payment_currency_amount'], 6) : null,
            ...($check ? [] : ['reference_number' => MoneyValues::text($choice['reference_number'] ?? null, 255)]),
            'notes' => MoneyValues::text($choice['notes'] ?? null),
        ];
    }

    /**
     * Read-only, exact preview. Canonical payment validation runs again before commit.
     *
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function paymentTerms(Purchase $purchase, array $intent): array
    {
        $total = BigDecimal::of((string) $purchase->grand_total_currency)->toScale(6);
        if ($intent['mode'] === 'unpaid') {
            return ['mode' => 'unpaid', 'outstanding_purchase_amount' => (string) $total];
        }
        $account = null;
        if ($intent['payment_method'] === 'check') {
            $instrument = new Check;
            $instrument->forceFill(['company_id' => $purchase->company_id, 'direction' => 'outgoing']);
            $ledger = app(CheckPaymentSource::class)->ledger($instrument);
            $account = MoneyAccount::where('company_id', $purchase->company_id)->lockForUpdate()->findOrFail($intent['money_account_id']);
            if (! $account->is_active || $account->account_type !== MoneyAccount::TYPE_BANK || $account->currency_code !== $intent['currency_code']) {
                throw new InvalidArgumentException('Outgoing check requires an active Bank account in its currency.');
            }
            app(MoneyAccountLedger::class)->validate($account, true);
            $currency = $intent['currency_code'];
        } else {
            $account = MoneyAccount::where('company_id', $purchase->company_id)->lockForUpdate()->findOrFail($intent['money_account_id']);
            $expectedType = $intent['payment_method'] === VendorPayment::METHOD_CASH ? MoneyAccount::TYPE_CASH : MoneyAccount::TYPE_BANK;
            if (! $account->is_active || $account->account_type !== $expectedType) {
                throw new InvalidArgumentException('Choose an active matching company payment account.');
            }
            $ledger = app(MoneyAccountLedger::class)->validate($account, true);
            $currency = $account->currency_code;
        }
        $company = Company::findOrFail($purchase->company_id);
        $amount = MoneyValues::amount($intent['amount'], $currency);
        $allocated = MoneyValues::amount($intent['purchase_amount'], $purchase->currency_code);
        $rate = MoneyValues::rate($intent['exchange_rate'], $currency, $company->base_currency_code);
        app(PurchaseDocumentRules::class)->date($intent['payment_date']);
        $sameCurrency = $currency === $purchase->currency_code;
        if (! $sameCurrency && $intent['payment_currency_amount'] === null) {
            throw new InvalidArgumentException('Review both currency amounts for cross-currency settlement.');
        }
        $consumed = MoneyValues::amount($intent['payment_currency_amount'] ?? (string) $allocated, $currency);
        if (($sameCurrency && ! $consumed->isEqualTo($allocated)) || $allocated->isGreaterThan($total)
            || ($intent['mode'] === 'full' && ! $allocated->isEqualTo($total))
            || ($intent['mode'] === 'partial' && ! $allocated->isLessThan($total)) || $consumed->isGreaterThan($amount)) {
            throw new InvalidArgumentException('Reviewed payment and allocation amounts do not match the chosen purchase mode.');
        }

        return [
            'mode' => $intent['mode'],
            'money_account_id' => (int) $account->id,
            'payment_method' => $intent['payment_method'],
            'payment_date' => $intent['payment_date'],
            'account_name_ar' => $account->name_ar,
            'account_name_en' => $account->name_en,
            ...($intent['payment_method'] === 'check' ? array_intersect_key($intent, array_flip(['check_number', 'bank_name', 'drawer', 'due_date'])) : []),
            'payment_currency' => $currency,
            'purchase_currency' => $purchase->currency_code,
            'paid_amount' => (string) $amount,
            'exchange_rate' => (string) $rate,
            'purchase_amount' => (string) $allocated,
            'payment_currency_amount' => (string) $consumed,
            'outstanding_purchase_amount' => (string) $total->minus($allocated),
            'unallocated_payment_amount' => (string) $amount->minus($consumed),
            'account_revision' => hash('sha256', json_encode([$account->only(['name_ar', 'name_en', 'currency_code', 'account_type', 'is_active', 'ledger_account_id']), $ledger->only(['id', 'code', 'active', 'is_control', 'parent_id', 'account_type', 'normal_balance'])], JSON_THROW_ON_ERROR)),
        ];
    }
}
