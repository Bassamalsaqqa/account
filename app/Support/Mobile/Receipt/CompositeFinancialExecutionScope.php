<?php

declare(strict_types=1);

namespace App\Support\Mobile\Receipt;

use App\Support\Mobile\Receipt\Exceptions\SecurityException;
use LogicException;

/**
 * Request-local authority for executing composite financial multi-leg actions.
 * Guarantees that partial financial actions (invoice draft, invoice post, payment post)
 * cannot be invoked directly within an active native execution context unless authorized
 * under an active composite-scope permit.
 */
final class CompositeFinancialExecutionScope
{
    private static ?NativeFinancialExecutionCapability $activeCapability = null;

    private static int $activeCompanyId = 0;

    private static int $activeActorId = 0;

    /**
     * Activates composite-scope permit for the designated capability, company, and actor.
     * Accessible exclusively to closed canonical multi-leg action boundaries via private binding.
     *
     * @phpstan-ignore method.unused
     */
    private static function activatePermit(
        NativeFinancialExecutionCapability $capability,
        int $companyId,
        int $actorId
    ): void {
        if (self::$activeCapability !== null) {
            throw new LogicException('CompositeFinancialExecutionScope does not support nested composite permits.');
        }

        self::$activeCapability = $capability;
        self::$activeCompanyId = $companyId;
        self::$activeActorId = $actorId;
    }

    /**
     * Deactivates the currently active composite-scope permit.
     *
     * @phpstan-ignore method.unused
     */
    private static function deactivatePermit(): void
    {
        self::$activeCapability = null;
        self::$activeCompanyId = 0;
        self::$activeActorId = 0;
    }

    /**
     * Asserts that composite-scope permit is active for the given company and actor.
     */
    public static function assertActive(int $companyId, int $actorId): void
    {
        if (! self::isActive($companyId, $actorId)) {
            throw new SecurityException('Direct partial financial execution is prohibited in native mobile execution context without an active composite-scope permit.');
        }
    }

    /**
     * Checks if a composite permit is active for the specified company and actor.
     */
    public static function isActive(int $companyId, int $actorId): bool
    {
        return self::$activeCapability !== null
            && self::$activeCompanyId === $companyId
            && self::$activeActorId === $actorId;
    }

    /**
     * Guard partial action execution against unpermitted calls in native execution context.
     * Preserves legitimate web AR flows when native execution context is not active.
     */
    public static function guardPartialAction(int $companyId, int $actorId): void
    {
        if (MobileEventReceiptManager::isNativeExecutionContextActive()) {
            self::assertActive($companyId, $actorId);
        }
    }
}
