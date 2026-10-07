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
use App\Models\PostingBatch;
use App\Models\SalaryAdvanceAllocation;
use App\Models\SalaryEntry;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyValues;
use App\Services\Phase7\OwnsPhase7Event;
use App\Services\Phase7\Phase7Amounts;
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

final class PostSalaryEntryAction implements Phase7EventOwner
{
    use OwnsPhase7Event;

    public function __construct(
        private readonly DocumentSequenceService $sequenceService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Company $company, User $actor, array $data): SalaryEntry
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
        if (! $actor->hasPermissionTo('payroll.salary.post')) {
            throw new AuthorizationException('User does not have permission to post salary entries.');
        }

        $idempotencyKey = ReceiptRequestValues::key($data['idempotency_key'] ?? null);
        $recognitionDate = (string) ($data['recognition_date'] ?? '');
        $periodStart = (string) ($data['period_start'] ?? '');
        $periodEnd = (string) ($data['period_end'] ?? '');

        app(SalesDocumentRules::class)->date($recognitionDate);
        app(SalesDocumentRules::class)->date($periodStart);
        app(SalesDocumentRules::class)->date($periodEnd);

        if ($periodStart > $periodEnd) {
            throw new InvalidArgumentException('Period start date cannot be after period end date.');
        }

        $employeeId = ReceiptRequestValues::id($data['employee_id'] ?? null);
        $currencyCode = (string) ($data['currency_code'] ?? '');
        $exchangeRate = MoneyValues::rate($data['exchange_rate'] ?? '1', $currencyCode, $company->base_currency_code);

        $baseSalary = $this->parseNonNegativeAmount($data['base_salary'] ?? '0', $currencyCode);
        $bonus = $this->parseNonNegativeAmount($data['bonus'] ?? '0', $currencyCode);
        $deduction = $this->parseNonNegativeAmount($data['deduction'] ?? '0', $currencyCode);

        if ($baseSalary->isNegative() || $bonus->isNegative() || $deduction->isNegative()) {
            throw new InvalidArgumentException('Base salary, bonus, and deduction cannot be negative.');
        }

        $earnedSalary = $baseSalary->plus($bonus)->minus($deduction);
        if ($earnedSalary->isNegative()) {
            throw new InvalidArgumentException('Earned salary (base + bonus - deduction) cannot be negative.');
        }

        $advancesInput = $data['advances'] ?? [];
        $notes = MoneyValues::text($data['notes'] ?? null, 2000);

        // Normalize advances for request hash
        $normalizedAdvances = [];
        foreach ($advancesInput as $adv) {
            $advId = ReceiptRequestValues::id($adv['advance_id'] ?? null);
            $advAmount = (string) MoneyValues::amount($adv['allocated_amount'] ?? '0', $currencyCode);
            $normalizedAdvances[] = ['advance_id' => $advId, 'allocated_amount' => $advAmount];
        }
        $targetIds = array_column($normalizedAdvances, 'advance_id');
        if (count($targetIds) !== count(array_unique($targetIds))) {
            throw new InvalidArgumentException('Duplicate allocation targets are not permitted.');
        }
        usort($normalizedAdvances, fn ($a, $b) => $a['advance_id'] <=> $b['advance_id']);

        $canonicalPayload = [
            'company_id' => (int) $company->id,
            'actor_id' => (int) $actor->id,
            'employee_id' => $employeeId,
            'recognition_date' => $recognitionDate,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'currency_code' => $currencyCode,
            'exchange_rate' => (string) $exchangeRate,
            'base_salary' => (string) $baseSalary,
            'bonus' => (string) $bonus,
            'deduction' => (string) $deduction,
            'earned_salary' => (string) $earnedSalary,
            'advances' => $normalizedAdvances,
            'notes' => $notes,
        ];
        $requestHash = hash('sha256', json_encode($canonicalPayload, JSON_THROW_ON_ERROR));

