<?php

declare(strict_types=1);

namespace App\Services\Sales;

/** Select stored bilingual defaults; preserve deliberately authored descriptions verbatim. */
final class DocumentDescription
{
    public static function choose(?string $description, ?string $nameAr, ?string $nameEn, string $locale): string
    {
        if ($description !== null && $description !== '' && ! in_array($description, [$nameAr, $nameEn], true)) {
            return $description;
        }

        return ($locale === 'en' ? ($nameEn ?: $nameAr) : ($nameAr ?: $nameEn)) ?: ($description ?? '');
    }
}
