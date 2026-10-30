<?php

declare(strict_types=1);

namespace App\Application\Commands;

use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Application\Platform\TenantWriteAccess;
use App\Models\Check;
use App\Models\CommandReceipt;
use App\Models\Company;
use App\Models\CustomerPayment;
use App\Models\MoneyAccount;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Money\CheckCreation;
use App\Services\Money\CheckHistory;
use App\Services\Money\CheckPaymentSource;
use App\Services\Money\MoneyAccountLedger;
use App\Services\Money\MoneyValues;
use App\Services\Sales\ReceiptRequestValues;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use App\Services\Security\CommandSessionGuard;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Atomic invoice/receipt composition used by the reviewed invoice workspace. */
final class ImmediateSaleCommands
{
    private const ACTION = 'sales_invoice.immediate_settlement';

    public function __construct(
        private SalesInvoiceCommands $invoices,
        private PostCustomerPaymentAction $payments,
    ) {}

    /** Payment choice is explicit. The encrypted confirmation binds both economic legs.
     * @param  array<string, mixed>  $choice
     * @return array<string, mixed>
     */
    public function preview(SalesInvoice $invoice, User $actor, array $choice): array
    {
        return DB::transaction(function () use ($invoice, $actor, $choice): array {
            $this->authorize($invoice, $actor, ($choice['mode'] ?? null) !== 'unpaid', ($choice['payment_method'] ?? null) === 'check');
            $locked = SalesInvoice::where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($invoice->id);
            $preview = $this->invoices->preview($locked, $actor);
            $intent = $this->intent($locked, $actor, $choice);
            $terms = $this->paymentTerms($locked, $intent);
            $token = Crypt::encryptString(json_encode([
                'version' => 1, 'company_id' => (int) $locked->company_id, 'actor_id' => (int) $actor->id,
                'invoice_id' => (int) $locked->id, 'invoice_public_id' => $locked->public_id,
                'expires_at' => now()->addMinutes(5)->timestamp,
                'invoice_confirmation_token' => $preview['confirmation_token'],
                'intent' => $intent, 'payment_terms' => $terms,
            ], JSON_THROW_ON_ERROR));
            $preview['confirmation_token'] = $token;
            $preview['settlement'] = $terms;

            return $preview;
        });
    }

    /** @return array{invoice: SalesInvoice, payment: CustomerPayment|null} */
    public function execute(SalesInvoice $invoice, User $actor, string $token, string $key): array
    {
        $key = ReceiptRequestValues::key($key);
        if ($token === '' || strlen($token) > 16384) {
            throw new InvalidArgumentException('A bounded reviewed sale confirmation is required.');
        }
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Invalid sale confirmation.', previous: $exception);
        }
        if (! is_array($payload) || ($payload['version'] ?? null) !== 1
            || ($payload['company_id'] ?? null) !== (int) $invoice->company_id
            || ($payload['actor_id'] ?? null) !== (int) $actor->id
            || ($payload['invoice_id'] ?? null) !== (int) $invoice->id
            || ($payload['invoice_public_id'] ?? null) !== $invoice->public_id
            || ! is_array($payload['intent'] ?? null)) {
            throw new AuthorizationException('Sale confirmation does not match the company, actor and invoice.');
        }

