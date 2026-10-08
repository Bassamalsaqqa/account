<?php

declare(strict_types=1);

namespace App\Services\Phase7;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Models\Check;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\SalaryAdvanceAllocation;
use App\Models\SalaryEntry;
use App\Models\SalaryPayment;
use App\Models\SalaryPaymentAllocation;
use App\Models\User;
use App\Services\Expenses\ExpenseLedger;
use App\Services\Money\CheckPaymentSource;
use App\Services\Money\MoneyAccountLedger;
use App\Services\Money\MoneyValues;
use App\Services\Purchasing\VendorPaymentHistoryCommands;
use App\Services\Sales\SalesPostingLines;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/** Reconstructs original economics from immutable source and allocation history. */
final class Phase7History
{
    public const SOURCES = ['expense' => Expense::class, 'employee_advance' => EmployeeAdvance::class,
        'salary_entry' => SalaryEntry::class, 'salary_payment' => SalaryPayment::class];

    public function validate(Expense|EmployeeAdvance|SalaryEntry|SalaryPayment $source): void
    {
        $company = Company::findOrFail($source->company_id);
        [$type,$number,$date] = $this->identity($source);
        $this->require(in_array($source->status, ['posted', 'reversed'], true) && $source->posted_at !== null
            && (int) $source->posted_by === (int) $source->created_by && trim($number) !== '', 'Completed source lifecycle');
        if (! $source instanceof Expense) {
            $employee = Employee::withTrashed()->where('company_id', $company->id)->findOrFail($source->employee_id);
            $snapshot = $source->getAttribute('employee_snapshot');
            $this->require(is_array($snapshot) && (int) ($snapshot['employee_id'] ?? 0) === (int) $employee->id
                && is_string($snapshot['name'] ?? null) && trim($snapshot['name']) !== '', 'Historical employee identity');
        }
        $payload = $this->payload($source);
        $this->require(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)) === $source->request_hash, 'Immutable request identity');
        $command = $this->command($source);
        $batch = $source->posting_batch_id === null ? null : PostingBatch::where('company_id', $company->id)->findOrFail($source->posting_batch_id);
        if ($command === null) {
            $this->require($batch === null && ! PostingBatch::where('company_id', $company->id)->where('source_type', $type)->where('source_id', $source->id)->exists(), 'Zero-effect source owns no GL');
        } else {
            $this->require($batch !== null, 'Canonical original batch');
            app(VendorPaymentHistoryCommands::class)->assertBatch($command, $batch);
            $this->require(PostingBatch::where('company_id', $company->id)->where('source_type', $type)->where('source_id', $source->id)->count() === 1, 'Exactly one canonical original');
        }
        if ($source->status === 'reversed') {
            $this->require($source->reversed_at !== null && $source->reversed_by !== null, 'Completed inverse lifecycle');
            if ($batch !== null) {
                $this->require($source->reversal_posting_batch_id !== null, 'Exact source inverse reference');
                app(VendorPaymentHistoryCommands::class)->assertReversal($batch, (int) $source->reversal_posting_batch_id, (int) $source->reversed_by);
                $inverse = PostingBatch::where('company_id', $company->id)->findOrFail($source->reversal_posting_batch_id);
                $this->require($inverse->posting_date->toDateString() >= $date && $inverse->description === ($source->reversal_reason !== null ? 'Reversal: '.$source->reversal_reason : 'Reversal of batch #'.$batch->public_id), 'Inverse date/reason');
            } else {
                $this->require($source->reversal_posting_batch_id === null, 'Zero-effect inverse has no GL');
            }
        } else {
            $this->require($source->reversed_at === null && $source->reversed_by === null && $source->reversal_posting_batch_id === null && $source->reversal_reason === null
                && ($batch === null || $batch->status === 'posted'), 'Active source inverse metadata');
        }
    }

    /** @return array{string,string,string} */
    private function identity(Expense|EmployeeAdvance|SalaryEntry|SalaryPayment $s): array
    {
        return match (true) {
            $s instanceof Expense => ['expense', $s->expense_number, $s->expense_date->toDateString()],
            $s instanceof EmployeeAdvance => ['employee_advance', $s->advance_number, $s->advance_date->toDateString()],
            $s instanceof SalaryEntry => ['salary_entry', $s->salary_number, $s->recognition_date->toDateString()],
            default => ['salary_payment', $s->payment_number, $s->payment_date->toDateString()],
        };
    }

    private function account(int $cid, string $key): int
    {
        return (int) LedgerAccount::where('company_id', $cid)->where('system_key', $key)->firstOrFail()->id;
    }

    private function settlement(Expense|EmployeeAdvance|SalaryPayment $s): int
    {
        if ($s->payment_method === 'check') {
            $this->require($s->money_account_id === null && $s->check_id !== null, 'Check settlement identity');
            $check = Check::where('company_id', $s->company_id)->findOrFail($s->check_id);

            return (int) app(CheckPaymentSource::class)->ledger($check, false)->id;
        }
        $this->require($s->check_id === null && $s->money_account_id !== null && in_array($s->payment_method, ['cash', 'bank'], true), 'Cash/Bank settlement identity');
        $account = MoneyAccount::withTrashed()->where('company_id', $s->company_id)->findOrFail($s->money_account_id);
        $this->require($account->currency_code === $s->currency_code && $account->account_type === $s->payment_method, 'Frozen Money routing');

        return (int) app(MoneyAccountLedger::class)->validate($account)->id;
    }

    public function command(Expense|EmployeeAdvance|SalaryEntry|SalaryPayment $s): ?PostingCommand
    {
        $cid = (int) $s->company_id;
        $company = Company::findOrFail($cid);
        [$type,$number,$date] = $this->identity($s);
        $currency = $s->currency_code;
        $rate = MoneyValues::rate($s->exchange_rate, $currency, $company->base_currency_code);
        $lines = [];
        $zero = MoneyAmount::zero();
        $append = function (int $account, string|BigDecimal $debit, string|BigDecimal $credit, ?string $tx, ?BigDecimal $amount, ?string $description) use (&$lines, $rate): void {
            app(SalesPostingLines::class)->append($lines, count($lines) + 1, $account, MoneyAmount::from($debit), MoneyAmount::from($credit), $tx,
                $amount === null ? null : MoneyAmount::from($amount), $tx === null ? null : ExchangeRate::from($rate), $description);
        };
        if ($s instanceof Expense || $s instanceof EmployeeAdvance) {
            $amount = MoneyValues::amount($s->amount, $currency);
            $base = MoneyValues::base($amount, $rate);
            $this->require($base->isEqualTo($s->base_amount), 'Principal base value');
            if ($s instanceof Expense) {
                $snapshot = $s->getAttribute('category_snapshot');
                $this->require(is_array($snapshot) && (int) ($snapshot['id'] ?? 0) === (int) $s->category_id
                    && isset($snapshot['ledger_account_id'],$snapshot['name_ar']), 'Category snapshot');
                $debit = $s->classification === 'landed_cost' ? $this->account($cid, 'landed_cost_clearing') : (int) $snapshot['ledger_account_id'];
                $ledger = LedgerAccount::where('company_id', $cid)->findOrFail($debit);
                $this->require($s->classification === 'landed_cost' || ($s->classification === 'operating' && $ledger->account_type === 'expense' && ! $ledger->is_control), 'Expense routing');
                if ($s->classification === 'operating') {
                    app(ExpenseLedger::class)->validate($ledger);
                }
                $suffix = $s->classification === 'landed_cost' ? 'تكلفة شحن وتخليص إضافية' : $snapshot['name_ar'];
                $append($debit, $base, '0', $currency, $amount, "Expense {$number} - {$suffix}");
                $append($this->settlement($s), '0', $base, $currency, $amount, "Expense {$number} payment");
                $description = "Expense {$number}";
            } else {
                $append($this->account($cid, 'employee_advances'), $base, '0', $currency, $amount, "Advance {$number} - ".$s->employee_snapshot['name']);
                $append($this->settlement($s), '0', $base, $currency, $amount, "Advance {$number} payment");
                $description = "Employee Advance {$number}";
            }
        } elseif ($s instanceof SalaryEntry) {
            $earned = BigDecimal::of($s->base_salary)->plus($s->bonus)->minus($s->deduction);
            $this->require(! $earned->isNegative() && $earned->isEqualTo($s->earned_salary), 'Earned salary');
            $base = $earned->multipliedBy($rate)->toScale(6, RoundingMode::HALF_UP);
            $this->require($base->isEqualTo($s->base_earned_salary), 'Earned base');
            $applied = BigDecimal::zero();
            $book = BigDecimal::zero();
            $relief = BigDecimal::zero();
            $rows = $s->advanceAllocations()->orderBy('employee_advance_id')->get();
            $seen = [];
            foreach ($rows as $row) {
                $target = EmployeeAdvance::where('company_id', $cid)->findOrFail($row->employee_advance_id);
                $this->validate($target);
                $this->require(! isset($seen[$target->id]) && (int) $target->employee_id === (int) $s->employee_id && $target->currency_code === $currency
                    && $target->advance_date->toDateString() <= $date && (int) $row->company_id === $cid, 'Advance allocation provenance');
                $seen[$target->id] = true;
                $amount = MoneyValues::amount($row->allocated_amount, $currency);
                [$remaining,$carrying] = $this->priorResidual($target, $s);
                $this->require($amount->isLessThanOrEqualTo($remaining), 'Historical Advance residual');
                $consumed = $amount->isEqualTo($remaining) ? $carrying : $amount->multipliedBy($target->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                $consumed = BigDecimal::min($consumed, $carrying);
                $applied = $applied->plus($amount);
                $next = BigDecimal::min($applied->multipliedBy($rate)->toScale(6, RoundingMode::HALF_UP), $base);
                $part = $next->minus($relief);
                $relief = $next;
                $book = $book->plus($consumed);
                $this->require($consumed->isEqualTo($row->advance_base_consumed) && $part->isEqualTo($row->salary_base_relief)
                    && $part->minus($consumed)->isEqualTo($row->realized_fx_gain_loss_base), 'Exact Advance relief/FX');
                $this->allocationLifecycle($row, $s);
            }
            $net = $earned->minus($applied);
            $payable = $base->minus($relief);
            $fx = $relief->minus($book);
            $this->require(! $net->isNegative() && $applied->isEqualTo($s->advance_applied) && $net->isEqualTo($s->net_payable)
                && $relief->isEqualTo($s->base_advance_relief) && $payable->isEqualTo($s->base_payable) && $fx->isEqualTo($s->realized_fx_gain_loss_base), 'Salary partition');
            if ($earned->isZero()) {
                $this->require($rows->isEmpty(), 'Zero salary has no consumption');

                return null;
            }
            $this->require($base->isPositive(), 'Representable earned value');
            $append($this->account($cid, 'salary_expense'), $base, '0', $currency, $earned, "Salary Expense {$number} - ".$s->employee_snapshot['name']);
            if ($book->isPositive()) {
                $append($this->account($cid, 'employee_advances'), '0', $book, null, null, "Advance relief {$number}");
            }
            if ($net->isPositive()) {
                $append($this->account($cid, 'salary_payable'), '0', $payable, $currency, $net, "Salary Payable {$number}");
            }
            if (! $fx->isZero()) {
                $append($this->account($cid, $fx->isPositive() ? 'fx_gain' : 'fx_loss'), $fx->isNegative() ? $fx->abs() : '0', $fx->isPositive() ? $fx : '0', null, null, "Salary advance relief FX {$number}");
            }
            $description = "Salary Entry {$number}";
        } else {
            $amount = MoneyValues::amount($s->amount, $currency);
            $base = MoneyValues::base($amount, $rate);
            $this->require($base->isEqualTo($s->base_amount), 'Salary settlement base');
            $rows = $s->allocations()->orderBy('salary_entry_id')->get();
            $seen = [];
            $principal = BigDecimal::zero();
            $book = BigDecimal::zero();
            $remainingBase = $base;
            foreach ($rows as $index => $row) {
                $target = SalaryEntry::where('company_id', $cid)->findOrFail($row->salary_entry_id);
                $this->validate($target);
                $this->require(! isset($seen[$target->id]) && (int) $target->employee_id === (int) $s->employee_id && $target->currency_code === $currency
                    && $target->recognition_date->toDateString() <= $date && (int) $row->company_id === $cid, 'Salary allocation provenance');
                $seen[$target->id] = true;
                $allocated = MoneyValues::amount($row->allocated_amount, $currency);
                [$remaining,$carrying] = $this->priorResidual($target, $s);
                $this->require($allocated->isLessThanOrEqualTo($remaining), 'Historical Salary residual');
                $relief = $allocated->isEqualTo($remaining) ? $carrying : BigDecimal::min($allocated->multipliedBy($target->exchange_rate)->toScale(6, RoundingMode::HALF_UP), $carrying);
                $settlement = $index === $rows->count() - 1 ? $remainingBase : BigDecimal::min($allocated->multipliedBy($rate)->toScale(6, RoundingMode::HALF_UP), $remainingBase);
                $remainingBase = $remainingBase->minus($settlement);
                $principal = $principal->plus($allocated);
                $book = $book->plus($relief);
                $this->require($relief->isEqualTo($row->salary_book_relief_base) && $settlement->isEqualTo($row->settlement_base)
                    && $settlement->minus($relief)->isEqualTo($row->realized_fx_gain_loss_base), 'Salary allocation exact values');
                $this->allocationLifecycle($row, $s);
            }
            $fx = $base->minus($book);
            $this->require($rows->isNotEmpty() && $principal->isEqualTo($amount) && $book->isEqualTo($s->salary_book_relief_base)
                && $fx->isEqualTo($s->realized_fx_gain_loss_base), 'Salary Payment partition');
            $append($this->account($cid, 'salary_payable'), $book, '0', null, null, "Salary Payment {$number} - ".$s->employee_snapshot['name']);
            $append($this->settlement($s), '0', $base, $currency, $amount, "Salary Payment {$number} settlement");
            if (! $fx->isZero()) {
                $append($this->account($cid, $fx->isPositive() ? 'fx_loss' : 'fx_gain'), $fx->isPositive() ? $fx : '0', $fx->isNegative() ? $fx->abs() : '0', null, null, "Salary payment realized FX {$number}");
            }
            $description = "Salary Payment {$number}";
        }

        return new PostingCommand($company, Carbon::parse($date), $type, (int) $s->id, $currency, $company->base_currency_code,
            ExchangeRate::from($rate), $type.':'.$s->public_id, User::findOrFail($s->posted_by), $description, lines: $lines);
    }

    /** @return array{BigDecimal,BigDecimal} */
    private function priorResidual(EmployeeAdvance|SalaryEntry $target, SalaryEntry|SalaryPayment $source): array
    {
        $advance = $target instanceof EmployeeAdvance;
        $this->require($target->posting_batch_id !== null && (int) $target->posting_batch_id < (int) $source->posting_batch_id
            && ($target->reversal_posting_batch_id === null || (int) $target->reversal_posting_batch_id > (int) $source->posting_batch_id), 'Target existed and was active at consumption');
        $rows = $advance ? $target->salaryAdvanceAllocations()->get() : $target->paymentAllocations()->get();
        $principal = BigDecimal::of($advance ? $target->amount : $target->net_payable);
        $base = BigDecimal::of($advance ? $target->base_amount : $target->base_payable);
        foreach ($rows as $row) {
            $parent = $advance ? $row->salaryEntry()->first() : $row->salaryPayment()->first();
            $this->require($parent !== null && (int) $parent->company_id === (int) $target->company_id && (int) $row->company_id === (int) $target->company_id, 'Allocation parent');
            if ($parent->id === $source->id && $parent::class === $source::class) {
                continue;
            }
            $original = $parent->posting_batch_id;
            $inverse = $parent->reversal_posting_batch_id;
            if ($original !== null && (int) $original < (int) $source->posting_batch_id && ($inverse === null || (int) $inverse > (int) $source->posting_batch_id)) {
                $principal = $principal->minus($row->allocated_amount);
                $base = $base->minus($advance ? $row->advance_base_consumed : $row->salary_book_relief_base);
            }
        }
        $this->require(! $principal->isNegative() && ! $base->isNegative(), 'Nonnegative historical residual');

        return [$principal, $base];
    }

    private function allocationLifecycle(SalaryAdvanceAllocation|SalaryPaymentAllocation $row, SalaryEntry|SalaryPayment $parent): void
    {
        $this->require($row->status === ($parent->status === 'posted' ? 'active' : 'reversed')
            && ($parent->status === 'posted' ? $row->reversed_at === null : $row->reversed_at !== null), 'Controlled allocation inverse state');
    }

    /** @return array<string,mixed> */
    private function payload(Expense|EmployeeAdvance|SalaryEntry|SalaryPayment $s): array
    {
        $common = ['company_id' => (int) $s->company_id, 'actor_id' => (int) $s->created_by];
        if ($s instanceof Expense) {
            return $common + ['category_id' => (int) $s->category_id, 'vendor_id' => $s->vendor_id, 'payee_name' => $s->payee_name, 'classification' => $s->classification,
                'description' => $s->description, 'currency_code' => $s->currency_code, 'amount' => $s->amount, 'exchange_rate' => $s->exchange_rate, 'base_amount' => $s->base_amount,
                'payment_method' => $s->payment_method, 'money_account_id' => $s->money_account_id, 'check_id' => $s->check_id, 'expense_date' => $s->expense_date->toDateString(), 'notes' => $s->notes];
        }
        if ($s instanceof EmployeeAdvance) {
            return $common + ['employee_id' => (int) $s->employee_id, 'currency_code' => $s->currency_code, 'amount' => $s->amount, 'exchange_rate' => $s->exchange_rate, 'base_amount' => $s->base_amount,
                'payment_method' => $s->payment_method, 'money_account_id' => $s->money_account_id, 'check_id' => $s->check_id, 'advance_date' => $s->advance_date->toDateString(), 'notes' => $s->notes];
        }
        if ($s instanceof SalaryEntry) {
            $rows = $s->advanceAllocations()->orderBy('employee_advance_id')->get()->map(fn ($a) => ['advance_id' => (int) $a->employee_advance_id, 'allocated_amount' => $a->allocated_amount])->all();

            return $common + ['employee_id' => (int) $s->employee_id, 'recognition_date' => $s->recognition_date->toDateString(), 'period_start' => $s->period_start->toDateString(),
                'period_end' => $s->period_end->toDateString(), 'currency_code' => $s->currency_code, 'exchange_rate' => $s->exchange_rate, 'base_salary' => $s->base_salary, 'bonus' => $s->bonus,
                'deduction' => $s->deduction, 'earned_salary' => $s->earned_salary, 'advances' => $rows, 'notes' => $s->notes];
        }
        $rows = $s->allocations()->orderBy('salary_entry_id')->get()->map(fn ($a) => ['salary_entry_id' => (int) $a->salary_entry_id, 'allocated_amount' => $a->allocated_amount])->all();

        return $common + ['employee_id' => (int) $s->employee_id, 'payment_date' => $s->payment_date->toDateString(), 'currency_code' => $s->currency_code, 'exchange_rate' => $s->exchange_rate,
            'amount' => $s->amount, 'base_amount' => $s->base_amount, 'payment_method' => $s->payment_method, 'money_account_id' => $s->money_account_id, 'check_id' => $s->check_id, 'allocations' => $rows, 'notes' => $s->notes];
    }

    private function require(bool $condition, string $invariant): void
    {
        if (! $condition) {
            throw new ImmutableRecordException('Phase7 history integrity failed: '.$invariant);
        }
    }
}
