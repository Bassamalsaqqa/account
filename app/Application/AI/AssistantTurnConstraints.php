<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Reporting\DTO\ReportPeriod;
use App\Models\Company;

/** Preserve explicit request constraints without restricting the merchant's vocabulary. */
final class AssistantTurnConstraints
{
    /** @param list<array<string, mixed>> $proposals
     * @param  list<array<string, mixed>>  $prior
     */
    public function assert(string $text, array $proposals, Company $company, array $prior = []): void
    {
        $currency = app(AssistantDeterministicClassifier::class)->extractCurrencyFromText($text);
        // Recognize other common ISO currencies too: never turn an unsupported currency into the default.
        preg_match_all('/\b(?:ILS|NIS|USD|JOD|EUR|GBP|CAD|AUD|CHF|CNY|AED|SAR|KWD|BHD|EGP|QAR|TRY|JPY|INR)\b/i', $text, $codes);
        preg_match_all('/(?:\b(?:in|currency(?:\s+code)?)\s*[:=]?\s*|(?:بعملة|بالعملة|عملة)\s*[:=]?\s*)([A-Z]{3})\b/u', $text, $explicitCodes);
        $currencies = $currency === null ? [] : [$currency];
        foreach ([...$codes[0], ...$explicitCodes[1]] as $code) {
            $currencies[] = strtoupper($code) === 'NIS' ? 'ILS' : strtoupper($code);
        }
        $currencies = array_values(array_unique($currencies));
        if (count($currencies) > 1) {
            throw new AssistantUnavailable('question_invalid');
        }
        $currency = $currencies[0] ?? null;
        $normalized = $this->normalize($text);
        $presets = $this->presets($normalized);
        $comparison = preg_match('/\b(?:compar(?:e|ing|ison|ed)|versus|vs)\b|(?:^|\s)(?:قارن|وقارن|مقارنه)(?:\s|$)/u', $normalized) === 1;
        $previousYear = preg_match('/\b(?:previous|last)\s+year\b|(?:ال)?(?:سنه|عام)\s+(?:ال)?(?:ماضيه?|سابقه?)/u', $normalized) === 1;
        preg_match_all('/(?<![0-9])([1-9][0-9]{3}-[0-9]{2}-[0-9]{2})(?![0-9])/', $text, $dateMatches);
        $dates = $dateMatches[1];
        if ($dates !== [] && count($dates) !== 2) {
            throw new AssistantUnavailable('question_invalid');
        }
        if ($dates !== []) {
            try {
                ReportPeriod::custom($dates[0], $dates[1], $company);
            } catch (\InvalidArgumentException) {
                throw new AssistantUnavailable('question_invalid');
            }
        }
        foreach ($proposals as $proposal) {
            try {
                $filters = AssistantIntentProposal::fromArray($proposal)->filters;
            } catch (AssistantUnavailable) {
                throw new AssistantUnavailable('question_invalid');
            }
            $this->assertNames($normalized, $filters, $prior);
            if ($currency !== null && ($filters['currency_code'] ?? null) !== $currency) {
                throw new AssistantUnavailable('question_invalid');
            }
            if ($dates !== [] && (($filters['start_date'] ?? null) !== $dates[0] || ($filters['end_date'] ?? null) !== $dates[1])) {
                throw new AssistantUnavailable('question_invalid');
            }
            if ($comparison && ! isset($filters['compare'])) {
                throw new AssistantUnavailable('question_invalid');
            }
            if ($comparison && $previousYear && $filters['compare'] !== 'previous_year'
                && $presets !== ['this_year', 'last_year']) {
                throw new AssistantUnavailable('question_invalid');
            }
            $this->assertPeriods($presets, $filters, $company);
        }
        if ($proposals === [] && ($currency !== null || $dates !== [] || $presets !== [] || $comparison)) {
            throw new AssistantUnavailable('question_invalid');
        }
    }

    /** @param array<string, string> $filters
     * @param  list<array<string, mixed>>  $prior
     */
    private function assertNames(string $text, array $filters, array $prior): void
    {
        foreach (AssistantIntentProposal::NAME_FILTERS as $key) {
            if (! isset($filters[$key])) {
                continue;
            }
            $name = $this->normalize($filters[$key]);
            if ($this->containsName($text, $name)) {
                continue;
            }
            $inPrior = false;
            foreach ($prior as $proposal) {
                $priorName = $proposal['filters'][$key] ?? null;
                if (is_string($priorName) && $this->normalize($priorName) === $name) {
                    $inPrior = true;
                    break;
                }
            }
            if (! $inPrior) {
                throw new AssistantUnavailable('question_invalid');
            }
        }
        // Only explicit entity labels are recognized here; unfamiliar vocabulary is not a refusal.
        $labels = [
            'customer_name' => ['customer', 'client', 'للزبون', 'للعميل', 'الزبون', 'العميل'],
            'vendor_name' => ['vendor', 'supplier', 'للمورد', 'المورد'],
            'product_name' => ['product', 'item', 'للصنف', 'الصنف'],
            'warehouse_name' => ['warehouse', 'للمستودع', 'المستودع'],
            'category_name' => ['category', 'للفئه', 'الفئه'],
        ];
        foreach ($labels as $key => $words) {
            $english = [];
            $arabic = [];
            foreach ($words as $word) {
                if (preg_match('/\A[a-z]+\z/', $word)) {
                    $english[] = preg_quote($word, '/');
                } else {
                    $arabic[] = preg_quote($word, '/');
                }
            }
            $label = '(?:(?:for\s+(?:the\s+)?)(?:'.implode('|', $english).')|(?:'.implode('|', $english).')\s*[:=]|(?:'.implode('|', $arabic).')\s*[:=]?)';
            $quotedLabel = '(?:'.implode('|', [...$english, ...$arabic]).')';
            preg_match_all('/(?<!\p{L})(?:'.$quotedLabel.'\s*[:=]?\s*["“\']([^"”\']+)["”\']|'.$label.'\s+["“\']?([\p{L}\p{N}][\p{L}\p{N}_.-]*))/u', $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $requested = ($match[1] ?? '') !== '' ? $match[1] : $match[2];
                if (! isset($filters[$key]) || ! $this->containsName($this->normalize($filters[$key]), $requested)) {
                    throw new AssistantUnavailable('question_invalid');
                }
            }
        }
    }

