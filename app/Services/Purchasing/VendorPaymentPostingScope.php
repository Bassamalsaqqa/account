<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PDO;

/** Request-local authority for one executing canonical Vendor Payment post. */
final class VendorPaymentPostingScope
{
    private function __clone() {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('Vendor payment posting scopes cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Vendor payment posting scopes cannot be restored.');
    }

    private ?VendorPaymentPostingCapability $active = null;

    private ?PostVendorPaymentAction $owner = null;

    private ?Connection $connection = null;

    private ?PDO $pdo = null;

    private ?DatabaseTransactionRecord $transaction = null;

    private int $companyId = 0;

    private int $vendorId = 0;

    private int $actorId = 0;

    private string $paymentNumber = '';

    /** @var array<int, array<string, mixed>> */
    private array $pendingAllocations = [];

    /**
     * @template T
     *
     * @param  list<array<string, mixed>>  $preparedAllocations
     * @param  Closure(VendorPaymentPostingCapability): T  $callback
     * @return T
     */
    public function withinCanonicalPaymentPosting(
        PostVendorPaymentAction $owner,
        int $companyId,
        int $vendorId,
        User $actor,
        string $paymentNumber,
        array $preparedAllocations,
        Closure $callback
    ): mixed {
        if ($this->active !== null || ! $owner->ownsPostingScope($this)) {
            throw new InvalidArgumentException('Only the executing canonical Vendor Payment action may activate a non-reentrant posting scope.');
        }

        $connection = DB::connection();
        $transaction = $this->transactions()->getPendingTransactions()->last(
            fn (DatabaseTransactionRecord $record) => $record->connection === $connection->getName()
                && $record->level === $connection->transactionLevel()
        );

        if ($connection->transactionLevel() === 0 || $transaction === null) {
            throw new InvalidArgumentException('Vendor payment scope requires its active canonical database transaction.');
        }

        $capability = new VendorPaymentPostingCapability;
        $this->active = $capability;
        $this->owner = $owner;
        $this->connection = $connection;
        $this->pdo = $connection->getPdo();
        $this->transaction = $transaction;
        $this->companyId = $companyId;
        $this->vendorId = $vendorId;
        $this->actorId = (int) $actor->id;
        $this->paymentNumber = $paymentNumber;

        $this->pendingAllocations = [];
        foreach ($preparedAllocations as $alloc) {
            $purId = (int) ($alloc['purchase_id'] ?? 0);
            $this->pendingAllocations[$purId] = $alloc;
        }

        try {
            return $callback($capability);
        } finally {
            $this->active = null;
            $this->owner = null;
            $this->connection = null;
            $this->pdo = null;
            $this->transaction = null;
            $this->companyId = $this->vendorId = $this->actorId = 0;
            $this->paymentNumber = '';
            $this->pendingAllocations = [];
        }
    }

    public function isActive(?VendorPaymentPostingCapability $capability): bool
    {
        if ($capability === null || $this->connection === null || $this->pdo === null) {
            return false;
        }

        return $this->active === $capability
            && $this->owner?->ownsPostingScope($this) === true
            && $this->connection === DB::connection()
            && $this->connection->transactionLevel() === $this->transaction?->level
            && $this->pdo === $this->connection->getPdo()
            && $this->pdo->inTransaction()
            && $this->transactions()->getPendingTransactions()->contains(
                fn (DatabaseTransactionRecord $record) => $record === $this->transaction
            );
    }

    public function assertPosting(?VendorPaymentPostingCapability $capability, int $companyId, int $vendorId, int $actorId, ?string $paymentNumber = null): void
    {
        $context = app(CompanyContext::class);
        if ($capability === null || ! $this->isActive($capability)
            || $this->companyId !== $companyId || $this->vendorId !== $vendorId || $this->actorId !== $actorId
            || (int) auth()->id() !== $actorId
            || ! $context->hasCompany() || (int) $context->companyId() !== $companyId) {
            throw new InvalidArgumentException('Vendor payment requires the active matching canonical posting capability.');
        }

        if ($paymentNumber !== null && $this->paymentNumber !== '' && $this->paymentNumber !== $paymentNumber) {
            throw new InvalidArgumentException('Vendor payment number does not match active canonical posting scope.');
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function consumePreparedAllocation(array $values): bool
    {
        $purId = (int) ($values['purchase_id'] ?? 0);
        if (! isset($this->pendingAllocations[$purId])) {
            return false;
        }

        $expected = $this->pendingAllocations[$purId];
        if ($expected !== $values) {
            return false;
        }

        unset($this->pendingAllocations[$purId]);

        return true;
    }

    private function transactions(): DatabaseTransactionsManager
    {
        return app('db.transactions');
    }
}
