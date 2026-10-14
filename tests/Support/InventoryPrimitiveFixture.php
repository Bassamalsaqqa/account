<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryMovementService;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;

/**
 * Guarded test-only fixture for primitive inventory engine tests.
 *
 * Uses ReflectionMethod on private recordMovement on InventoryMovementService.
 * Gated strictly to APP_ENV=testing and DisposableMariaDbSchema::assertPrimarySchema.
 * NEVER use this helper in canonical financial acceptance or claim orphan stock healthy.
 */
final class InventoryPrimitiveFixture
{
    /**
     * Enforce testing environment and disposable MariaDB schema ownership proof.
     */
    public static function assertGates(): void
    {
        if (app()->environment() !== 'testing' && getenv('APP_ENV') !== 'testing') {
            throw new RuntimeException('InventoryPrimitiveFixture is restricted to APP_ENV=testing.');
        }

        $database = (string) DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($database);
    }

    /**
     * Record movement via reflection directly on the private recordMovement method.
     *
     * @return list<StockMovement>
     */
    public static function recordMovement(InventoryMovementService $service, StockMovementCommand $command): array
    {
        self::assertGates();

        $ref = new ReflectionMethod(InventoryMovementService::class, 'recordMovement');
        $ref->setAccessible(true);

        /** @var list<StockMovement> */
        return $ref->invoke($service, $command);
    }

    /**
     * Test helper call alias for recordMovement.
     *
     * @return list<StockMovement>
     */
    public static function record(InventoryMovementService $service, StockMovementCommand $command): array
    {
        return self::recordMovement($service, $command);
    }

    /**
     * Create a test-only subclass of InventoryMovementService that overrides record()
     * for genuine primitive engine tests without modifying app container bindings.
     */
    public static function createService(): InventoryMovementService
    {
        return new class extends InventoryMovementService
        {
            /**
             * @return list<StockMovement>
             */
            public function record(StockMovementCommand $command): array
            {
                return InventoryPrimitiveFixture::recordMovement($this, $command);
            }
        };
    }
}
