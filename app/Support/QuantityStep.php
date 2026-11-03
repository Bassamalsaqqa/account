<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Unit;

final class QuantityStep
{
    /** Presentation quantum; canonical writers independently recheck the unit.
     * @param  list<string>  $historicalQuantities
     */
    public static function forUnit(?Unit $unit, array $historicalQuantities = []): string
    {
        $scale = $unit?->allows_fraction ? min(6, max(0, (int) $unit->decimal_places)) : 0;
        foreach ($historicalQuantities as $quantity) {
            $fraction = explode('.', $quantity)[1] ?? '';
            $scale = max($scale, min(6, strlen(rtrim($fraction, '0'))));
        }

        return $scale > 0 ? '0.'.str_repeat('0', $scale - 1).'1' : '1';
    }
}
