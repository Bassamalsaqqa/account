<?php

declare(strict_types=1);

namespace App\Support;

final class TablePageSize
{
    public const DEFAULT = 50;

    public const OPTIONS = [50, 100, 200];

    public static function resolve(mixed $value): int
    {
        foreach (self::OPTIONS as $size) {
            if ($value === $size || $value === (string) $size) {
                return $size;
            }
        }

        return self::DEFAULT;
    }

    public static function fromRequest(): int
    {
        return self::resolve(request()->query('per_page', self::DEFAULT));
    }
}
