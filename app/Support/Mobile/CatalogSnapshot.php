<?php

declare(strict_types=1);

namespace App\Support\Mobile;

final class CatalogSnapshot
{
    /** Only valid after verifying that the authorized company catalog is empty. */
    public static function emptyVersion(): string
    {
        return hash('sha256', '[]');
    }
}
