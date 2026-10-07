<?php

declare(strict_types=1);

namespace App\Services\Phase7;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Expenses\ReverseExpenseAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Payroll\PostSalaryPaymentAction;
use App\Actions\Payroll\ReverseEmployeeAdvanceAction;
use App\Actions\Payroll\ReverseSalaryEntryAction;
use App\Actions\Payroll\ReverseSalaryPaymentAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Posting\DTO\PostingCommand;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\LandedCostAllocation;
use App\Models\PostingBatch;
use App\Models\SalaryAdvanceAllocation;
use App\Models\SalaryEntry;
use App\Models\SalaryPayment;
use App\Models\SalaryPaymentAllocation;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;

/** Request-local authority belonging to the executing concrete business action. */
final class Phase7EventScope
{
    private ?Phase7EventOwner $owner = null;

    private ?Connection $connection = null;

    private ?PDO $pdo = null;

    private ?DatabaseTransactionRecord $transaction = null;

    private int $companyId = 0;

    private int $actorId = 0;

    /** @var array<int, array<string, mixed>> */
    private array $records = [];

    private ?PostingCommand $command = null;

    /** @var array<int, string> */
    private array $reversals = [];

    private const OWNERS = [
        PostExpenseAction::class => [Expense::class],
        ReverseExpenseAction::class => [Expense::class, LandedCostAllocation::class],
        PostEmployeeAdvanceAction::class => [EmployeeAdvance::class],
        ReverseEmployeeAdvanceAction::class => [EmployeeAdvance::class],
        PostSalaryEntryAction::class => [SalaryEntry::class, SalaryAdvanceAllocation::class],
        ReverseSalaryEntryAction::class => [SalaryEntry::class, SalaryAdvanceAllocation::class],
        PostSalaryPaymentAction::class => [SalaryPayment::class, SalaryPaymentAllocation::class],
        ReverseSalaryPaymentAction::class => [SalaryPayment::class, SalaryPaymentAllocation::class],
    ];

    private function __clone() {}

    public function __serialize(): array
    {
        throw new LogicException('Canonical event authority cannot be serialized.');
    }

    /** @param array<string,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Canonical event authority cannot be restored.');
    }

    public function within(Phase7EventOwner $owner, int $companyId, User $actor, Closure $callback): mixed
    {
        if ($this->owner !== null || ! isset(self::OWNERS[$owner::class]) || ! $owner->ownsPhase7Scope($this)) {
            throw new LogicException('Only the executing concrete Phase7 action may enter its scope.');
        }
        $connection = DB::connection();
        $transaction = app('db.transactions')->getPendingTransactions()->last(
            fn (DatabaseTransactionRecord $r) => $r->connection === $connection->getName() && $r->level === $connection->transactionLevel());
        if ($transaction === null || ! $connection->getPdo()->inTransaction()) {
            throw new LogicException('Canonical transaction required.');
        }
        $this->owner = $owner;
        $this->companyId = $companyId;
        $this->actorId = (int) $actor->id;
        $this->connection = $connection;
        $this->pdo = $connection->getPdo();
        $this->transaction = $transaction;
        try {
            $this->assert($companyId);

            return $callback();
        } finally {
            $this->owner = null;
            $this->connection = null;
            $this->pdo = null;
            $this->transaction = null;
            $this->companyId = $this->actorId = 0;
            $this->records = $this->reversals = [];
            $this->command = null;
        }
    }

    private function assert(int $companyId): void
    {
        $context = app(CompanyContext::class);
        if ($this->owner === null || ! $this->owner->ownsPhase7Scope($this) || $companyId !== $this->companyId
            || ! $context->hasCompany() || (int) $context->companyId() !== $companyId || (int) auth()->id() !== $this->actorId
            || $this->connection !== DB::connection() || $this->pdo !== $this->connection->getPdo()
            || ! $this->pdo->inTransaction() || $this->connection->transactionLevel() < $this->transaction?->level
            || ! app('db.transactions')->getPendingTransactions()->contains(fn ($r) => $r === $this->transaction)) {
            throw new LogicException('Expired or mismatched Phase7 business-event authority.');
        }
    }

    public function persist(Phase7EventOwner $owner, Model $record): void
    {
        $this->assert((int) $record->getAttribute('company_id'));
        if ($owner !== $this->owner || ! in_array($record::class, self::OWNERS[$owner::class], true)) {
            throw new LogicException('Record does not belong to this canonical action.');
        }
        $identity = spl_object_id($record);
        $this->records[$identity] = $record->getAttributes();
        try {
            $record->save();
        } finally {
            unset($this->records[$identity]);
        }
    }

    public function consumeRecord(Model $record): void
    {
        try {
            $this->assert((int) $record->getAttribute('company_id'));
        } catch (LogicException $e) {
            throw new ImmutableRecordException('Financial records require their canonical action.', previous: $e);
        }
        $identity = spl_object_id($record);
        if (! isset($this->records[$identity]) || $this->records[$identity] !== $record->getAttributes()) {
            throw new ImmutableRecordException('Exact one-use canonical record completion required.');
        }
        unset($this->records[$identity]);
    }

    public function prepareCommand(Phase7EventOwner $owner, PostingCommand $command): void
    {
        $this->assert((int) $command->company->id);
        if ($owner !== $this->owner || $this->command !== null) {
            throw new LogicException('Invalid command owner.');
        }
        $this->command = $command;
    }

    public function assertCommand(PostingCommand $command): void
    {
        $this->assert((int) $command->company->id);
        if ($command !== $this->command || (int) $command->postedBy?->id !== $this->actorId) {
            throw new LogicException('Exact canonical command required.');
        }
        $this->command = null;
    }

    public function prepareReversal(Phase7EventOwner $owner, PostingBatch $batch, string $date): void
    {
        $this->assert((int) $batch->company_id);
        if ($owner !== $this->owner) {
            throw new LogicException('Invalid reversal owner.');
        }
        $this->reversals[(int) $batch->id] = $date;
    }

    public function assertReversal(PostingBatch $batch, User $actor, ?string $date): void
    {
        $this->assert((int) $batch->company_id);
        if ((int) $actor->id !== $this->actorId || ($this->reversals[$batch->id] ?? null) !== $date || $date === null) {
            throw new LogicException('Exact canonical reversal required.');
        }
        unset($this->reversals[$batch->id]);
    }
}
