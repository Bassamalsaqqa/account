<?php

declare(strict_types=1);

namespace App\Services\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared conservative Arabic search normalizer and safe LIKE helper.
 *
 * Ensures SQL column expressions and user search queries apply identical folding
 * for Arabic diacritics/tashkeel, tatweel, alef variants, taa marbuta, and alef maqsura.
 */
final class ArabicSearchNormalizer
{
    /** @var list<array{0: string, 1: string}> */
    private const array CHAR_REPLACEMENTS = [
        ['أ', 'ا'],
        ['إ', 'ا'],
        ['آ', 'ا'],
        ['ٱ', 'ا'],
        ['ة', 'ه'],
        ['ى', 'ي'],
    ];

    /** Strippable Arabic harakat / tatweel codepoints for SQLite fallback where REGEXP_REPLACE is absent. */
    private const array STRIPPABLE_CODEPOINTS = [
        "\u{0640}",
        "\u{064B}", "\u{064C}", "\u{064D}", "\u{064E}", "\u{064F}",
        "\u{0650}", "\u{0651}", "\u{0652}", "\u{0653}", "\u{0654}",
        "\u{0655}", "\u{0656}", "\u{0657}", "\u{0658}", "\u{0659}",
        "\u{065A}", "\u{065B}", "\u{065C}", "\u{065D}", "\u{065E}",
        "\u{065F}", "\u{0670}",
    ];

    public static function containsArabic(string $text): bool
    {
        return preg_match('/\p{Arabic}/u', $text) === 1;
    }

    public static function fold(string $text): string
    {
        $stripped = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;

        return strtr($stripped, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ٱ' => 'ا',
            'ة' => 'ه',
            'ى' => 'ي',
        ]);
    }

    public static function shouldApplyFolding(string $term): bool
    {
        return $term !== '' && (self::containsArabic($term) || self::fold($term) !== $term);
    }

    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    public static function sqlFoldColumn(string $column, ?string $driver = null): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?$/D', $column) !== 1) {
            throw new \InvalidArgumentException("Unsafe SQL column identifier [{$column}].");
        }

        $driver ??= DB::connection()->getDriverName();
        $expr = "COALESCE({$column}, '')";

        if ($driver === 'sqlite') {
            foreach (self::STRIPPABLE_CODEPOINTS as $char) {
                $expr = "REPLACE({$expr}, '{$char}', '')";
            }
        } else {
            // MariaDB 10.4+/11.x & MySQL 8+ UTF-8 regex class matching PHP fold()
            $expr = "REGEXP_REPLACE({$expr}, '[\u{064B}-\u{065F}\u{0670}\u{0640}]', '')";
        }

        foreach (self::CHAR_REPLACEMENTS as [$from, $to]) {
            $expr = "REPLACE({$expr}, '{$from}', '{$to}')";
        }

        return $expr;
    }

    /**
     * Apply conservative wildcard-escaped LIKE filtering across plain and Arabic-folded columns.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $plainCols
     * @param  list<string>  $arabicFoldCols
     * @param  (\Closure(Builder<TModel>): mixed)|null  $extraClause
     */
    public static function applySearchTerms(
        Builder $query,
        string $term,
        array $plainCols,
        array $arabicFoldCols = [],
        ?\Closure $extraClause = null
    ): void {
        $rawLike = '%'.self::escapeLike($term).'%';
        $useArabicFold = $arabicFoldCols !== [] && self::shouldApplyFolding($term);
        $foldedLike = $useArabicFold ? '%'.self::escapeLike(self::fold($term)).'%' : $rawLike;
        $foldedSqlCols = $useArabicFold
            ? array_map(static fn (string $col): string => self::sqlFoldColumn($col), $arabicFoldCols)
            : [];

        $query->where(function (Builder $q) use ($rawLike, $plainCols, $useArabicFold, $foldedLike, $foldedSqlCols, $extraClause): void {
            foreach ($plainCols as $index => $col) {
                if (preg_match('/^[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?$/D', $col) !== 1) {
                    throw new \InvalidArgumentException("Unsafe SQL column identifier [{$col}].");
                }
                if ($index === 0) {
                    $q->where($col, 'like', $rawLike);
                } else {
                    $q->orWhere($col, 'like', $rawLike);
                }
            }

            if ($useArabicFold) {
                foreach ($foldedSqlCols as $expr) {
                    $q->orWhereRaw("{$expr} LIKE ?", [$foldedLike]);
                }
            }

            if ($extraClause !== null) {
                $extraClause($q);
            }
        });
    }
}
