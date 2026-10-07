<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\Customer;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Audit\AuditService;
use App\Services\Purchasing\PurchaseIdentitySnapshot;
use App\Services\Sales\ReceiptRequestValues;
use App\Services\Sales\SalesDocumentRules;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Shared preparation only; concrete receive/issue actions own the outer transaction/capability. */
final class CheckCreation
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function intent(Company $company, User $actor, array $data, string $direction): array
    {
        $date = (string) $data['date'];
        $due = (string) $data['due_date'];
        app(SalesDocumentRules::class)->date($date);
        app(SalesDocumentRules::class)->date($due);
        $currency = (string) $data['currency_code'];
        $amount = MoneyValues::amount($data['amount'], $currency);
        $rate = MoneyValues::rate($data['exchange_rate'], $currency, $company->base_currency_code);
        $number = MoneyValues::text($data['check_number'], 100);
        $bankName = MoneyValues::text($data['bank_name'], 200);
        if ($number === null || $bankName === null) {
            throw new InvalidArgumentException('Check number and bank name are required.');
        }

        return [
            'company_id' => (int) $company->id, 'actor_id' => (int) $actor->id, 'direction' => $direction,
            'party_id' => ReceiptRequestValues::id($data['party_id']), 'date' => $date, 'due_date' => $due,
            'currency_code' => $currency, 'amount' => (string) $amount, 'exchange_rate' => (string) $rate,
            'check_number' => $number, 'bank_name' => $bankName, 'drawer' => MoneyValues::text($data['drawer'] ?? null, 200),
            'drawn_money_account_id' => $direction === 'outgoing' ? ReceiptRequestValues::id($data['money_account_id']) : null,
            'notes' => MoneyValues::text($data['notes'] ?? null),
            'allocations' => PaymentAllocationIntent::normalize($data['allocations'] ?? [], $direction === 'incoming' ? 'sales_invoice_id' : 'purchase_id'),
            'document_locale' => $data['document_locale'] ?? null,
        ];
    }

    /** @param array<string, mixed> $intent */
    public function existing(array $intent, string $key): ?Check
    {
        $check = Check::where('company_id', $intent['company_id'])->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($check !== null) {
            if ($check->request_hash !== hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR))) {
                throw new IdempotencyConflictException('Check key already has different business intent.');
            }
            app(CheckHistory::class)->validate($check);
        }

        return $check;
    }

    /** @param array<string, mixed> $intent */
    public function record(Company $company, User $actor, array $intent, string $key, MoneyEventCapability $capability): Check
    {
        if (! CompanyCurrency::where('company_id', $company->id)->where('currency_code', $intent['currency_code'])->where('enabled', true)->exists()) {
            throw new InvalidArgumentException('Check currency must be enabled.');
        }
        $incoming = $intent['direction'] === 'incoming';
        $party = $incoming ? Customer::withTrashed()->where('company_id', $company->id)->lockForUpdate()->findOrFail($intent['party_id'])
            : Vendor::withTrashed()->where('company_id', $company->id)->lockForUpdate()->findOrFail($intent['party_id']);
        $bank = null;
        if (! $incoming) {
            $bank = MoneyAccount::where('company_id', $company->id)->lockForUpdate()->findOrFail($intent['drawn_money_account_id']);
            app(MoneyAccountLedger::class)->validate($bank, true);
            if ($bank->account_type !== 'bank' || $bank->currency_code !== $intent['currency_code']) {
                throw new InvalidArgumentException('Outgoing Check requires an active Bank in its currency.');
            }
        }
        $status = $incoming ? 'received' : 'issued';
        $values = [
            'public_id' => (string) Str::ulid(), 'company_id' => (int) $company->id, 'direction' => $intent['direction'],
            'check_number' => $intent['check_number'], 'customer_id' => $incoming ? $party->id : null, 'vendor_id' => $incoming ? null : $party->id,
            'drawn_money_account_id' => $bank?->id, 'bank_name' => $intent['bank_name'], 'drawer' => $intent['drawer'],
            'currency_code' => $intent['currency_code'], 'base_currency_code' => $company->base_currency_code, 'amount' => $intent['amount'],
            'exchange_rate' => $intent['exchange_rate'], 'amount_base' => (string) MoneyValues::base(MoneyValues::amount($intent['amount'], $intent['currency_code']), MoneyValues::rate($intent['exchange_rate'], $intent['currency_code'], $company->base_currency_code)),
            'received_issued_date' => $intent['date'], 'due_date' => $intent['due_date'], 'status' => $status,
            'party_snapshot' => $incoming ? $party->only(['name_ar', 'name_en', 'business_name_ar', 'business_name_en', 'phone', 'email']) : app(PurchaseIdentitySnapshot::class)->vendor($party),
            'bank_snapshot' => $bank?->only(['name_ar', 'name_en']), 'notes' => $intent['notes'],
            'idempotency_key' => $key, 'request_hash' => hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR)), 'request_payload' => $intent, 'created_by' => (int) $actor->id,
        ];
        $scope = app(MoneyEventScope::class);
        $scope->prepareRecord($capability, Check::class.':new', $values);
        $check = Check::record($capability, $values);
        $scope->prepareCheck($capability, $check);
        $paymentData = ['check_id' => $check->id, 'money_account_id' => null, 'payment_method' => 'check', 'payment_date' => $intent['date'],
            'amount' => $intent['amount'], 'exchange_rate' => $intent['exchange_rate'], 'notes' => $intent['notes'], 'reference_number' => $intent['check_number'],
            'document_locale' => $intent['document_locale'], 'idempotency_key' => 'check-payment:'.$check->public_id, 'allocations' => $intent['allocations']];
        if ($incoming) {
            app(PostCustomerPaymentAction::class)->execute($company, $actor, $paymentData + ['customer_id' => $party->id]);
        } else {
            app(PostVendorPaymentAction::class)->execute($company, $actor, $paymentData + ['vendor_id' => $party->id]);
        }
        $eventValues = ['public_id' => (string) Str::ulid(), 'company_id' => (int) $company->id, 'check_id' => (int) $check->id,
            'event_type' => $status, 'from_status' => null, 'to_status' => $status, 'event_date' => $intent['date'],
            'idempotency_key' => 'check-initial:'.$check->public_id, 'request_hash' => $check->request_hash, 'request_payload' => $intent, 'actor_id' => (int) $actor->id, 'completed_at' => now()];
        $scope->prepareRecord($capability, CheckEvent::class.':new', $eventValues);
        CheckEvent::record($capability, $eventValues);
        app(CheckHistory::class)->validate($check);
        app(AuditService::class)->log((int) $company->id, 'check.'.$status, 'Check recorded', (int) $actor->id, $check,
            meta: ['check_id' => (int) $check->id, 'lifecycle_action' => $status]);

        return $check;
    }
}