        return app(TenantWriteAccess::class)->transaction((int) $invoice->company_id, function () use ($invoice, $actor, $token, $key, $payload): array {
            $paid = $payload['intent']['mode'] !== 'unpaid';
            $check = ($payload['intent']['payment_method'] ?? null) === 'check';
            $this->authorize($invoice, $actor, $paid, $check);
            $locked = SalesInvoice::where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($invoice->id);
            $hash = hash('sha256', $locked->id.'|'.$token);
            $suffix = hash('sha256', $actor->id.'|'.$locked->id.'|'.$key);
            $paymentKey = 'fin1-payment:'.$suffix;
            $receipt = CommandReceipt::where('company_id', $locked->company_id)->where('user_id', $actor->id)
                ->where('action', self::ACTION)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($receipt !== null) {
                if ((int) $receipt->target_invoice_id !== (int) $locked->id || ! hash_equals($receipt->request_hash, $hash)) {
                    throw new HttpException(409, 'Sale retry differs from its reviewed request.');
                }

                $payment = null;
                if ($paid) {
                    $payments = CustomerPayment::where('company_id', $locked->company_id)->where('customer_id', $locked->customer_id);
                    if ($check) {
                        $instrument = Check::where('company_id', $locked->company_id)->where('idempotency_key', 'fin1-check:'.$suffix)->firstOrFail();
                        app(CheckHistory::class)->validate($instrument);
                        $payment = $payments->where('check_id', $instrument->id)->firstOrFail();
                    } else {
                        $payment = $payments->where('idempotency_key', $paymentKey)->firstOrFail();
                    }
                }

                return ['invoice' => $locked, 'payment' => $payment];
            }
            if (($payload['expires_at'] ?? 0) <= now()->timestamp || ! $locked->isDraft()) {
                throw new HttpException(409, 'Sale confirmation expired or the invoice is no longer a draft.');
            }
            try {
                $intent = $this->intent($locked, $actor, $payload['intent']);
                $terms = $this->paymentTerms($locked, $intent);
            } catch (InvalidArgumentException|ModelNotFoundException $exception) {
                // These instructions were validated when the signed preview was
                // issued. A now-invalid amount/account requires a new review.
                throw new HttpException(409, 'Payment context changed; review the sale again.', $exception);
            }
            if ($terms !== ($payload['payment_terms'] ?? null)) {
                throw new HttpException(409, 'Payment context changed; review the sale again.');
            }
            // The existing invoice token rechecks draft/stock/tax/pricing authority.
            // Both canonical writers and both durable receipts share this outer transaction.
            $posted = $this->invoices->execute($locked, $actor, $payload['invoice_confirmation_token'], 'fin1-invoice:'.$suffix);
            $payment = null;
            if ($paid && $check) {
                $instrument = app(ReceiveCheckAction::class)->execute(Company::findOrFail($locked->company_id), $actor, [
                    'customer_id' => (int) $locked->customer_id, 'date' => $intent['payment_date'],
                    'currency_code' => $intent['currency_code'], 'amount' => $intent['amount'], 'exchange_rate' => $intent['exchange_rate'],
                    'check_number' => $intent['check_number'], 'bank_name' => $intent['bank_name'],
                    'drawer' => $intent['drawer'], 'due_date' => $intent['due_date'], 'notes' => $intent['notes'],
                    'document_locale' => $locked->document_locale, 'idempotency_key' => 'fin1-check:'.$suffix,
                    'allocations' => [['sales_invoice_id' => (int) $locked->id,
                        'allocated_amount' => $terms['invoice_amount'], 'payment_currency_amount' => $terms['payment_currency_amount']]],
                ]);
                $payment = $instrument->customerPayment()->firstOrFail();
            } elseif ($paid) {
                $payment = $this->payments->execute(Company::findOrFail($locked->company_id), $actor, [
                    'customer_id' => (int) $locked->customer_id, 'money_account_id' => $intent['money_account_id'],
                    'payment_method' => $intent['payment_method'], 'payment_date' => $intent['payment_date'],
                    'amount' => $intent['amount'], 'exchange_rate' => $intent['exchange_rate'],
                    'reference_number' => $intent['reference_number'], 'notes' => $intent['notes'],
                    'idempotency_key' => $paymentKey,
                    'allocations' => [['sales_invoice_id' => (int) $locked->id,
                        'allocated_amount' => $terms['invoice_amount'], 'payment_currency_amount' => $terms['payment_currency_amount']]],
                ]);
            }
            CommandReceipt::create([
                'company_id' => $locked->company_id, 'user_id' => $actor->id, 'action' => self::ACTION,
                'idempotency_key' => $key, 'target_invoice_id' => $locked->id, 'sales_invoice_id' => $posted->id,
                'request_hash' => $hash,
            ]);

            return ['invoice' => $posted, 'payment' => $payment];
        });
    }

    private function authorize(SalesInvoice $invoice, User $actor, bool $paid, bool $check = false): void
    {
        Company::whereKey($invoice->company_id)->lockForUpdate()->firstOrFail();
        foreach (['sales.invoice.view', 'sales.invoice.post', ...($paid ? ['money.receipt.create', 'money.receipt.allocate'] : []), ...($check ? ['money.check.incoming.manage'] : [])] as $permission) {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $invoice->company_id, $actor, $permission);
        }
        if (request()->attributes->has('phase11_api_session')) {
            app(CommandSessionGuard::class)->authorize($actor);
        }
    }

    /** @param array<string, mixed> $choice
     * @return array<string, mixed>
     */
    private function intent(SalesInvoice $invoice, User $actor, array $choice): array
    {
        $mode = $choice['mode'] ?? null;
        if (! in_array($mode, ['unpaid', 'full', 'partial'], true)) {
            throw new InvalidArgumentException('Choose unpaid, full or partial payment explicitly.');
        }
        if ($mode === 'unpaid') {
            if (array_diff(array_keys($choice), ['mode']) !== []) {
                throw new InvalidArgumentException('An unpaid sale cannot carry payment instructions.');
            }

            return ['mode' => 'unpaid'];
        }
        $check = ($choice['payment_method'] ?? null) === 'check';
        $fields = ['mode', 'payment_method', 'payment_date', 'amount', 'exchange_rate', 'invoice_amount', 'payment_currency_amount', 'notes'];
        $fields = [...$fields, ...($check ? ['currency_code', 'check_number', 'bank_name', 'drawer', 'due_date'] : ['money_account_id', 'reference_number'])];
        if (array_diff(array_keys($choice), $fields) !== []
            || ! in_array($choice['payment_method'] ?? null, [CustomerPayment::METHOD_CASH, CustomerPayment::METHOD_BANK, 'check'], true)) {
            throw new InvalidArgumentException('Choose a supported receipt method and matching instructions.');
        }
        app(SalesDocumentRules::class)->date($choice['payment_date'] ?? '');

        $instrument = [];
        if ($check) {
            $currency = $choice['currency_code'] ?? null;
            if (! is_string($currency)) {
                throw new InvalidArgumentException('Choose the check currency explicitly.');
            }
            $normalized = app(CheckCreation::class)->intent(Company::findOrFail($invoice->company_id), $actor, [
                ...$choice, 'customer_id' => (int) $invoice->customer_id,
                'check_number' => $choice['check_number'] ?? null, 'bank_name' => $choice['bank_name'] ?? null,
            ], 'incoming');
            if ($normalized['due_date'] < $choice['payment_date']) {
                throw new InvalidArgumentException('Check due date cannot precede its receipt date.');
            }
            $instrument = array_intersect_key($normalized, array_flip(['currency_code', 'check_number', 'bank_name', 'drawer', 'due_date']));
        }

        return [
            'mode' => $mode, ...($check ? $instrument : ['money_account_id' => ReceiptRequestValues::id($choice['money_account_id'] ?? null)]),
            'payment_method' => $choice['payment_method'], 'payment_date' => $choice['payment_date'],
            'amount' => ReceiptRequestValues::decimal($choice['amount'] ?? null, 6),
            'exchange_rate' => ReceiptRequestValues::decimal($choice['exchange_rate'] ?? null, 10),
            'invoice_amount' => $mode === 'full' ? (string) $invoice->grand_total_currency
                : ReceiptRequestValues::decimal($choice['invoice_amount'] ?? null, 6),
            'payment_currency_amount' => isset($choice['payment_currency_amount'])
                ? ReceiptRequestValues::decimal($choice['payment_currency_amount'], 6) : null,
            ...($check ? [] : ['reference_number' => MoneyValues::text($choice['reference_number'] ?? null, 255)]),
            'notes' => MoneyValues::text($choice['notes'] ?? null),
        ];
    }

    /** Read-only, exact preview. Canonical receipt validation runs again before commit.
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function paymentTerms(SalesInvoice $invoice, array $intent): array
    {
        $total = BigDecimal::of((string) $invoice->grand_total_currency)->toScale(6);
        if ($intent['mode'] === 'unpaid') {
            return ['mode' => 'unpaid', 'outstanding_invoice_amount' => (string) $total];
        }
        $account = null;
        if ($intent['payment_method'] === 'check') {
            $instrument = new Check;
            $instrument->forceFill(['company_id' => $invoice->company_id, 'direction' => 'incoming']);
            $ledger = app(CheckPaymentSource::class)->ledger($instrument);
            $currency = $intent['currency_code'];
        } else {
            $account = MoneyAccount::where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($intent['money_account_id']);
            $expectedType = $intent['payment_method'] === CustomerPayment::METHOD_CASH ? MoneyAccount::TYPE_CASH : MoneyAccount::TYPE_BANK;
            if (! $account->is_active || $account->account_type !== $expectedType) {
                throw new InvalidArgumentException('Choose an active matching company payment account.');
            }
            $ledger = app(MoneyAccountLedger::class)->validate($account, true);
            $currency = $account->currency_code;
        }
        $company = Company::findOrFail($invoice->company_id);
        $amount = MoneyValues::amount($intent['amount'], $currency);
        $allocated = MoneyValues::amount($intent['invoice_amount'], $invoice->currency_code);
        $rate = MoneyValues::rate($intent['exchange_rate'], $currency, $company->base_currency_code);
        app(SalesDocumentRules::class)->header($company, $currency, $rate, $intent['payment_date'], $invoice->document_locale);
        $sameCurrency = $currency === $invoice->currency_code;
        if (! $sameCurrency && $intent['payment_currency_amount'] === null) {
            throw new InvalidArgumentException('Review both currency amounts for cross-currency settlement.');
        }
        $consumed = MoneyValues::amount($intent['payment_currency_amount'] ?? (string) $allocated, $currency);
        if (($sameCurrency && ! $consumed->isEqualTo($allocated)) || $allocated->isGreaterThan($total)
            || ($intent['mode'] === 'full' && ! $allocated->isEqualTo($total))
            || ($intent['mode'] === 'partial' && ! $allocated->isLessThan($total)) || $consumed->isGreaterThan($amount)) {
            throw new InvalidArgumentException('Reviewed payment and allocation amounts do not match the chosen sale mode.');
        }

        return ['mode' => $intent['mode'], 'money_account_id' => $account === null ? null : (int) $account->id, 'payment_method' => $intent['payment_method'],
            'payment_date' => $intent['payment_date'],
            'account_name_ar' => $account?->name_ar, 'account_name_en' => $account?->name_en,
            ...($intent['payment_method'] === 'check' ? array_intersect_key($intent, array_flip(['check_number', 'bank_name', 'drawer', 'due_date'])) : []),
            'payment_currency' => $currency, 'invoice_currency' => $invoice->currency_code,
            'received_amount' => (string) $amount, 'exchange_rate' => (string) $rate,
            'invoice_amount' => (string) $allocated, 'payment_currency_amount' => (string) $consumed,
            'outstanding_invoice_amount' => (string) $total->minus($allocated),
            'unallocated_payment_amount' => (string) $amount->minus($consumed),
            'account_revision' => hash('sha256', json_encode([$account?->only(['name_ar', 'name_en', 'currency_code', 'account_type', 'is_active', 'ledger_account_id']), $ledger->only(['id', 'code', 'active', 'is_control', 'parent_id', 'account_type', 'normal_balance'])], JSON_THROW_ON_ERROR))];
    }
}