        return $this->canonicalTransaction((int) $company->id, $actor, function () use (
            $company,
            $actor,
            $idempotencyKey,
            $requestHash,
            $employeeId,
            $recognitionDate,
            $periodStart,
            $periodEnd,
            $currencyCode,
            $exchangeRate,
            $baseSalary,
            $bonus,
            $deduction,
            $earnedSalary,
            $normalizedAdvances,
            $notes
        ): SalaryEntry {
            $lockedCompany = Company::where('id', $company->id)->lockForUpdate()->firstOrFail();
            app(MoneyActorGuard::class)->authorize((int) $lockedCompany->id, 'payroll.salary.post');

            // 1. Idempotency check
            $existing = SalaryEntry::where('company_id', $lockedCompany->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->request_hash !== $requestHash) {
                    throw new IdempotencyConflictException("Idempotency key [{$idempotencyKey}] was already used with different salary entry parameters.");
                }

                app(Phase7History::class)->validate($existing);

                return $existing->load(['employee', 'advanceAllocations', 'postingBatch']);
            }

            if (! CompanyCurrency::where('company_id', $lockedCompany->id)->where('currency_code', $currencyCode)->where('enabled', true)->exists()) {
                throw new InvalidArgumentException("Salary currency [{$currencyCode}] is not enabled in this company.");
            }

            // 2. Lock Employee FOR UPDATE
            $employee = Employee::where('company_id', $lockedCompany->id)->lockForUpdate()->findOrFail($employeeId);
            if (! $employee->active) {
                throw new InvalidArgumentException("Employee [{$employee->code}] is inactive.");
            }

            // 3. Prevent inclusive overlapping active posted periods for same employee
            $hasOverlap = SalaryEntry::where('company_id', $lockedCompany->id)
                ->where('employee_id', $employee->id)
                ->where('status', 'posted')
                ->where('period_start', '<=', $periodEnd)
                ->where('period_end', '>=', $periodStart)
                ->exists();

            if ($hasOverlap) {
                throw new InvalidArgumentException("Salary period [{$periodStart} to {$periodEnd}] overlaps with an existing posted salary entry for employee [{$employee->code}].");
            }

            // 4. Validate and allocate selected advances
            $totalAdvanceApplied = BigDecimal::zero();
            $totalAdvanceBaseConsumed = BigDecimal::zero();
            $totalSalaryBaseRelief = BigDecimal::zero();
            $totalFxGainLoss = BigDecimal::zero();

            $advanceAllocationsData = [];

            foreach ($normalizedAdvances as $item) {
                $advId = $item['advance_id'];
                $allocAmount = BigDecimal::of($item['allocated_amount']);

                if ($allocAmount->isNegative() || $allocAmount->isZero()) {
                    throw new InvalidArgumentException('Advance allocation amount must be positive.');
                }

                /** @var EmployeeAdvance $advance */
                $advance = EmployeeAdvance::where('company_id', $lockedCompany->id)
                    ->where('id', $advId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $advance->employee_id !== (int) $employee->id) {
                    throw new InvalidArgumentException("Advance [{$advance->advance_number}] does not belong to employee [{$employee->code}].");
                }

                if ($advance->status !== 'posted') {
                    throw new InvalidArgumentException("Advance [{$advance->advance_number}] is not posted.");
                }

                if ($advance->currency_code !== $currencyCode) {
                    throw new InvalidArgumentException("Advance currency [{$advance->currency_code}] does not match salary currency [{$currencyCode}].");
                }

                if (Carbon::parse($advance->advance_date)->toDateString() > $recognitionDate) {
                    throw new InvalidArgumentException("Advance [{$advance->advance_number}] was issued after salary recognition date.");
                }

                // Calculate outstanding principal and carrying base
                $alreadyAllocated = (string) $advance->salaryAdvanceAllocations()
                    ->where('status', 'active')
                    ->sum('allocated_amount');
                $alreadyConsumedBase = (string) $advance->salaryAdvanceAllocations()
                    ->where('status', 'active')
                    ->sum('advance_base_consumed');

                $outstandingPrincipal = BigDecimal::of((string) $advance->amount)->minus(BigDecimal::of($alreadyAllocated));
                $outstandingCarryingBase = BigDecimal::of((string) $advance->base_amount)->minus(BigDecimal::of($alreadyConsumedBase));

                if ($allocAmount->isGreaterThan($outstandingPrincipal)) {
                    throw new InvalidArgumentException("Allocated amount [{$allocAmount}] exceeds outstanding advance principal [{$outstandingPrincipal}].");
                }

                // If final allocation, consume exact remaining carrying base!
                $isFinal = $allocAmount->isEqualTo($outstandingPrincipal);
                if ($isFinal) {
                    $advBaseConsumed = $outstandingCarryingBase;
                } else {
                    $advBaseConsumed = $allocAmount->multipliedBy(BigDecimal::of((string) $advance->exchange_rate))->toScale(6, RoundingMode::HALF_UP);
                    if ($advBaseConsumed->isGreaterThan($outstandingCarryingBase)) {
                        $advBaseConsumed = $outstandingCarryingBase;
                    }
                }

                $cumulativeRelief = BigDecimal::min($totalAdvanceApplied->plus($allocAmount)->multipliedBy($exchangeRate)->toScale(6, RoundingMode::HALF_UP), $earnedSalary->multipliedBy($exchangeRate)->toScale(6, RoundingMode::HALF_UP));
                $salaryReliefBase = $cumulativeRelief->minus($totalSalaryBaseRelief);
                $fxDelta = $salaryReliefBase->minus($advBaseConsumed);

                $totalAdvanceApplied = $totalAdvanceApplied->plus($allocAmount);
                $totalAdvanceBaseConsumed = $totalAdvanceBaseConsumed->plus($advBaseConsumed);
                $totalSalaryBaseRelief = $totalSalaryBaseRelief->plus($salaryReliefBase);
                $totalFxGainLoss = $totalFxGainLoss->plus($fxDelta);

                $advanceAllocationsData[] = [
                    'advance_id' => $advance->id,
                    'allocated_amount' => $allocAmount,
                    'advance_base_consumed' => $advBaseConsumed,
                    'salary_base_relief' => $salaryReliefBase,
                    'realized_fx_gain_loss_base' => $fxDelta,
                ];
            }

            if ($totalAdvanceApplied->isGreaterThan($earnedSalary)) {
                throw new InvalidArgumentException("Total advance applied [{$totalAdvanceApplied}] cannot exceed earned salary [{$earnedSalary}].");
            }

            $netPayable = $earnedSalary->minus($totalAdvanceApplied);
            $baseEarnedSalary = $earnedSalary->multipliedBy($exchangeRate)->toScale(6, RoundingMode::HALF_UP);

            // Deterministic residual: base_payable = base_earned_salary - totalSalaryBaseRelief
            $basePayable = $baseEarnedSalary->minus($totalSalaryBaseRelief);

            // Consume SAL sequence
            $year = (int) Carbon::parse($recognitionDate)->format('Y');
            $salaryNumber = $this->sequenceService->generateNextNumber((int) $lockedCompany->id, DocumentSequence::TYPE_SALARY_ENTRY, $year);

            $employeeSnapshotData = [
                'employee_id' => (int) $employee->id,
                'code' => $employee->code,
                'name' => $employee->name,
                'job_title' => $employee->job_title,
                'phone' => $employee->phone,
            ];

            $provisionalPublicId = (string) Str::ulid();

            $salaryEntry = new SalaryEntry([
                'public_id' => $provisionalPublicId,
                'company_id' => (int) $lockedCompany->id,
                'salary_number' => $salaryNumber,
                'employee_id' => (int) $employee->id,
                'employee_snapshot' => $employeeSnapshotData,
                'recognition_date' => $recognitionDate,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'currency_code' => $currencyCode,
                'exchange_rate' => (string) $exchangeRate,
                'base_salary' => (string) $baseSalary,
                'bonus' => (string) $bonus,
                'deduction' => (string) $deduction,
                'earned_salary' => (string) $earnedSalary,
                'advance_applied' => (string) $totalAdvanceApplied,
                'net_payable' => (string) $netPayable,
                'base_earned_salary' => (string) $baseEarnedSalary,
                'base_advance_relief' => (string) $totalSalaryBaseRelief,
                'base_payable' => (string) $basePayable,
                'realized_fx_gain_loss_base' => (string) $totalFxGainLoss,
                'notes' => $notes,
                'posting_batch_id' => null,
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => (int) $actor->id,
                'request_hash' => $requestHash,
                'idempotency_key' => $idempotencyKey,
                'created_by' => (int) $actor->id,
            ]);
            $this->persistPhase7($salaryEntry);

            // Record allocations
            foreach ($advanceAllocationsData as $alloc) {
                $this->createPhase7(new SalaryAdvanceAllocation([
                    'public_id' => (string) Str::ulid(),
                    'company_id' => (int) $lockedCompany->id,
                    'salary_entry_id' => (int) $salaryEntry->id,
                    'employee_advance_id' => (int) $alloc['advance_id'],
                    'allocated_amount' => (string) $alloc['allocated_amount'],
                    'advance_base_consumed' => (string) $alloc['advance_base_consumed'],
                    'salary_base_relief' => (string) $alloc['salary_base_relief'],
                    'realized_fx_gain_loss_base' => (string) $alloc['realized_fx_gain_loss_base'],
                    'status' => 'active',
                ]));
            }

            // Post to General Ledger if positive earned salary
            if ($baseEarnedSalary->isPositive()) {
                $salaryExpenseLedger = LedgerAccount::where('company_id', $lockedCompany->id)->where('system_key', 'salary_expense')->firstOrFail();
                $salaryPayableLedger = LedgerAccount::where('company_id', $lockedCompany->id)->where('system_key', 'salary_payable')->firstOrFail();
                $advanceLedger = LedgerAccount::where('company_id', $lockedCompany->id)->where('system_key', 'employee_advances')->firstOrFail();

                $lines = [];
                $zero = MoneyAmount::from('0');

                // 1. Debit Salary Expense
                app(SalesPostingLines::class)->append(
                    $lines,
                    count($lines) + 1,
                    (int) $salaryExpenseLedger->id,
                    MoneyAmount::from($baseEarnedSalary),
                    $zero,
                    $currencyCode,
                    MoneyAmount::from($earnedSalary),
                    ExchangeRate::from($exchangeRate),
                    "Salary Expense {$salaryNumber} - {$employee->name}"
                );

                // 2. Credit Employee Advances (if any advance consumed)
                if ($totalAdvanceBaseConsumed->isPositive()) {
                    app(SalesPostingLines::class)->append(
                        $lines,
                        count($lines) + 1,
                        (int) $advanceLedger->id,
                        $zero,
                        MoneyAmount::from($totalAdvanceBaseConsumed),
                        null,
                        null,
                        null,
                        "Advance relief {$salaryNumber}"
                    );
                }

                // 3. Credit Salary Payable (if net payable base > 0)
                if ($netPayable->isPositive()) {
                    app(SalesPostingLines::class)->append(
                        $lines,
                        count($lines) + 1,
                        (int) $salaryPayableLedger->id,
                        $zero,
                        MoneyAmount::from($basePayable),
                        $currencyCode,
                        MoneyAmount::from($netPayable),
                        ExchangeRate::from($exchangeRate),
                        "Salary Payable {$salaryNumber}"
                    );
                }

                // 4. Realized FX Gain/Loss
                if (! $totalFxGainLoss->isZero()) {
                    $fxAccount = LedgerAccount::where('company_id', $lockedCompany->id)
                        ->where('system_key', $totalFxGainLoss->isPositive() ? 'fx_gain' : 'fx_loss')
                        ->firstOrFail();

                    app(SalesPostingLines::class)->append(
                        $lines,
                        count($lines) + 1,
                        (int) $fxAccount->id,
                        $totalFxGainLoss->isNegative() ? MoneyAmount::from($totalFxGainLoss->abs()) : $zero,
                        $totalFxGainLoss->isPositive() ? MoneyAmount::from($totalFxGainLoss) : $zero,
                        description: "Salary advance relief FX {$salaryNumber}"
                    );
                }

                $postingCommand = new PostingCommand(
                    $lockedCompany,
                    Carbon::parse($recognitionDate),
                    'salary_entry',
                    (int) $salaryEntry->id,
                    $currencyCode,
                    $lockedCompany->base_currency_code,
                    ExchangeRate::from($exchangeRate),
                    "salary_entry:{$salaryEntry->public_id}",
                    $actor,
                    "Salary Entry {$salaryNumber}",
                    lines: $lines
                );

                /** @var PostingBatch $batch */
                $batch = $this->postPhase7($postingCommand);

                $salaryEntry->posting_batch_id = (int) $batch->id;
                $this->persistPhase7($salaryEntry);
            }

            app(AuditService::class)->log(
                (int) $lockedCompany->id,
                'salary_entry.posted',
                "Posted salary entry {$salaryNumber} for {$employee->name}",
                (int) $actor->id,
                $salaryEntry,
                meta: [
                    'salary_entry_id' => $salaryEntry->id,
                    'salary_number' => $salaryNumber,
                    'earned_salary' => (string) $earnedSalary,
                    'net_payable' => (string) $netPayable,
                    'advances_applied' => (string) $totalAdvanceApplied,
                ]
            );

            app(Phase7History::class)->validate($salaryEntry);

            return $salaryEntry->load(['employee', 'advanceAllocations', 'postingBatch']);
        });
    }

    private function parseNonNegativeAmount(mixed $value, string $currencyCode): BigDecimal
    {
        return Phase7Amounts::nonNegative($value, $currencyCode);
    }
}
