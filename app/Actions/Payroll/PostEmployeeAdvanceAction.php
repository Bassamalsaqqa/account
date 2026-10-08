<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\DocumentSequence;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Money\CheckPaymentSource;
use App\Services\Money\MoneyAccountLedger;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyEventScope;
use App\Services\Money\MoneyValues;
use App\Services\Phase7\OwnsPhase7Event;
use App\Services\Phase7\Phase7EventOwner;
use App\Services\Phase7\Phase7History;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Sales\ReceiptRequestValues;
use App\Services\Sales\SalesDocumentRules;
use App\Services\Sales\SalesPostingLines;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PostEmployeeAdvanceAction implements Phase7EventOwner
{
    use OwnsPhase7Event;

    public function __construct(
        private readonly DocumentSequenceService $sequenceService,
        private readonly MoneyAccountLedger $moneyAccountLedger,
        private readonly CheckPaymentSource $checkPaymentSource,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Company $company, User $actor, array $data): EmployeeAdvance
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $company->id) {
            throw new NoActiveCompanyException("Active company context does not match company [{$company->id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $actor->id) {
            throw new AuthorizationException('Actor does not match authenticated user.');
        }

        if (! $actor->belongsToCompany($company->id)) {
            throw new AuthorizationException("User does not belong to company [{$company->id}].");
        }

        setPermissionsTeamId($company->id);
        if (! $actor->hasPermissionTo('payroll.advance.manage')) {
            throw new AuthorizationException('User does not have permission to manage employee advances.');
        }

        $idempotencyKey = ReceiptRequestValues::key($data['idempotency_key'] ?? null);
        $advanceDate = (string) ($data['advance_date'] ?? '');
        app(SalesDocumentRules::class)->date($advanceDate);

        $employeeId = ReceiptRequestValues::id($data['employee_id'] ?? null);
        $currencyCode = (string) ($data['currency_code'] ?? '');
        $paymentMethod = (string) ($data['payment_method'] ?? '');
        if (! in_array($paymentMethod, ['cash', 'bank', 'check'], true)) {
            throw new InvalidArgumentException("Payment method [{$paymentMethod}] is not supported. Use cash, bank, or check.");
        }

        $moneyAccountId = null;
        $checkId = null;
        if ($paymentMethod === 'check') {
            $checkId = ReceiptRequestValues::id($data['check_id'] ?? null);
        } else {
            $moneyAccountId = ReceiptRequestValues::id($data['money_account_id'] ?? null);
        }

        $amount = MoneyValues::amount($data['amount'] ?? '0', $currencyCode);
        $exchangeRate = MoneyValues::rate($data['exchange_rate'] ?? '1', $currencyCode, $company->base_currency_code);
        $baseAmount = MoneyValues::base($amount, $exchangeRate);
        $notes = MoneyValues::text($data['notes'] ?? null, 2000);

        $canonicalPayload = [
            'company_id' => (int) $company->id,
            'actor_id' => (int) $actor->id,
            'employee_id' => $employeeId,
            'currency_code' => $currencyCode,
            'amount' => (string) $amount,
            'exchange_rate' => (string) $exchangeRate,
            'base_amount' => (string) $baseAmount,
            'payment_method' => $paymentMethod,
            'money_account_id' => $moneyAccountId,
            'check_id' => $checkId,
            'advance_date' => $advanceDate,
            'notes' => $notes,
        ];
        $requestHash = hash('sha256', json_encode($canonicalPayload, JSON_THROW_ON_ERROR));

        return $this->canonicalTransaction((int) $company->id, $actor, function () use (
            $company,
            $actor,
            $idempotencyKey,
            $requestHash,
            $employeeId,
            $currencyCode,
            $amount,
            $exchangeRate,
            $baseAmount,
            $paymentMethod,
            $moneyAccountId,
            $checkId,
            $advanceDate,
            $notes
        ): EmployeeAdvance {
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            app(MoneyActorGuard::class)->authorize((int) $lockedCompany->id, 'payroll.advance.manage');

            // Idempotency check
            $existing = EmployeeAdvance::where('company_id', $lockedCompany->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->request_hash !== $requestHash) {
                    throw new IdempotencyConflictException("Idempotency key [{$idempotencyKey}] was already used with different employee advance parameters.");
                }

                app(Phase7History::class)->validate($existing);

                return $existing->load(['employee', 'moneyAccount', 'postingBatch']);
            }

            if (! CompanyCurrency::where('company_id', $lockedCompany->id)->where('currency_code', $currencyCode)->where('enabled', true)->exists()) {
                throw new InvalidArgumentException("Advance currency [{$currencyCode}] is not enabled for this company.");
            }

            $employee = Employee::where('company_id', $lockedCompany->id)->lockForUpdate()->findOrFail($employeeId);
            if (! $employee->active) {
                throw new InvalidArgumentException("Employee [{$employee->code}] is inactive.");
            }

            $check = null;
            $cashLedgerAccount = null;
            if ($paymentMethod === 'check') {
                $check = $this->checkPaymentSource->instrumentForAdvance(
                    (int) $lockedCompany->id,
                    $actor,
                    ['check_id' => $checkId, 'advance_date' => $advanceDate, 'currency_code' => $currencyCode, 'amount' => (string) $amount, 'exchange_rate' => (string) $exchangeRate]
                );
                $cashLedgerAccount = $this->checkPaymentSource->ledger($check);
            } else {
                $moneyAccount = MoneyAccount::where('company_id', $lockedCompany->id)->lockForUpdate()->findOrFail($moneyAccountId);
                $cashLedgerAccount = $this->moneyAccountLedger->validate($moneyAccount, true);
                if ($moneyAccount->currency_code !== $currencyCode) {
                    throw new InvalidArgumentException("Money account currency [{$moneyAccount->currency_code}] does not match advance currency [{$currencyCode}].");
                }
            }

            // Document sequence
            $year = (int) Carbon::parse($advanceDate)->format('Y');
            $advanceNumber = $this->sequenceService->generateNextNumber((int) $lockedCompany->id, DocumentSequence::TYPE_EMPLOYEE_ADVANCE, $year);

            // Employee advances ledger account (System key: employee_advances)
            $advanceLedger = LedgerAccount::where('company_id', $lockedCompany->id)->where('system_key', 'employee_advances')->firstOrFail();

            $employeeSnapshotData = [
                'employee_id' => (int) $employee->id,
                'code' => $employee->code,
                'name' => $employee->name,
                'job_title' => $employee->job_title,
                'phone' => $employee->phone,
            ];

            // Build double-entry posting command
            $lines = [];
            $zero = MoneyAmount::from('0');

            // Line 1: Debit Employee Advances (Asset)
            app(SalesPostingLines::class)->append(
                $lines,
                1,
                (int) $advanceLedger->id,
                MoneyAmount::from($baseAmount),
                $zero,
                $currencyCode,
                MoneyAmount::from($amount),
                ExchangeRate::from($exchangeRate),
                "Advance {$advanceNumber} - {$employee->name}"
            );

            // Line 2: Credit Cash/Bank or Checks Issued
            app(SalesPostingLines::class)->append(
                $lines,
                2,
                (int) $cashLedgerAccount->id,
                $zero,
                MoneyAmount::from($baseAmount),
                $currencyCode,
                MoneyAmount::from($amount),
                ExchangeRate::from($exchangeRate),
                "Advance {$advanceNumber} payment"
            );

            // Reserve provisional ID
            $provisionalPublicId = (string) Str::ulid();

            $advance = new EmployeeAdvance([
                'public_id' => $provisionalPublicId,
                'company_id' => (int) $lockedCompany->id,
                'advance_number' => $advanceNumber,
                'employee_id' => (int) $employee->id,
                'employee_snapshot' => $employeeSnapshotData,
                'advance_date' => $advanceDate,
                'currency_code' => $currencyCode,
                'amount' => (string) $amount,
                'exchange_rate' => (string) $exchangeRate,
                'base_amount' => (string) $baseAmount,
                'payment_method' => $paymentMethod,
                'money_account_id' => $moneyAccountId,
                'check_id' => $check?->id,
                'notes' => $notes,
                'posting_batch_id' => null,
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => (int) $actor->id,
                'request_hash' => $requestHash,
                'idempotency_key' => $idempotencyKey,
                'created_by' => (int) $actor->id,
            ]);
            $this->persistPhase7($advance);

            $postingCommand = new PostingCommand(
                $lockedCompany,
                Carbon::parse($advanceDate),
                'employee_advance',
                (int) $advance->id,
                $currencyCode,
                $lockedCompany->base_currency_code,
                ExchangeRate::from($exchangeRate),
                "employee_advance:{$advance->public_id}",
                $actor,
                "Employee Advance {$advanceNumber}",
                lines: $lines
            );

            if ($paymentMethod === 'check') {
                app(MoneyEventScope::class)->prepareCheckPaymentCommand($postingCommand, (int) $check->id, 'outgoing');
            }

            /** @var PostingBatch $batch */
            $batch = $this->postPhase7($postingCommand);

            $advance->posting_batch_id = (int) $batch->id;
            $this->persistPhase7($advance);

            app(AuditService::class)->log(
                (int) $lockedCompany->id,
                'employee_advance.posted',
                "Posted employee advance {$advanceNumber}",
                (int) $actor->id,
                $advance,
                meta: [
                    'advance_id' => $advance->id,
                    'advance_number' => $advanceNumber,
                    'amount' => (string) $amount,
                    'currency' => $currencyCode,
                    'payment_method' => $paymentMethod,
                ]
            );

            app(Phase7History::class)->validate($advance);

            return $advance->load(['employee', 'moneyAccount', 'postingBatch']);
        });
    }
}
