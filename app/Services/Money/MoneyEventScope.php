<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\PostMoneyTransferAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Money\ReverseMoneyTransferAction;
use App\Actions\Money\TransitionCheckAction;
use App\Domain\Posting\DTO\PostingCommand;
use App\Models\Check;
use App\Models\PostingBatch;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;

/** Opaque request-local authority for the concrete canonical Phase 6 actions only. */
final class MoneyEventScope
{
    private ?MoneyEventCapability $active = null;

    private ?MoneyEventOwner $owner = null;

    private ?Connection $connection = null;

    private ?PDO $pdo = null;

    private ?DatabaseTransactionRecord $transaction = null;

    private int $companyId = 0;

    private int $actorId = 0;

    /** @var array<string, array<string, mixed>> */
    private array $records = [];

    private ?PostingCommand $command = null;

    private ?Check $linkedCheck = null;

    /** @var array<int, string> */
    private array $reversals = [];

    private function __clone() {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('Money event scope cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Money event scope cannot be restored.');
    }

    /**
     * @template T
     *
     * @param  Closure(MoneyEventCapability): T  $callback
     * @return T
     */
    public function within(MoneyEventOwner $owner, int $companyId, User $actor, Closure $callback): mixed
    {
        if ($this->active !== null || ! in_array($owner::class, [
            PostMoneyTransferAction::class,
            ReverseMoneyTransferAction::class,
            ReceiveCheckAction::class,
            IssueCheckAction::class,
            TransitionCheckAction::class,
        ], true) || ! $owner->ownsScope($this)) {
            throw new LogicException('Only an executing canonical Money action can enter a non-reentrant scope.');
        }
        $connection = DB::connection();
        $transaction = $this->transactions()->getPendingTransactions()->last(
            fn (DatabaseTransactionRecord $record) => $record->connection === $connection->getName() && $record->level === $connection->transactionLevel()
        );
        if ($transaction === null || ! $connection->getPdo()->inTransaction()) {
            throw new LogicException('An exact active canonical transaction is required.');
        }
        $this->active = $capability = new MoneyEventCapability;
        $this->owner = $owner;
        $this->connection = $connection;
        $this->pdo = $connection->getPdo();
        $this->transaction = $transaction;
        $this->companyId = $companyId;
        $this->actorId = (int) $actor->id;
        try {
            $this->assert($capability, $companyId);

            return $callback($capability);
        } finally {
            $this->active = null;
            $this->owner = null;
            $this->connection = null;
            $this->pdo = null;
            $this->transaction = null;
            $this->records = $this->reversals = [];
            $this->command = null;
            $this->linkedCheck = null;
            $this->companyId = $this->actorId = 0;
        }
    }

    public function assert(?MoneyEventCapability $capability, int $companyId): void
    {
        $context = app(CompanyContext::class);
        if ($capability === null || $capability !== $this->active || $companyId !== $this->companyId
            || $this->owner === null || ! $this->owner->ownsScope($this)
            || ! $context->hasCompany() || $context->companyId() !== $companyId
            || (int) auth()->id() !== $this->actorId
            || $this->connection !== DB::connection() || $this->pdo !== $this->connection->getPdo()
            || ! $this->pdo->inTransaction() || $this->connection->transactionLevel() < $this->transaction?->level
            || ! $this->transactions()->getPendingTransactions()->contains(fn ($record) => $record === $this->transaction)) {
            throw new LogicException('Expired, forged or mismatched Money event capability.');
        }
    }

    /** @param array<string, mixed> $values */
    public function prepareRecord(MoneyEventCapability $capability, string $identity, array $values): void
    {
        $this->assert($capability, (int) $values['company_id']);
        if (isset($this->records[$identity])) {
            throw new LogicException('Record authorization is one-use.');
        }
        $this->records[$identity] = $values;
    }

    /** @param array<string, mixed> $values */
    public function consumeRecord(MoneyEventCapability $capability, string $identity, array $values): void
    {
        $this->assert($capability, (int) $values['company_id']);
        if (($this->records[$identity] ?? null) !== $values) {
            throw new LogicException('Exact canonical prepared record is required.');
        }
        unset($this->records[$identity]);
    }

    public function prepareCommand(MoneyEventCapability $capability, PostingCommand $command): void
    {
        $this->assert($capability, (int) $command->company->id);
        if ($this->command !== null) {
            throw new LogicException('Only one pending Money posting command is permitted.');
        }
        $this->command = $command;
    }

    public function assertCommand(PostingCommand $command): void
    {
        $this->assert($this->active, (int) $command->company->id);
        if ($this->command !== $command || (int) $command->postedBy?->id !== $this->actorId) {
            throw new LogicException('Canonical Money source requires its exact prepared command.');
        }
        $this->command = null;
    }

    public function prepareReversal(MoneyEventCapability $capability, PostingBatch $batch, string $date): void
    {
        $this->assert($capability, (int) $batch->company_id);
        $this->reversals[(int) $batch->id] = $date;
    }

    public function prepareCheck(MoneyEventCapability $capability, Check $check): void
    {
        $this->assert($capability, (int) $check->company_id);
        $this->linkedCheck = $check;
    }

    public function checkForPayment(int $checkId, int $companyId, User $actor, string $direction): Check
    {
        $this->assert($this->active, $companyId);
        $expected = $direction === 'incoming' ? ReceiveCheckAction::class : IssueCheckAction::class;
        if ($this->owner === null || $expected !== $this->owner::class || (int) $actor->id !== $this->actorId
            || $this->linkedCheck === null || (int) $this->linkedCheck->id !== $checkId || $this->linkedCheck->direction !== $direction) {
            throw new LogicException('Check settlement requires its executing canonical receive/issue action.');
        }

        return $this->linkedCheck;
    }

    public function prepareCheckPaymentCommand(PostingCommand $command, int $checkId, string $direction): void
    {
        $check = $this->checkForPayment($checkId, (int) $command->company->id, $command->postedBy, $direction);
        $table = match ($command->sourceType) {
            'customer_payment' => 'customer_payments',
            'vendor_payment' => 'vendor_payments',
            'expense' => 'expenses',
            'employee_advance' => 'employee_advances',
            'salary_payment' => 'salary_payments',
            default => throw new LogicException("Unsupported check payment command source type: {$command->sourceType}"),
        };
        if (! DB::table($table)->where('company_id', $this->companyId)->where('id', $command->sourceId)->where('check_id', $check->id)->whereNull('posting_batch_id')->exists()) {
            throw new LogicException('Check Payment command does not belong to the prepared instrument.');
        }
        $this->prepareCommand($this->active, $command);
    }

    public function assertCheckTransition(int $checkId, int $companyId, User $actor): void
    {
        $this->assert($this->active, $companyId);
        if (! $this->owner instanceof TransitionCheckAction || (int) $actor->id !== $this->actorId
            || (int) $this->linkedCheck?->id !== $checkId) {
            throw new LogicException('Check payment reversal requires its canonical lifecycle transition.');
        }
    }

    public function assertReversal(PostingBatch $batch, User $actor, ?string $date): void
    {
        $this->assert($this->active, (int) $batch->company_id);
        if ((int) $actor->id !== $this->actorId || ! isset($this->reversals[$batch->id]) || $this->reversals[$batch->id] !== $date) {
            throw new LogicException('Canonical Money reversal requires its prepared batch and business date.');
        }
        unset($this->reversals[$batch->id]);
    }

    private function transactions(): DatabaseTransactionsManager
    {
        return app('db.transactions');
    }
}
