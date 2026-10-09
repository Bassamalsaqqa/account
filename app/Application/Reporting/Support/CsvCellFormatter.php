<?php

declare(strict_types=1);

namespace App\Application\Reporting\Support;

use InvalidArgumentException;

/** Formats typed CSV cells without turning descriptive text into executable formulas. */
final class CsvCellFormatter
{
    public static function text(?string $value): string
    {
        $text = $value ?? '';
        $formula = preg_match('/^[\p{Z}\p{C}\s]*[=+\-@]/u', $text);
        if ($formula === false) {
            throw new InvalidArgumentException('CSV text must be valid UTF-8.');
        }

        return $formula === 1 ? "'".$text : $text;
    }

    /** Monetary/quantity columns are declared numeric by the report column contract. */
    public static function decimal(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (preg_match('/^-?\d+(?:\.\d+)?$/D', $value) !== 1) {
            throw new InvalidArgumentException('CSV numeric cells require an exact decimal string.');
        }

        return $value;
    }
}
