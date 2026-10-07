<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\PurchaseLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Exact acquisition value is authoritative; rounded unit cost is only a snapshot. */
final class PurchaseAcquisitionValue
{
    public function commercial(PurchaseLine $line, ?int $taxAccountId): BigDecimal
    {
        $value = BigDecimal::of($line->line_total_base)->minus($taxAccountId === null ? '0' : $line->line_tax_base);
        if ($value->isNegative()) {
            throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
        }

        return $value->toScale(6);
    }

    public function line(PurchaseLine $line, ?int $taxAccountId, bool $includeLanded = true): BigDecimal
    {
        $commercial = $this->commercial($line, $taxAccountId);
        if (! $includeLanded) {
            return $commercial;
        }

        $landed = BigDecimal::of($line->landed_cost_allocated_base ?? '0');

        return $commercial->plus($landed)->toScale(6);
    }

    public function unitCost(PurchaseLine $line, BigDecimal $value): string
    {
        return (string) $value->dividedBy($line->quantity_base, 6, RoundingMode::HALF_UP);
    }

    /** @return list<BigDecimal> */
    public function lots(PurchaseLine $line, BigDecimal $value): array
    {
        $lots = $line->lots;
        $quantity = BigDecimal::zero();
        $base = BigDecimal::zero();
        foreach ($lots as $lot) {
            $quantity = $quantity->plus($lot->quantity);
            $base = $base->plus($lot->quantity_base);
        }
        if ($lots->isEmpty() || ! $quantity->isEqualTo($line->quantity) || ! $base->isEqualTo($line->quantity_base)) {
            throw new InvalidArgumentException(__('purchasing.lots_must_be_complete'));
        }
        $values = [];
        $allocated = BigDecimal::zero();
        foreach ($lots as $index => $lot) {
            $part = $index === $lots->count() - 1 ? $value->minus($allocated)
                : $value->multipliedBy($lot->quantity_base)->dividedBy($line->quantity_base, 6, RoundingMode::HALF_UP);
            if ($part->isNegative()) {
                throw new InvalidArgumentException(__('purchasing.post_integrity_failed'));
            }
            $values[] = $part->toScale(6);
            $allocated = $allocated->plus($part);
        }

        return $values;
    }
}
