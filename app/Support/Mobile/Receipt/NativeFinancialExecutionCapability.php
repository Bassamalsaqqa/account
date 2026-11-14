<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt;

use BadMethodCallException;

/**
 * Scoped, single-use capability representing authenticated and qualified admission
 * inside MobileEventReceiptManager::recordOrReplay.
 *
 * Technical review constraint:
 * - Constructor is strictly private.
 * - No public static issue() method exists.
 * - Cannot be minted by container, factory, or public constructor.
 * - Minted ONLY inside fresh locked recordOrReplay admission callback via scoped manager registry.
 * - Bound to company, actor, device, token password epoch, event hash, active DB connection,
 *   root transaction depth, and invocation scope ID.
 * - Consumed once, non-reusable, non-serializable, and expires on return or rollback.
 */
final class NativeFinancialExecutionCapability
{
    /**
     * Private constructor: can only be instantiated internally by MobileEventReceiptManager admission.
     */
    private function __construct(
        public readonly int $companyId,
        public readonly string $companyPublicId,
        public readonly int $actorId,
        public readonly string $actorPublicId,
        public readonly string $deviceId,
        public readonly string $tokenPasswordEpoch,
        public readonly string $clientEventId,
        public readonly string $eventHash,
        public readonly int $currencyMinorUnits,
        public readonly string $connectionName,
        public readonly int $rootTransactionLevel,
        public readonly string $invocationScopeId
    ) {}

    /**
     * Serialization is strictly prohibited to prevent off-band token capture or replay.
     */
    public function __sleep(): array
    {
        throw new BadMethodCallException('NativeFinancialExecutionCapability cannot be serialized.');
    }

    public function __wakeup(): void
    {
        throw new BadMethodCallException('NativeFinancialExecutionCapability cannot be deserialized.');
    }

    public function __serialize(): array
    {
        throw new BadMethodCallException('NativeFinancialExecutionCapability cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new BadMethodCallException('NativeFinancialExecutionCapability cannot be deserialized.');
    }
}
