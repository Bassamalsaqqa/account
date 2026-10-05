<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Models\User;
use App\Models\VendorPayment;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;

/** Request-local authority for the complete dependent-before-parent reversal. */
final class VendorPaymentReversalScope
{
    private ?VendorPaymentReversalCapability $active = null;

    private ?ReverseVendorPaymentAction $owner = null;

    private ?Connection $connection = null;

    private ?PDO $pdo = null;

    private ?DatabaseTransactionRecord $transaction = null;

    private int $companyId = 0;

    private int $paymentId = 0;

    private int $actorId = 0;

    /** @var list<int> */
    private array $events = [];

    private function __clone() {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Reversal scopes cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Reversal scopes cannot be restored.');
    }

    /**
     * @template T
     *
     * @param  list<int>  $events
     * @param  Closure(VendorPaymentReversalCapability): T  $callback
     * @return T
     */
    public function withinCanonicalReversal(ReverseVendorPaymentAction $owner, VendorPayment $payment, User $actor, array $events, Closure $callback): mixed
    {
        if ($this->active !== null || ! $owner->ownsReversalScope($this)) {
            throw new InvalidArgumentException('Only the executing canonical reversal may activate this scope.');
        }
        $connection = DB::connection();
        $transaction = app('db.transactions')->getPendingTransactions()->last(fn (DatabaseTransactionRecord $record) => $record->connection === $connection->getName() && $record->level === $connection->transactionLevel());
        if ($transaction === null || $connection->transactionLevel() === 0) {
            throw new InvalidArgumentException('Canonical reversal transaction required.');
        }
        $capability = new VendorPaymentReversalCapability;
        $this->active = $capability;
        $this->owner = $owner;
        $this->connection = $connection;
        $this->pdo = $connection->getPdo();
        $this->transaction = $transaction;
        $this->companyId = (int) $payment->company_id;
        $this->paymentId = (int) $payment->id;
        $this->actorId = (int) $actor->id;
        $this->events = $events;
        try {
            return $callback($capability);
        } finally {
            $this->active = null;
            $this->owner = null;
            $this->connection = null;
            $this->pdo = null;
            $this->transaction = null;
            $this->companyId = $this->paymentId = $this->actorId = 0;
            $this->events = [];
        }
    }

    public function isActive(?VendorPaymentReversalCapability $capability): bool
    {
        return $capability !== null && $this->active === $capability && $this->owner?->ownsReversalScope($this) === true
            && $this->connection === DB::connection() && $this->connection->transactionLevel() === $this->transaction?->level
            && $this->pdo === $this->connection->getPdo() && $this->pdo->inTransaction() === true
            && app('db.transactions')->getPendingTransactions()->contains(fn (DatabaseTransactionRecord $record) => $record === $this->transaction);
    }

    public function assertReversal(?VendorPaymentReversalCapability $capability, int $companyId, int $paymentId, int $actorId, ?int $eventId = null): void
    {
        $context = app(CompanyContext::class);
        if (! $this->isActive($capability) || $companyId !== $this->companyId || $paymentId !== $this->paymentId || $actorId !== $this->actorId
            || (int) auth()->id() !== $actorId || ! $context->hasCompany() || (int) $context->companyId() !== $companyId
            || ($eventId === null ? $this->events !== [] : ($this->events[0] ?? null) !== $eventId)) {
            throw new InvalidArgumentException('Exact active canonical dependent-before-parent reversal authority required.');
        }
    }

    public function completeEvent(VendorPaymentReversalCapability $capability, int $eventId): void
    {
        $this->assertReversal($capability, $this->companyId, $this->paymentId, $this->actorId, $eventId);
        array_shift($this->events);
    }
}
