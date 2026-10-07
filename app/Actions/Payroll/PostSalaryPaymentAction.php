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
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\SalaryEntry;
use App\Models\SalaryPayment;
use App\Models\SalaryPaymentAllocation;
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
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PostSalaryPaymentAction implements Phase7EventOwner
{
    use OwnsPhase7Event;

    public function __construct(
        private readonly DocumentSequenceService $sequenceService,
        private readonly MoneyAccountLedger $moneyAccountLedger,
        private readonly CheckPaymentSource $checkPaymentSource,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Company $company, User $actor, array $data): SalaryPayment
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
        if (! $actor->hasPermissionTo('payroll.salary.pay')) {
            throw new AuthorizationException('User does not have permission to record salary payments.');
        }

        $idempotencyKey = ReceiptRequestValues::key($data['idempotency_key'] ?? null);
        $paymentDate = (string) ($data['payment_date'] ?? '');
        app(SalesDocumentRules::class)->date($paymentDate);

        $employeeId = ReceiptRequestValues::id($data['employee_id'] ?? null);
        $currencyCode = (string) ($data['currency_code'] ?? '');
        $exchangeRate = MoneyValues::rate($data['exchange_rate'] ?? '1', $currencyCode, $company->base_currency_code);
        $amount = MoneyValues::amount($data['amount'] ?? '0', $currencyCode);

        if ($amount->isNegative() || $amount->isZero()) {
            throw new InvalidArgumentException('Salary payment amount must be positive.');
        }

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

        $notes = MoneyValues::text($data['notes'] ?? null, 2000);
        $allocationsInput = $data['allocations'] ?? [];
        if ($allocationsInput === []) {
            throw new InvalidArgumentException('Salary payment requires at least one salary entry allocation.');
        }

        $normalizedAllocations = [];
        $sumAllocated = BigDecimal::zero();
        foreach ($allocationsInput as $alloc) {
            $entryId = ReceiptRequestValues::id($alloc['salary_entry_id'] ?? null);
            $allocAmount = MoneyValues::amount($alloc['allocated_amount'] ?? '0', $currencyCode);
            if ($allocAmount->isNegative() || $allocAmount->isZero()) {
                throw new InvalidArgumentException('Allocation amount must be positive.');
            }
            $normalizedAllocations[] = ['salary_entry_id' => $entryId, 'allocated_amount' => (string) $allocAmount];
            $sumAllocated = $sumAllocated->plus($allocAmount);
        }
        $targetIds = array_column($normalizedAllocations, 'salary_entry_id');
        if (count($targetIds) !== count(array_unique($targetIds))) {
            throw new InvalidArgumentException('Duplicate allocation targets are not permitted.');
        }
        usort($normalizedAllocations, fn ($a, $b) => $a['salary_entry_id'] <=> $b['salary_entry_id']);

        if (! $sumAllocated->isEqualTo($amount)) {
            throw new InvalidArgumentException("Sum of salary allocations [{$sumAllocated}] must equal payment amount [{$amount}].");
        }

        $totalSettlementBase = MoneyValues::base($amount, $exchangeRate);

        $canonicalPayload = [
            'company_id' => (int) $company->id,
            'actor_id' => (int) $actor->id,
            'employee_id' => $employeeId,
            'payment_date' => $paymentDate,
            'currency_code' => $currencyCode,
            'exchange_rate' => (string) $exchangeRate,
            'amount' => (string) $amount,
            'base_amount' => (string) $totalSettlementBase,
            'payment_method' => $paymentMethod,
            'money_account_id' => $moneyAccountId,
            'check_id' => $checkId,
            'allocations' => $normalizedAllocations,
            'notes' => $notes,
        ];
        $requestHash = hash('sha256', json_encode($canonicalPayload, JSON_THROW_ON_ERROR));

        return $this->canonicalTransaction((int) $company->id, $actor, function () use (
            $company,
            $actor,
            $idempotencyKey,
            $requestHash,
            $employeeId,
            $paymentDate,
            $currencyCode,
            $exchangeRate,
            $amount,
            $totalSettlementBase,
            $paymentMethod,
            $moneyAccountId,
            $checkId,
            $normalizedAllocations,
            $notes
        ): SalaryPayment {
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            app(MoneyActorGuard::class)->authorize((int) $lockedCompany->id, 'payroll.salary.pay');

            // 1. Idempotency check
            $existing = SalaryPayment::where('company_id', $lockedCompany->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->request_hash !== $requestHash) {
                    throw new IdempotencyConflictException("Idempotency key [{$idempotencyKey}] was already used with different salary payment parameters.");
                }

                app(Phase7History::class)->validate($existing);

                return $existing->load(['employee', 'allocations', 'postingBatch']);
            }

            if (! CompanyCurrency::where('company_id', $lockedCompany->id)->where('currency_code', $currencyCode)->where('enabled', true)->exists()) {
                throw new InvalidArgumentException("Payment currency [{$currencyCode}] is not enabled in this company.");
            }

            $employee = Employee::where('company_id', $lockedCompany->id)->lockForUpdate()->findOrFail($employeeId);
            if (! $employee->active) {
                throw new InvalidArgumentException("Employee [{$employee->code}] is inactive.");
            }

            $check = null;
            $cashLedgerAccount = null;
            if ($paymentMethod === 'check') {
                $check = $this->checkPaymentSource->instrumentForSalaryPayment(
                    (int) $lockedCompany->id,
                    $actor,
                    ['check_id' => $checkId, 'payment_date' => $paymentDate, 'currency_code' => $currencyCode, 'amount' => (string) $amount, 'exchange_rate' => (string) $exchangeRate]
                );
                $cashLedgerAccount = $this->checkPaymentSource->ledger($check);
            } else {
                $moneyAccount = MoneyAccount::where('company_id', $lockedCompany->id)->lockForUpdate()->findOrFail($moneyAccountId);
                $cashLedgerAccount = $this->moneyAccountLedger->validate($moneyAccount, true);
                if ($moneyAccount->currency_code !== $currencyCode) {
                    throw new InvalidArgumentException("Money account currency [{$moneyAccount->currency_code}] does not match payment currency [{$currencyCode}].");
                }
            }

            // 2. Validate allocations against salary entries
            $allocationsData = [];
            $totalSalaryBookReliefBase = BigDecimal::zero();
            $remainingSettlementBase = $totalSettlementBase;

            $totalCount = count($normalizedAllocations);
            foreach ($normalizedAllocations as $index => $item) {
                $isLast = ($index === $totalCount - 1);
                $entryId = $item['salary_entry_id'];
                $allocAmount = BigDecimal::of($item['allocated_amount']);

                /** @var SalaryEntry $entry */
                $entry = SalaryEntry::where('company_id', $lockedCompany->id)
                    ->where('id', $entryId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $entry->employee_id !== (int) $employee->id) {
                    throw new InvalidArgumentException("Salary entry [{$entry->salary_number}] does not belong to employee [{$employee->code}].");
                }

                if ($entry->status !== 'posted') {
                    throw new InvalidArgumentException("Salary entry [{$entry->salary_number}] is not posted.");
                }

                if ($entry->currency_code !== $currencyCode) {
                    throw new InvalidArgumentException("Salary entry currency [{$entry->currency_code}] does not match payment currency [{$currencyCode}].");
                }

                if (Carbon::parse($entry->recognition_date)->toDateString() > $paymentDate) {
                    throw new InvalidArgumentException("Salary entry [{$entry->salary_number}] was recognized after payment date.");
                }

                // Compute outstanding payable principal and carrying base
                $alreadyPaidPrincipal = (string) $entry->paymentAllocations()
                    ->where('status', 'active')
                    ->sum('allocated_amount');
                $alreadyRelievedBase = (string) $entry->paymentAllocations()
                    ->where('status', 'active')
                    ->sum('salary_book_relief_base');

                $outstandingPrincipal = BigDecimal::of((string) $entry->net_payable)->minus(BigDecimal::of($alreadyPaidPrincipal));
                $outstandingBookBase = BigDecimal::of((string) $entry->base_payable)->minus(BigDecimal::of($alreadyRelievedBase));

                if ($allocAmount->isGreaterThan($outstandingPrincipal)) {
                    throw new InvalidArgumentException("Allocation amount [{$allocAmount}] exceeds outstanding payable principal [{$outstandingPrincipal}] on entry [{$entry->salary_number}].");
                }

                // Final entry relief consumes exact remaining payable base!
                $isEntryFinal = $allocAmount->isEqualTo($outstandingPrincipal);
                if ($isEntryFinal) {
                    $bookReliefBase = $outstandingBookBase;
                } else {
                    $bookReliefBase = $allocAmount->multipliedBy(BigDecimal::of((string) $entry->exchange_rate))->toScale(6, RoundingMode::HALF_UP);
                    if ($bookReliefBase->isGreaterThan($outstandingBookBase)) {
                        $bookReliefBase = $outstandingBookBase;
                    }
                }

                // Settlement base partition with exact final residual
                if ($isLast) {
                    $itemSettlementBase = $remainingSettlementBase;
                } else {
                    $itemSettlementBase = $allocAmount->multipliedBy($exchangeRate)->toScale(6, RoundingMode::HALF_UP);
                    if ($itemSettlementBase->isGreaterThan($remainingSettlementBase)) {
                        $itemSettlementBase = $remainingSettlementBase;
                    }
                    $remainingSettlementBase = $remainingSettlementBase->minus($itemSettlementBase);
                }

                // Realized FX delta: settlement_base - salary_book_relief_base
                // positive => FX Loss, negative => FX Gain
                $itemFxDelta = $itemSettlementBase->minus($bookReliefBase);

                $totalSalaryBookReliefBase = $totalSalaryBookReliefBase->plus($bookReliefBase);

                $allocationsData[] = [
                    'salary_entry_id' => $entry->id,
                    'allocated_amount' => $allocAmount,
                    'salary_book_relief_base' => $bookReliefBase,
                    'settlement_base' => $itemSettlementBase,
                    'realized_fx_gain_loss_base' => $itemFxDelta,
                ];
            }

            $totalRealizedFx = $totalSettlementBase->minus($totalSalaryBookReliefBase);

            // Document sequence SLP
            $year = (int) Carbon::parse($paymentDate)->format('Y');
            $paymentNumber = $this->sequenceService->generateNextNumber((int) $lockedCompany->id, DocumentSequence::TYPE_SALARY_PAYMENT, $year);

            $employeeSnapshotData = [
                'employee_id' => (int) $employee->id,
                'code' => $employee->code,
                'name' => $employee->name,
                'job_title' => $employee->job_title,
                'phone' => $employee->phone,
            ];

            $provisionalPublicId = (string) Str::ulid();

            $salaryPayment = new SalaryPayment([
                'public_id' => $provisionalPublicId,
                'company_id' => (int) $lockedCompany->id,
                'payment_number' => $paymentNumber,
                'employee_id' => (int) $employee->id,
                'employee_snapshot' => $employeeSnapshotData,
                'payment_date' => $paymentDate,
                'currency_code' => $currencyCode,
                'amount' => (string) $amount,
                'exchange_rate' => (string) $exchangeRate,
                'base_amount' => (string) $totalSettlementBase,
                'salary_book_relief_base' => (string) $totalSalaryBookReliefBase,
                'realized_fx_gain_loss_base' => (string) $totalRealizedFx,
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
            $this->persistPhase7($salaryPayment);

            foreach ($allocationsData as $alloc) {
                $this->createPhase7(new SalaryPaymentAllocation([
                    'public_id' => (string) Str::ulid(),
                    'company_id' => (int) $lockedCompany->id,
                    'salary_payment_id' => (int) $salaryPayment->id,
                    'salary_entry_id' => (int) $alloc['salary_entry_id'],
                    'allocated_amount' => (string) $alloc['allocated_amount'],
                    'salary_book_relief_base' => (string) $alloc['salary_book_relief_base'],
                    'settlement_base' => (string) $alloc['settlement_base'],
                    'realized_fx_gain_loss_base' => (string) $alloc['realized_fx_gain_loss_base'],
                    'status' => 'active',
                ]));
            }

            // Accounting posting
            $salaryPayableLedger = LedgerAccount::where('company_id', $lockedCompany->id)->where('system_key', 'salary_payable')->firstOrFail();

            $lines = [];
            $zero = MoneyAmount::from('0');

            // 1. Debit Salary Payable for historical book relief base
            app(SalesPostingLines::class)->append(
                $lines,
                1,
                (int) $salaryPayableLedger->id,
                MoneyAmount::from($totalSalaryBookReliefBase),
                $zero,
                null,
                null,
                null,
                "Salary Payment {$paymentNumber} - {$employee->name}"
            );

            // 2. Credit Cash/Bank or Checks Issued for total settlement base
            app(SalesPostingLines::class)->append(
                $lines,
                2,
                (int) $cashLedgerAccount->id,
                $zero,
                MoneyAmount::from($totalSettlementBase),
                $currencyCode,
                MoneyAmount::from($amount),
                ExchangeRate::from($exchangeRate),
                "Salary Payment {$paymentNumber} settlement"
            );

            // 3. Realized FX Gain/Loss
            if (! $totalRealizedFx->isZero()) {
                // delta > 0 is Loss, delta < 0 is Gain
                $isLoss = $totalRealizedFx->isPositive();
                $fxAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                    ->where('system_key', $isLoss ? 'fx_loss' : 'fx_gain')
                    ->firstOrFail();

                app(SalesPostingLines::class)->append(
                    $lines,
                    3,
                    (int) $fxAccount->id,
                    $isLoss ? MoneyAmount::from($totalRealizedFx) : $zero,
                    $isLoss ? $zero : MoneyAmount::from($totalRealizedFx->abs()),
                    description: "Salary payment realized FX {$paymentNumber}"
                );
            }

            $postingCommand = new PostingCommand(
                $lockedCompany,
                Carbon::parse($paymentDate),
                'salary_payment',
                (int) $salaryPayment->id,
                $currencyCode,
                $lockedCompany->base_currency_code,
                ExchangeRate::from($exchangeRate),
                "salary_payment:{$salaryPayment->public_id}",
                $actor,
                "Salary Payment {$paymentNumber}",
                lines: $lines
            );

            if ($paymentMethod === 'check') {
                app(MoneyEventScope::class)->prepareCheckPaymentCommand($postingCommand, (int) $check->id, 'outgoing');
            }

            /** @var PostingBatch $batch */
            $batch = $this->postPhase7($postingCommand);

            $salaryPayment->posting_batch_id = (int) $batch->id;
            $this->persistPhase7($salaryPayment);

            app(AuditService::class)->log(
                (int) $lockedCompany->id,
                'salary_payment.posted',
                "Posted salary payment {$paymentNumber} for {$employee->name}",
                (int) $actor->id,
                $salaryPayment,
                meta: [
                    'salary_payment_id' => $salaryPayment->id,
                    'payment_number' => $paymentNumber,
                    'amount' => (string) $amount,
                    'currency' => $currencyCode,
                    'payment_method' => $paymentMethod,
                ]
            );

            app(Phase7History::class)->validate($salaryPayment);

            return $salaryPayment->load(['employee', 'allocations', 'postingBatch']);
        });
    }
}
