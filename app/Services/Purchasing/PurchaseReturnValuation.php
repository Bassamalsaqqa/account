<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class PurchaseReturnValuationResult
{
    public function __construct(
        public BigDecimal $newCompanyQty,
        public BigDecimal $newCompanyVal,
        public BigDecimal $newCompanyAvg,
        public BigDecimal $actualRemoved,
    ) {}
}

/** Pure deterministic moving-average revaluation for purchase returns. */
final class PurchaseReturnValuation
{
    public static function compute(
        BigDecimal $oldCompanyQty,
        BigDecimal $oldCompanyVal,
        BigDecimal $returnQty,
        BigDecimal $historicalTarget
    ): PurchaseReturnValuationResult {
        if ($returnQty->isLessThanOrEqualTo(0)) {
            throw new \InvalidArgumentException('Return quantity must be strictly positive.');
        }

        $newQty = $oldCompanyQty->minus($returnQty);
        if ($newQty->isNegative()) {
            throw InsufficientStockException::forCompany(
                0,
                (string) $returnQty->toScale(6, RoundingMode::HALF_UP),
                (string) $oldCompanyQty->toScale(6, RoundingMode::HALF_UP)
            );
        }

        if ($newQty->isZero()) {
            // Full depletion: movement value equals exact remaining value in cost state
            // This clears accumulated valuation residual.
            $actualRemoved = $oldCompanyVal;
            $newVal = BigDecimal::zero();
            $newAvg = BigDecimal::zero();
        } else {
            // Partial depletion: require historical target <= old value
            if ($historicalTarget->isGreaterThan($oldCompanyVal)) {
                throw new InvalidInventoryMovementException('Historical receipt target exceeds current inventory carrying value.');
            }
            $actualRemoved = $historicalTarget;
            $newVal = $oldCompanyVal->minus($historicalTarget);
            $newAvg = $newVal->isZero()
                ? BigDecimal::zero()
                : $newVal->dividedBy($newQty, 6, RoundingMode::HALF_UP);
        }

        return new PurchaseReturnValuationResult(
            newCompanyQty: $newQty->toScale(6),
            newCompanyVal: $newVal->toScale(6),
            newCompanyAvg: $newAvg->toScale(6),
            actualRemoved: $actualRemoved->toScale(6),
        );
    }
}
