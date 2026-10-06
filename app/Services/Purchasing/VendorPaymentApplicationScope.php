<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PDO;

/** Request-local authority for one executing canonical Vendor Payment advance application. */
final class VendorPaymentApplicationScope
{
    private function __clone() {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('Vendor payment application scopes cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Vendor payment application scopes cannot be restored.');
    }

    private ?VendorPaymentApplicationCapability $active = null;

    private ?ApplyVendorPaymentCreditAction $owner = null;

    private ?Connection $connection = null;

    private ?PDO $pdo = null;

    private ?DatabaseTransactionRecord $transaction = null;

    private int $companyId = 0;

    private int $paymentId = 0;

    private int $actorId = 0;

    /** @var array<int, array<string, mixed>> */
    private array $pendingAllocations = [];

    private bool $hasNonzeroFx = false;

    /**
     * @template T
     *
     * @param  list<array<string, mixed>>  $preparedAllocations
     * @param  Closure(VendorPaymentApplicationCapability): T  $callback
     * @return T
     */
    public function withinCanonicalApplication(
        ApplyVendorPaymentCreditAction $owner,
        int $companyId,
        int $paymentId,
        User $actor,
        array $preparedAllocations,
        Closure $callback
    ): mixed {
        if ($this->active !== null || ! $owner->ownsApplicationScope($this)) {
            throw new InvalidArgumentException('Only the executing canonical Apply action may activate a non-reentrant application scope.');
        }

        $connection = DB::connection();
        $transaction = $this->transactions()->getPendingTransactions()->last(
            fn (DatabaseTransactionRecord $record) => $record->connection === $connection->getName()
                && $record->level === $connection->transactionLevel()
        );

        if ($connection->transactionLevel() === 0 || $transaction === null) {
            throw new InvalidArgumentException('Vendor payment application scope requires its active canonical database transaction.');
        }

        $capability = new VendorPaymentApplicationCapability;
        $this->active = $capability;
        $this->owner = $owner;
        $this->connection = $connection;
        $this->pdo = $connection->getPdo();
        $this->transaction = $transaction;
        $this->companyId = $companyId;
        $this->paymentId = $paymentId;
        $this->actorId = (int) $actor->id;

        $this->pendingAllocations = [];
        $hasNonzero = false;
        foreach ($preparedAllocations as $alloc) {
            $purId = (int) ($alloc['purchase_id'] ?? 0);
            $this->pendingAllocations[$purId] = $alloc;
            if (! BigDecimal::of((string) ($alloc['realized_fx_gain_loss_base'] ?? '0'))->isZero()) {
                $hasNonzero = true;
            }
        }
        $this->hasNonzeroFx = $hasNonzero;

        try {
            return $callback($capability);
        } finally {
            $this->active = null;
            $this->owner = null;
            $this->connection = null;
            $this->pdo = null;
            $this->transaction = null;
            $this->companyId = $this->paymentId = $this->actorId = 0;
            $this->pendingAllocations = [];
            $this->hasNonzeroFx = false;
        }
    }

    public function isActive(?VendorPaymentApplicationCapability $capability): bool
    {
        if ($capability === null || $this->connection === null || $this->pdo === null) {
            return false;
        }

        return $this->active === $capability
            && $this->owner?->ownsApplicationScope($this) === true
            && $this->connection === DB::connection()
            && $this->connection->transactionLevel() === $this->transaction?->level
            && $this->pdo === $this->connection->getPdo()
            && $this->pdo->inTransaction()
            && $this->transactions()->getPendingTransactions()->contains(
                fn (DatabaseTransactionRecord $record) => $record === $this->transaction
            );
    }

    public function assertApplication(?VendorPaymentApplicationCapability $capability, int $companyId, int $paymentId, int $actorId): void
    {
        $context = app(CompanyContext::class);
        if ($capability === null || ! $this->isActive($capability)
            || $this->companyId !== $companyId || $this->paymentId !== $paymentId || $this->actorId !== $actorId
            || (int) auth()->id() !== $actorId
            || ! $context->hasCompany() || (int) $context->companyId() !== $companyId) {
            throw new InvalidArgumentException('Vendor payment application requires active matching canonical application capability.');
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

    public function hasNonzeroFinancialEffect(): bool
    {
        return $this->hasNonzeroFx;
    }

    private function transactions(): DatabaseTransactionsManager
    {
        return app('db.transactions');
    }
}
