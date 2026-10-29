<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Application\Platform\TenantWriteAccess;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Posting\DTO\PostingCommand;
use App\Models\PostingBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Posting\AccountingPostingService;
use App\Services\Posting\AccountingReversalService;
use Closure;

trait OwnsStockEvent
{
    private ?StockEventScope $executingStockEvent = null;

    final public function ownsStockEvent(StockEventScope $scope): bool
    {
        return $this->executingStockEvent === $scope;
    }

    /** @template T
     * @param Closure(): T $callback
     * @return T */
    private function stockTransaction(int $companyId, int $sourceId, User $actor, Closure $callback): mixed
    {
        if ($this->executingStockEvent !== null) {
            throw new InvalidInventoryMovementException('Canonical stock events cannot be reentered.');
        }

        return app(TenantWriteAccess::class)->transaction($companyId, function () use ($companyId, $sourceId, $actor, $callback) {
            $scope = app(StockEventScope::class);
            $this->executingStockEvent = $scope;
            try {
                return $scope->within($this, $companyId, $sourceId, $actor, $callback);
            } finally {
                $this->executingStockEvent = null;
            }
        });
    }

    /** @return list<StockMovement> */
    private function recordStock(InventoryMovementService $service, StockMovementCommand $command): array
    {
        $capability = app(StockEventScope::class)->prepareMovement($this, $command);

        return $service->recordCanonicalEvent($command, $capability);
    }

    private function postStock(AccountingPostingService $service, PostingCommand $command): PostingBatch
    {
        app(StockEventScope::class)->preparePosting($this, $command);

        return $service->post($command);
    }

    private function reverseStock(AccountingReversalService $service, PostingBatch $batch, User $actor, ?string $reason = null, ?string $postingDate = null): PostingBatch
    {
        app(StockEventScope::class)->prepareReversal($this, $batch, $actor);

        return $service->reverse($batch, $actor, $reason, $postingDate);
    }
}