    private function containsName(string $text, string $name): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $text) === 1;
    }

    public function isReadInvoiceQuestion(string $text): bool
    {
        $text = $this->normalize($text);
        if (preg_match('/\b(?:create|issue|draft|prepare|post|save|make|generate|new)\b|(?:^|\s)(?:اعمل|واعمل|جهز|وجهز|انشئ|وانشئ|اصدر|واصدر|رحل|حضر|افتح)(?:\s|$)|فاتوره\s+(?:جديده)/u', $text)) {
            return false;
        }

        return preg_match('/\b(?:last|latest|most\s+recent)\s+(?:(?:sales|purchase)\s+)?invoice\b|(?:اخر|احدث)\s+فاتوره/u', $text) === 1;
    }

    /** @param list<string> $presets
     * @param  array<string, string>  $filters
     */
    private function assertPeriods(array $presets, array $filters, Company $company): void
    {
        if ($presets === []) {
            return;
        }
        $base = $filters['preset'] ?? null;
        if (count($presets) === 1) {
            if ($base === $presets[0]) {
                return;
            }
            $period = ReportPeriod::fromPreset($presets[0], $company);
            if (($filters['start_date'] ?? null) === $period->startDate && ($filters['end_date'] ?? null) === $period->endDate) {
                return;
            }
            throw new AssistantUnavailable('question_invalid');
        }
        $pairs = [
            ['today', 'yesterday'], ['this_week', 'last_week'],
            ['this_month', 'last_month'], ['this_year', 'last_year'],
        ];
        foreach ($pairs as [$current, $previous]) {
            if (count($presets) === 2 && in_array($current, $presets, true) && in_array($previous, $presets, true)
                && $base === $current && (($filters['compare'] ?? null) === 'previous_period'
                    || ($current === 'this_year' && ($filters['compare'] ?? null) === 'previous_year'))) {
                return;
            }
        }
        // "This month compared with last year" refers to its corresponding prior-year period.
        if (count($presets) === 2 && in_array('last_year', $presets, true) && $base !== 'last_year'
            && in_array($base, $presets, true) && ($filters['compare'] ?? null) === 'previous_year') {
            return;
        }
        throw new AssistantUnavailable('question_invalid');
    }

    /** @return list<string> */
    private function presets(string $text): array
    {
        $patterns = [
            'this_month' => '/\bthis\s+month\b|هالشهر|هذا\s+الشهر|الشهر\s+الحالي/u',
            'last_month' => '/\blast\s+month\b|(?:ال)?شهر\s+(?:ال)?(?:ماضي|سابق)/u',
            'this_week' => '/\bthis\s+week\b|هالاسبوع|هذا\s+الاسبوع|الاسبوع\s+الحالي/u',
            'last_week' => '/\blast\s+week\b|(?:ال)?اسبوع\s+(?:ال)?(?:ماضي|سابق)/u',
            'this_year' => '/\bthis\s+year\b|هالسنه|هالعام|هذه\s+السنه|هذا\s+العام|(?:السنه|العام)\s+الحالي/u',
            'last_year' => '/\blast\s+year\b|(?:ال)?(?:سنه|عام)\s+(?:ال)?(?:ماضيه?|سابقه?)/u',
            'this_quarter' => '/\bthis\s+quarter\b|هالربع|هذا\s+الربع|الربع\s+الحالي/u',
            'today' => '/\btoday\b|(?:^|\s)اليوم(?:\s|$)/u',
            'yesterday' => '/\byesterday\b|(?:^|\s)(?:امس|مبارح|البارحه)(?:\s|$)/u',
        ];
        $found = [];
        foreach ($patterns as $preset => $pattern) {
            if (preg_match($pattern, $text)) {
                $found[] = $preset;
            }
        }

        return $found;
    }

    private function normalize(string $text): string
    {
        $text = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], mb_strtolower($text));
        $text = preg_replace('/[\p{M}ـ]/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
