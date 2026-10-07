<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryPaymentAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\Customer;
use App\Models\Employee;
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
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function intent(Company $company, User $actor, array $data, string $direction): array
    {
        $date = (string) ($data['date'] ?? $data['payment_date'] ?? $data['expense_date'] ?? $data['advance_date'] ?? '');
        $due = (string) ($data['due_date'] ?? $date);
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

        $sourceType = $direction === 'incoming'
            ? 'customer_payment'
            : (string) ($data['source_type'] ?? 'vendor_payment');

        if (in_array($sourceType, ['customer_payment', 'vendor_payment'], true)) {
            return [
                'company_id' => (int) $company->id, 'actor_id' => (int) $actor->id, 'direction' => $direction,
                'party_id' => ReceiptRequestValues::id($data['party_id'] ?? ($sourceType === 'customer_payment' ? ($data['customer_id'] ?? null) : ($data['vendor_id'] ?? null))),
                'date' => $date, 'due_date' => $due, 'currency_code' => $currency, 'amount' => (string) $amount, 'exchange_rate' => (string) $rate,
                'check_number' => $number, 'bank_name' => $bankName, 'drawer' => MoneyValues::text($data['drawer'] ?? null, 200),
                'drawn_money_account_id' => $direction === 'outgoing' ? ReceiptRequestValues::id($data['money_account_id'] ?? $data['drawn_money_account_id'] ?? null) : null,
                'notes' => MoneyValues::text($data['notes'] ?? null),
                'allocations' => PaymentAllocationIntent::normalize($data['allocations'] ?? [], $direction === 'incoming' ? 'sales_invoice_id' : 'purchase_id'),
                'document_locale' => $data['document_locale'] ?? null,
            ];
        }

        $intent = [
            'company_id' => (int) $company->id,
            'actor_id' => (int) $actor->id,
            'direction' => $direction,
            'source_type' => $sourceType,
            'date' => $date,
            'due_date' => $due,
            'currency_code' => $currency,
            'amount' => (string) $amount,
            'exchange_rate' => (string) $rate,
            'check_number' => $number,
            'bank_name' => $bankName,
            'drawer' => MoneyValues::text($data['drawer'] ?? null, 200),
            'drawn_money_account_id' => $direction === 'outgoing' ? ReceiptRequestValues::id($data['money_account_id'] ?? $data['drawn_money_account_id'] ?? null) : null,
            'notes' => MoneyValues::text($data['notes'] ?? null),
        ];

        if ($sourceType === 'customer_payment' || $sourceType === 'vendor_payment') {
            $intent['party_id'] = ReceiptRequestValues::id($data['party_id'] ?? ($sourceType === 'customer_payment' ? ($data['customer_id'] ?? null) : ($data['vendor_id'] ?? null)));
            $intent['allocations'] = PaymentAllocationIntent::normalize($data['allocations'] ?? [], $direction === 'incoming' ? 'sales_invoice_id' : 'purchase_id');
            $intent['document_locale'] = $data['document_locale'] ?? null;
        } elseif ($sourceType === 'expense') {
            $intent['expense_data'] = [
                'category_id' => ReceiptRequestValues::id($data['category_id'] ?? null),
                'vendor_id' => ! empty($data['vendor_id']) ? ReceiptRequestValues::id($data['vendor_id']) : null,
                'payee_name' => MoneyValues::text($data['payee_name'] ?? null, 255),
                'classification' => (string) ($data['classification'] ?? 'operating'),
                'description' => MoneyValues::text($data['description'] ?? 'Expense check payment'),
                'notes' => MoneyValues::text($data['notes'] ?? null, 2000),
                'attachment_path' => isset($data['attachment_path']) ? (string) $data['attachment_path'] : null,
                'attachment_name' => isset($data['attachment_name']) ? (string) $data['attachment_name'] : null,
                'attachment_mime' => isset($data['attachment_mime']) ? (string) $data['attachment_mime'] : null,
                'attachment_size' => isset($data['attachment_size']) ? (int) $data['attachment_size'] : null,
            ];
            $intent['party_id'] = $intent['expense_data']['vendor_id'];
        } elseif ($sourceType === 'employee_advance') {
            $intent['employee_id'] = ReceiptRequestValues::id($data['employee_id'] ?? $data['party_id'] ?? null);
            $intent['notes'] = MoneyValues::text($data['notes'] ?? null, 2000);
        } elseif ($sourceType === 'salary_payment') {
            $intent['employee_id'] = ReceiptRequestValues::id($data['employee_id'] ?? $data['party_id'] ?? null);
            $allocations = [];
            $seen = [];
            foreach ($data['allocations'] ?? [] as $row) {
                $id = ReceiptRequestValues::id($row['salary_entry_id'] ?? null);
                if (isset($seen[$id])) {
                    throw new InvalidArgumentException('Duplicate Salary allocation.');
                }
                $seen[$id] = true;
                $allocations[] = ['salary_entry_id' => $id, 'allocated_amount' => (string) MoneyValues::amount($row['allocated_amount'] ?? null, $currency)];
            }
            usort($allocations, fn ($a, $b) => $a['salary_entry_id'] <=> $b['salary_entry_id']);
            $intent['allocations'] = $allocations;
            $intent['notes'] = MoneyValues::text($data['notes'] ?? null, 2000);
        }

        return $intent;
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
        $sourceType = (string) ($intent['source_type'] ?? ($incoming ? 'customer_payment' : 'vendor_payment'));

        $bank = null;
        if (! $incoming) {
            $bank = MoneyAccount::where('company_id', $company->id)->lockForUpdate()->findOrFail($intent['drawn_money_account_id']);
            app(MoneyAccountLedger::class)->validate($bank, true);
            if ($bank->account_type !== 'bank' || $bank->currency_code !== $intent['currency_code']) {
                throw new InvalidArgumentException('Outgoing Check requires an active Bank in its currency.');
            }
        }

        $customerId = null;
        $vendorId = null;
        $partySnapshot = null;

        if ($sourceType === 'customer_payment') {
            $party = Customer::withTrashed()->where('company_id', $company->id)->lockForUpdate()->findOrFail($intent['party_id']);
            $customerId = (int) $party->id;
            $partySnapshot = $party->only(['name_ar', 'name_en', 'business_name_ar', 'business_name_en', 'phone', 'email']);
        } elseif ($sourceType === 'vendor_payment') {
            $party = Vendor::withTrashed()->where('company_id', $company->id)->lockForUpdate()->findOrFail($intent['party_id']);
            $vendorId = (int) $party->id;
            $partySnapshot = app(PurchaseIdentitySnapshot::class)->vendor($party);
        } elseif ($sourceType === 'expense') {
            if ($intent['expense_data']['vendor_id'] !== null) {
                $vendor = Vendor::withTrashed()->where('company_id', $company->id)->lockForUpdate()->findOrFail($intent['expense_data']['vendor_id']);
                $vendorId = (int) $vendor->id;
                $partySnapshot = app(PurchaseIdentitySnapshot::class)->vendor($vendor);
            } elseif (! empty($intent['expense_data']['payee_name'])) {
                $partySnapshot = ['payee_name' => $intent['expense_data']['payee_name']];
            } else {
                $partySnapshot = ['payee_name' => $intent['expense_data']['description'] ?? 'مصروف'];
            }
        } elseif ($sourceType === 'employee_advance' || $sourceType === 'salary_payment') {
            $employee = Employee::where('company_id', $company->id)->lockForUpdate()->findOrFail($intent['employee_id']);
            $partySnapshot = [
                'employee_id' => (int) $employee->id,
                'code' => $employee->code,
                'name' => $employee->name,
                'job_title' => $employee->job_title,
                'phone' => $employee->phone,
            ];
        }

        $status = $incoming ? 'received' : 'issued';
        $values = [
            'public_id' => (string) Str::ulid(),
            'company_id' => (int) $company->id,
            'direction' => $intent['direction'],
            'check_number' => $intent['check_number'],
            'customer_id' => $customerId,
            'vendor_id' => $vendorId,
            'drawn_money_account_id' => $bank?->id,
            'bank_name' => $intent['bank_name'],
            'drawer' => $intent['drawer'],
            'currency_code' => $intent['currency_code'],
            'base_currency_code' => $company->base_currency_code,
            'amount' => $intent['amount'],
            'exchange_rate' => $intent['exchange_rate'],
            'amount_base' => (string) MoneyValues::base(MoneyValues::amount($intent['amount'], $intent['currency_code']), MoneyValues::rate($intent['exchange_rate'], $intent['currency_code'], $company->base_currency_code)),
            'received_issued_date' => $intent['date'],
            'due_date' => $intent['due_date'],
            'status' => $status,
            'party_snapshot' => $partySnapshot,
            'bank_snapshot' => $bank?->only(['name_ar', 'name_en']),
            'notes' => $intent['notes'],
            'idempotency_key' => $key,
            'request_hash' => hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR)),
            'request_payload' => $intent,
            'created_by' => (int) $actor->id,
        ];

        $scope = app(MoneyEventScope::class);
        $scope->prepareRecord($capability, Check::class.':new', $values);
        $check = Check::record($capability, $values);
        $scope->prepareCheck($capability, $check);

        // Record the underlying source financial record
        if ($sourceType === 'customer_payment') {
            $paymentData = ['check_id' => $check->id, 'customer_id' => $customerId, 'money_account_id' => null, 'payment_method' => 'check', 'payment_date' => $intent['date'],
                'amount' => $intent['amount'], 'exchange_rate' => $intent['exchange_rate'], 'notes' => $intent['notes'], 'reference_number' => $intent['check_number'],
                'document_locale' => $intent['document_locale'], 'idempotency_key' => 'check-payment:'.$check->public_id, 'allocations' => $intent['allocations']];
            app(PostCustomerPaymentAction::class)->execute($company, $actor, $paymentData);
        } elseif ($sourceType === 'vendor_payment') {
            $paymentData = ['check_id' => $check->id, 'vendor_id' => $vendorId, 'money_account_id' => null, 'payment_method' => 'check', 'payment_date' => $intent['date'],
                'amount' => $intent['amount'], 'exchange_rate' => $intent['exchange_rate'], 'notes' => $intent['notes'], 'reference_number' => $intent['check_number'],
                'document_locale' => $intent['document_locale'], 'idempotency_key' => 'check-payment:'.$check->public_id, 'allocations' => $intent['allocations']];
            app(PostVendorPaymentAction::class)->execute($company, $actor, $paymentData);
        } elseif ($sourceType === 'expense') {
            $expenseData = array_merge($intent['expense_data'], [
                'expense_date' => $intent['date'],
                'currency_code' => $intent['currency_code'],
                'amount' => $intent['amount'],
                'exchange_rate' => $intent['exchange_rate'],
                'payment_method' => 'check',
                'money_account_id' => null,
                'check_id' => $check->id,
                'idempotency_key' => 'check-expense:'.$check->public_id,
            ]);
            app(PostExpenseAction::class)->execute($company, $actor, $expenseData);
        } elseif ($sourceType === 'employee_advance') {
            $advanceData = [
                'employee_id' => $intent['employee_id'],
                'advance_date' => $intent['date'],
                'currency_code' => $intent['currency_code'],
                'amount' => $intent['amount'],
                'exchange_rate' => $intent['exchange_rate'],
                'payment_method' => 'check',
                'money_account_id' => null,
                'check_id' => $check->id,
                'notes' => $intent['notes'],
                'idempotency_key' => 'check-advance:'.$check->public_id,
            ];
            app(PostEmployeeAdvanceAction::class)->execute($company, $actor, $advanceData);
        } elseif ($sourceType === 'salary_payment') {
            $salaryPaymentData = [
                'employee_id' => $intent['employee_id'],
                'payment_date' => $intent['date'],
                'currency_code' => $intent['currency_code'],
                'amount' => $intent['amount'],
                'exchange_rate' => $intent['exchange_rate'],
                'payment_method' => 'check',
                'money_account_id' => null,
                'check_id' => $check->id,
                'allocations' => $intent['allocations'],
                'notes' => $intent['notes'],
                'idempotency_key' => 'check-salary-payment:'.$check->public_id,
            ];
            app(PostSalaryPaymentAction::class)->execute($company, $actor, $salaryPaymentData);
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
