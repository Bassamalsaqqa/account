<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Tenant-local speech candidate matcher for transaction proposal resolution.
 *
 * Matching precedence:
 * 1. Exact identifier/barcode/code/name (direct SQL query, available beyond scan cap).
 * 2. Normalized orthographic match (Unicode digits, tatweel, diacritics, alef variants, case/whitespace).
 *    Collisions are treated as ambiguity requiring explicit choice.
 * 3. Bounded fuzzy near-name scan (bounded by FINITE_SCAN_CAP; refused when candidate pool exceeds cap).
 *    Never silently auto-selects a fuzzy candidate even if only one match exists.
 *
 * Unit matching:
 * - Direct/normalized match against active product units.
 * - Semantic piece/carton spoken variant mapping against active product units only.
 * - Ambiguous units ask rather than guessing.
 * - Barcode unit conflicts preserved.
 */
final class TenantSpeechCandidates
{
    public const int FINITE_SCAN_CAP = 100;

    public const int MAX_CANDIDATES = 5;

    /** @var list<string> */
    private const array PIECE_VARIANTS = [
        'piece', 'pieces', 'pc', 'pcs', 'unit', 'units',
        'حبة', 'حبه', 'حبات', 'قطعة', 'قطعه', 'قطع', 'عدد',
        'بالحبة', 'بالحبه', 'بالقطعة', 'بالقطعه', 'مفرد',
    ];

    /** @var list<string> */
    private const array CARTON_VARIANTS = [
        'carton', 'cartons', 'ctn', 'box', 'boxes',
        'كرتونة', 'كرتونه', 'كرتون', 'كراتين',
        'صندوق', 'صناديق', 'باكيت', 'بكيت', 'بكيتات',
        'بالكرتونة', 'بالكرتون',
    ];

    /**
     * Conservative Arabic and English normalization:
     * - Eastern Arabic & Persian digits mapped to ASCII [0-9], Arabic decimal comma to dot.
     * - Arabic tatweel (U+0640) stripped.
     * - Arabic diacritics/tashkeel (U+064B-U+065F, U+0670) stripped.
     * - Arabic Alef variants (أ, إ, آ, ٱ) unified to bare Alef (ا).
     * - Arabic Taa Marbuta (ة) unified to Haa (ه) and Alef Maqsura (ى) to Yaa (ي).
     * - English case lowercased via mb_strtolower.
     * - Consecutive whitespace collapsed and trimmed.
     */
    public static function normalize(string $text): string
    {
        // 1. Digits & decimal comma
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.',
        ]);

        // 2. Arabic tatweel (kashida U+0640)
        $text = preg_replace('/\x{0640}/u', '', $text) ?? $text;

        // 3. Arabic diacritics / harakat (U+064B - U+065F, U+0670)
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;

        // 4. Arabic Alef variants & letters
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه',
            'ى' => 'ي',
        ]);

        // 5. Case and whitespace
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Multibyte character-aware Levenshtein distance.
     */
    public static function mbLevenshtein(string $str1, string $str2): int
    {
        $s1 = mb_str_split($str1);
        $s2 = mb_str_split($str2);
        $l1 = count($s1);
        $l2 = count($s2);

        if ($l1 === 0) {
            return $l2;
        }
        if ($l2 === 0) {
            return $l1;
        }

        $prev = range(0, $l2);
        for ($i = 0; $i < $l1; $i++) {
            $curr = [$i + 1];
            for ($j = 0; $j < $l2; $j++) {
                $cost = ($s1[$i] === $s2[$j]) ? 0 : 1;
                $curr[$j + 1] = min($curr[$j] + 1, $prev[$j + 1] + 1, $prev[$j] + $cost);
            }
            $prev = $curr;
        }

        return $prev[$l2];
    }

    /**
     * Computes fuzzy score for near-name / minor typo matching.
     * Returns integer score (lower is better; null if no match).
     */
    public static function fuzzyScore(string $normRef, string $normVal): ?int
    {
        if ($normRef === '' || $normVal === '') {
            return null;
        }

        $lenRef = mb_strlen($normRef);
        $lenVal = mb_strlen($normVal);
        $maxLen = max($lenRef, $lenVal);

        if ($normRef === $normVal) {
            return 0;
        }

        $dist = self::mbLevenshtein($normRef, $normVal);

        $allowedDist = match (true) {
            $maxLen <= 2 => 0,
            $maxLen <= 4 => 1,
            $maxLen <= 8 => 2,
            default => 3,
        };

        if ($dist <= $allowedDist) {
            return $dist;
        }

        if ($lenRef >= 3 && str_contains($normVal, $normRef)) {
            return 10 + ($lenVal - $lenRef);
        }

        if ($lenVal >= 3 && str_contains($normRef, $normVal)) {
            return 20 + ($lenRef - $lenVal);
        }

        return null;
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  list<string>  $columns
     * @param  array<string, int>  $choices
     * @param  list<array<string, mixed>>  $questions
     * @return T|null
     */
    public static function resolveEntity(
        Builder $query,
        ?string $reference,
        array $columns,
        string $path,
        array $choices,
        array &$questions,
        ?int $barcodeCompanyId = null,
    ): ?Model {
        if ($reference === null) {
            $candidateRows = (clone $query)->orderBy('id')->limit(self::MAX_CANDIDATES)->get();
            if (isset($choices[$path])) {
                $chosen = $candidateRows->first(fn ($row) => (int) $row->getKey() === $choices[$path]);
                if ($chosen !== null) {
                    return $chosen;
                }
            }
            $questions[] = [
                'path' => $path,
                'candidates' => self::formatCandidates($candidateRows),
            ];

            return null;
        }

        $normalizedDigits = app(SalesProposalSchema::class)->normalizeDigits($reference);

        // Tier 1: Exact identifier / code / SKU / barcode / exact name match (direct SQL)
        $exactMatches = (clone $query)->where(function ($q) use ($columns, $reference, $barcodeCompanyId, $normalizedDigits): void {
            foreach ($columns as $column) {
                $q->orWhere($column, $reference);
            }
            if ($barcodeCompanyId !== null) {
                $q->orWhereHas('barcodes', fn ($barcodes) => $barcodes->where('company_id', $barcodeCompanyId)
                    ->where('barcode', $normalizedDigits));
            }
        })->orderBy('id')->limit(6)->get();

        if ($exactMatches->count() === 1) {
            return $exactMatches->first();
        }

        if ($exactMatches->count() > 1) {
            // Collision between exact identifiers (e.g. SKU and barcode collision, or 2 identical names)
            $candidates = $exactMatches->take(self::MAX_CANDIDATES);
            if (isset($choices[$path]) && $candidates->contains(fn ($r) => (int) $r->getKey() === $choices[$path])) {
                return $candidates->first(fn ($r) => (int) $r->getKey() === $choices[$path]);
            }
            $questions[] = [
                'path' => $path,
                'candidates' => self::formatCandidates($candidates),
            ];

            return null;
        }

        // Tier 2 & 3: Check scan cap before in-memory normalized and fuzzy scanning
        $scanRows = (clone $query)
            ->when($barcodeCompanyId !== null, fn ($q) => $q->with(['barcodes' => fn ($b) => $b->where('company_id', $barcodeCompanyId)]))
            ->orderBy('id')
            ->limit(self::FINITE_SCAN_CAP + 1)
            ->get();

        if ($scanRows->count() > self::FINITE_SCAN_CAP) {
            // Refusal on exceeding finite scan cap (exact matching remains available beyond cap)
            $questions[] = [
                'path' => $path,
                'candidates' => [],
            ];

            return null;
        }

        $normRef = self::normalize($reference);

        // Tier 2: Normalized exact match (Arabic alef/tatweel/diacritics, English case/whitespace)
        $normalizedMatches = $scanRows->filter(function ($row) use ($columns, $normRef, $barcodeCompanyId): bool {
            foreach ($columns as $column) {
                $val = $row->getAttribute($column);
                if ($val !== null && self::normalize((string) $val) === $normRef) {
                    return true;
                }
            }
            if ($barcodeCompanyId !== null && $row instanceof Product && $row->relationLoaded('barcodes')) {
                foreach ($row->barcodes as $barcode) {
                    if (self::normalize((string) $barcode->barcode) === $normRef) {
                        return true;
                    }
                }
            }

            return false;
        });

        if ($normalizedMatches->count() === 1) {
            return $normalizedMatches->first();
        }

        if ($normalizedMatches->count() > 1) {
            // Normalized collision: ambiguity requiring explicit choice
            $candidates = $normalizedMatches->take(self::MAX_CANDIDATES);
            if (isset($choices[$path]) && $candidates->contains(fn ($r) => (int) $r->getKey() === $choices[$path])) {
                return $candidates->first(fn ($r) => (int) $r->getKey() === $choices[$path]);
            }
            $questions[] = [
                'path' => $path,
                'candidates' => self::formatCandidates($candidates),
            ];

            return null;
        }

        // Tier 3: Fuzzy / near-name candidates
        $scored = [];
        foreach ($scanRows as $row) {
            $bestScore = null;
            foreach ($columns as $column) {
                $val = $row->getAttribute($column);
                if ($val === null || trim((string) $val) === '') {
                    continue;
                }
                $normVal = self::normalize((string) $val);
                $score = self::fuzzyScore($normRef, $normVal);
                if ($score !== null && ($bestScore === null || $score < $bestScore)) {
                    $bestScore = $score;
                }
            }
            if ($barcodeCompanyId !== null && $row instanceof Product && $row->relationLoaded('barcodes')) {
                foreach ($row->barcodes as $barcode) {
                    $normBarcode = self::normalize((string) $barcode->barcode);
                    $score = self::fuzzyScore($normRef, $normBarcode);
                    if ($score !== null && ($bestScore === null || $score < $bestScore)) {
                        $bestScore = $score;
                    }
                }
            }
            if ($bestScore !== null) {
                $scored[] = ['model' => $row, 'score' => $bestScore, 'id' => (int) $row->getKey()];
            }
        }

        usort($scored, fn ($a, $b) => ($a['score'] <=> $b['score']) ?: ($a['id'] <=> $b['id']));

        $fuzzyCandidates = collect(array_slice($scored, 0, self::MAX_CANDIDATES))->pluck('model');

        if ($fuzzyCandidates->isNotEmpty()) {
            // Never silently auto-select a fuzzy match even if only one candidate exists!
            if (isset($choices[$path]) && $fuzzyCandidates->contains(fn ($r) => (int) $r->getKey() === $choices[$path])) {
                return $fuzzyCandidates->first(fn ($r) => (int) $r->getKey() === $choices[$path]);
            }

            $questions[] = [
                'path' => $path,
                'candidates' => self::formatCandidates($fuzzyCandidates),
            ];

            return null;
        }

        $questions[] = [
            'path' => $path,
            'candidates' => [],
        ];

        return null;
    }

    /**
     * @param  Collection<int, ProductUnit>  $units
     * @param  array<string, int>  $choices
     * @param  list<array<string, mixed>>  $questions
     */
    public static function resolveUnit(
        Collection $units,
        ?string $spokenUnit,
        ?ProductBarcode $barcode,
        string $path,
        array $choices,
        array &$questions,
    ): ?ProductUnit {
        $candidates = $units->take(self::MAX_CANDIDATES)->map(fn (ProductUnit $row) => [
            'value' => (int) $row->id,
            'label' => $row->unit->name(),
        ])->all();

        // Choice provided
        if (isset($choices[$path.'.unit']) && in_array($choices[$path.'.unit'], array_column($candidates, 'value'), true)) {
            $chosen = $units->firstWhere('id', $choices[$path.'.unit']);
            if ($chosen !== null) {
                // Barcode conflict check
                if ($barcode?->unit_id !== null && (int) $chosen->unit_id !== (int) $barcode->unit_id) {
                    $questions[] = ['path' => $path.'.unit', 'candidates' => $candidates];

                    return null;
                }

                return $chosen;
            }
        }

        // No spoken unit
        if ($spokenUnit === null) {
            if ($barcode?->unit_id !== null) {
                $matched = $units->first(fn (ProductUnit $u) => (int) $u->unit_id === (int) $barcode->unit_id);
                if ($matched !== null) {
                    return $matched;
                }
            } elseif ($units->count() === 1) {
                return $units->first();
            }

            $questions[] = ['path' => $path.'.unit', 'candidates' => $candidates];

            return null;
        }

        $normSpoken = self::normalize($spokenUnit);

        // Tier 1: Direct case-insensitive match
        $matches = $units->filter(function (ProductUnit $row) use ($spokenUnit): bool {
            $names = [
                $row->unit->code,
                $row->unit->name_ar,
                $row->unit->name_en,
                $row->unit->symbol_ar,
                $row->unit->symbol_en,
            ];

            return in_array(mb_strtolower($spokenUnit), array_map(fn ($n) => mb_strtolower((string) $n), $names), true);
        });

        // Tier 2: Normalized match
        if ($matches->isEmpty()) {
            $matches = $units->filter(function (ProductUnit $row) use ($normSpoken): bool {
                $names = [
                    $row->unit->code,
                    $row->unit->name_ar,
                    $row->unit->name_en,
                    $row->unit->symbol_ar,
                    $row->unit->symbol_en,
                ];
                foreach ($names as $name) {
                    if ($name !== null && self::normalize((string) $name) === $normSpoken) {
                        return true;
                    }
                }

                return false;
            });
        }

        // Tier 3: Semantic piece / carton spoken variant match against product's active units only
        if ($matches->isEmpty()) {
            $isPieceSpoken = in_array($normSpoken, array_map([self::class, 'normalize'], self::PIECE_VARIANTS), true);
            $isCartonSpoken = in_array($normSpoken, array_map([self::class, 'normalize'], self::CARTON_VARIANTS), true);

            if ($isPieceSpoken) {
                $matches = $units->filter(fn (ProductUnit $row) => self::unitBelongsToGroup($row, self::PIECE_VARIANTS));
            } elseif ($isCartonSpoken) {
                $matches = $units->filter(fn (ProductUnit $row) => self::unitBelongsToGroup($row, self::CARTON_VARIANTS));
            }
        }

        $unit = $matches->count() === 1 ? $matches->first() : null;

        // Barcode conflict check
        if ($unit === null || ($barcode?->unit_id !== null && (int) $unit->unit_id !== (int) $barcode->unit_id)) {
            $unit = null;
            $questions[] = ['path' => $path.'.unit', 'candidates' => $candidates];
        }

        return $unit;
    }

    /**
     * @param  list<string>  $groupVariants
     */
    private static function unitBelongsToGroup(ProductUnit $productUnit, array $groupVariants): bool
    {
        $normalizedGroup = array_map([self::class, 'normalize'], $groupVariants);
        $unitNames = [
            $productUnit->unit->code,
            $productUnit->unit->name_ar,
            $productUnit->unit->name_en,
            $productUnit->unit->symbol_ar,
            $productUnit->unit->symbol_en,
        ];
        foreach ($unitNames as $name) {
            if ($name !== null && in_array(self::normalize((string) $name), $normalizedGroup, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T of Model
     *
     * @param  Collection<array-key, T>  $models
     * @return list<array{value: int, label: string}>
     */
    public static function formatCandidates(Collection $models): array
    {
        return $models->take(self::MAX_CANDIDATES)->values()->map(fn ($row) => [
            'value' => (int) $row->getKey(),
            'label' => (string) ($row->getAttribute('name_ar') ?? $row->getAttribute('name_en') ?? $row->getAttribute('sku') ?? $row->getAttribute('code')),
        ])->all();
    }
}
