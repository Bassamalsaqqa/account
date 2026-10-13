<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Reporting\DTO\ReportPeriod;

/**
 * Economical deterministic AR/EN classifier for curated assistant reports.
 * Runs before any provider call.
 */
final class AssistantDeterministicClassifier
{
    /**
     * @return array{kind: 'plan', plan: AssistantReportPlan}|array{kind: 'clarify_period', report_key: string, filters: array<string, string>}|array{kind: 'transaction'}|array{kind: 'ambiguous_operation', report_key: string}|null
     */
    public function classify(string $text): ?array
    {
        $clean = trim($text);
        if ($clean === '' || mb_strlen($clean) > (int) config('ai_assistant.max_text_characters')) {
            return null;
        }

        $arNorm = $this->normalizeArabic($clean);
        $enNorm = strtolower($clean);

        $hasTransaction = $this->matchesTransaction($arNorm, $enNorm);
        $matchedReports = $this->matchReportKeys($arNorm, $enNorm);

        if (count($matchedReports) > 1) {
            throw new AssistantUnavailable('question_invalid');
        }

        $matchedReport = $matchedReports[0] ?? null;

        if ($hasTransaction) {
            if ($matchedReport !== null) {
                return ['kind' => 'ambiguous_operation', 'report_key' => $matchedReport];
            }

            return ['kind' => 'transaction'];
        }

        if ($matchedReport === null) {
            if (preg_match('/\b(?:sales|purchases|balances?|stock|inventory|turnover|receivables|payables)\b|(?:مبيعات|مشتريات|رصيد|ارصده|مخزون|بضاعه|حصيله)/u', $arNorm)
                && $this->hasUnsupportedQualifiers($clean)) {
                throw new AssistantUnavailable('question_invalid');
            }

            return null;
        }

        // For report queries, reject unsupported entity filters, negation, comparisons, or contradictory values
        if ($this->hasUnsupportedQualifiers($clean)) {
            throw new AssistantUnavailable('question_invalid');
        }

        $definition = app(AssistantReportCatalog::class)->definition($matchedReport);
        $preset = $this->extractPreset($arNorm, $enNorm);
        $currency = $this->extractCurrency($arNorm, $enNorm);

        if ($currency !== null && ! in_array('currency_code', $definition['filters'], true)) {
            throw new AssistantUnavailable('question_invalid');
        }
        $filters = [];
        if ($currency !== null && in_array('currency_code', $definition['filters'], true)) {
            $filters['currency_code'] = $currency;
        }

        if ($definition['current']) {
            if ($preset !== null) {
                // Current balance reports cannot accept a historical period preset.
                throw new AssistantUnavailable('question_invalid');
            }

            return [
                'kind' => 'plan',
                'plan' => AssistantReportPlan::fromArray([
                    'version' => 1,
                    'type' => 'report_query',
                    'report_key' => $matchedReport,
                    'filters' => $filters,
                    'display' => 'summary',
                ]),
            ];
        }

        // For historical reports, require an explicit preset rather than guessing.
        if ($preset === null) {
            return [
                'kind' => 'clarify_period',
                'report_key' => $matchedReport,
                'filters' => $filters,
            ];
        }

        $filters['preset'] = $preset;

        return [
            'kind' => 'plan',
            'plan' => AssistantReportPlan::fromArray([
                'version' => 1,
                'type' => 'report_query',
                'report_key' => $matchedReport,
                'filters' => $filters,
                'display' => 'summary',
            ]),
        ];
    }

    /**
     * Re-executes or updates bounded period and currency filters for a followup question.
     * Never accepts arbitrary entities, SQL, negation, or unsupported comparisons.
     *
     * @param  array<string, string>  $cachedFilters
     */
    public function classifyFollowup(string $text, string $cachedReportKey, array $cachedFilters): ?AssistantReportPlan
    {
        $clean = trim($text);
        if ($clean === '' || mb_strlen($clean) > (int) config('ai_assistant.max_text_characters')) {
            return null;
        }

        $arNorm = $this->normalizeArabic($clean);
        $enNorm = strtolower($clean);

        if ($this->matchesTransaction($arNorm, $enNorm) || count($this->matchReportKeys($arNorm, $enNorm)) > 0
            || preg_match('/\bturnover\b|(?:^|\s)حصيله(?:\s|$)/u', $arNorm)) {
            return null;
        }
        if ($this->hasUnsupportedQualifiers($clean)) {
            throw new AssistantUnavailable('question_invalid');
        }

        $preset = $this->extractPreset($arNorm, $enNorm);
        $currency = $this->extractCurrency($arNorm, $enNorm);

        if ($preset === null && $currency === null) {
            return null;
        }

        $definition = app(AssistantReportCatalog::class)->definition($cachedReportKey);

        if ($currency !== null && ! in_array('currency_code', $definition['filters'], true)) {
            throw new AssistantUnavailable('question_invalid');
        }

        if ($preset !== null && $definition['current']) {
            throw new AssistantUnavailable('question_invalid');
        }

        // Filter out any invalid / untrusted metadata from cached filters
        $filters = [];
        if (isset($cachedFilters['preset']) && ! $definition['current']) {
            $filters['preset'] = $cachedFilters['preset'];
        }
        if (isset($cachedFilters['currency_code']) && in_array('currency_code', $definition['filters'], true)) {
            $filters['currency_code'] = $cachedFilters['currency_code'];
        }

        if ($preset !== null) {
            $filters['preset'] = $preset;
        }

        if ($currency !== null && in_array('currency_code', $definition['filters'], true)) {
            $filters['currency_code'] = $currency;
        }

        return AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => $cachedReportKey,
            'filters' => $filters,
            'display' => 'summary',
        ]);
    }

    /**
     * Guards orchestrator/provider against qualified queries that cannot be answered
     * by broad deterministic plans without altering economic intent.
     */
    public function hasUnsupportedQualifiers(string $text): bool
    {
        $clean = trim($text);
        if ($clean === '') {
            return false;
        }

        $arNorm = $this->normalizeArabic($clean);
        $enNorm = strtolower($clean);

        // SQL injection / syntax tokens
        if (preg_match('/\b(select|drop|insert|update|delete|union|truncate|alter|exec)\b/i', $enNorm)
            || preg_match('/(?:--|\/\*|\*\/|;)/', $clean)) {
            return true;
        }

        // Contradictory currencies
        if (count($this->extractCurrencies($arNorm, $enNorm)) > 1) {
            return true;
        }

        // Contradictory periods
        if (count($this->extractPresets($arNorm, $enNorm)) > 1) {
            return true;
        }

        // Contradictory reports
        if (count($this->matchReportKeys($arNorm, $enNorm)) > 1) {
            return true;
        }

        // Negation keywords
        if (preg_match('/\b(not\s+including|without|except|excluding)\b/i', $enNorm)
            || preg_match('/(?:^|\s|[^\p{L}])(بدون|عدا|باستثناء|غير\s+شامل|من\s+غير|دون)(?:[^\p{L}]|\s|$)/u', $arNorm)) {
            return true;
        }

        // Unsupported comparisons / thresholds / ranges / sorting
        if (preg_match('/\b(compared\s+to|greater\s+than|less\s+than|more\s+than|higher\s+than|lower\s+than|top\s+\d+|over\s+\d+|under\s+\d+|above\s+\d+|below\s+\d+|sort\s+by|order\s+by|exceeding|minimum|maximum)\b/i', $enNorm)
            || preg_match('/(?:^|\s|[^\p{L}])(مقارن[ةه]|اعلي\s+من|اعلى\s+من|اكثر\s+من|اقل\s+من|اكبر\s+من|اصغر\s+من|فوق|تحت|افضل\s+\d+|اعلي\s+\d+|اعلى\s+\d+)(?:[^\p{L}]|\s|$)/u', $arNorm)) {
            return true;
        }

        // Named customer/vendor/client entities
        if (preg_match('/\bfor\s+(?:the\s+)?(?:customer|client|vendor|supplier|account)\b/i', $enNorm)
            || preg_match('/\b(?:for\s+client|from\s+(?:vendor|supplier))\s+/i', $enNorm)
            || preg_match('/\b(?:customer|client|vendor|supplier)\s*[:=]\s*\w+/i', $enNorm)
            || preg_match('/(?:^|\s|[^\p{L}])(?:ل|لل|من)(?:ال)?(?:زبون|عميل|مورد|تاجر|شرك[ةه])\s+["\']?[\p{L}]+/u', $arNorm)) {
            return true;
        }

        return ! $this->isBoundedQuestionPhrase($clean);
    }

    /** Validate input both before paid planning and against the returned economic filters. */
    public function assertQuestionInput(string $text, ?AssistantReportPlan $plan = null): void
    {
        if ($this->matchesTransaction($this->normalizeArabic($text), strtolower($text))
            || $this->hasUnsupportedQualifiers($text)) {
            throw new AssistantUnavailable('question_invalid');
        }
        $keys = $this->matchReportKeys($this->normalizeArabic($text), strtolower($text));
        if ($keys === [] && preg_match('/\bturnover\b|(?:^|\s)حصيله(?:\s|$)/u', $this->normalizeArabic($text))) {
            $keys = ['sales.summary'];
        }
        if ($keys === []) {
            throw new AssistantUnavailable('question_invalid');
        }
        $preset = $this->extractPresetFromText($text);
        $currency = $this->extractCurrencyFromText($text);
        if (count($keys) === 1 && $currency !== null
            && ! in_array('currency_code', app(AssistantReportCatalog::class)->definition($keys[0])['filters'], true)) {
            throw new AssistantUnavailable('question_invalid');
        }
        if ($plan !== null && (($keys !== [$plan->reportKey])
            || ($plan->filters['preset'] ?? null) !== $preset
            || ($plan->filters['currency_code'] ?? null) !== $currency)) {
            // A provider may identify a tool, never invent or discard an explicit constraint.
            throw new AssistantUnavailable('question_invalid');
        }
    }

    /**
     * V1 accepts a bounded report language, not arbitrary NLP constraints. Consume the
     * complete phrase; any leftover name, product, location, date or currency refuses.
     * Shared by deterministic, follow-up and provider paths (including paraphrases).
     */
    private function isBoundedQuestionPhrase(string $text): bool
    {
        $remaining = $this->normalizeArabic($text);
        if (preg_match('/[\p{N}\p{C}"\'’=:_\/\\\\-]/u', $remaining)) {
            return false;
        }
        $periods = [
            'this month', 'last month', 'this week', 'last week', 'this quarter', 'this year', 'last year', 'today', 'yesterday',
            'هذا الشهر', 'الشهر الحالي', 'شهر حالي', 'الشهر الماضي', 'شهر ماضي', 'الشهر السابق', 'شهر سابق',
            'هذا الاسبوع', 'الاسبوع الحالي', 'اسبوع حالي', 'الاسبوع الماضي', 'اسبوع ماضي', 'الاسبوع السابق', 'اسبوع سابق',
            'هذه السنه', 'السنه الحاليه', 'سنه حاليه', 'هذا العام', 'العام الحالي', 'عام حالي',
            'السنه الماضيه', 'سنه ماضيه', 'السنه السابقه', 'سنه سابقه', 'العام الماضي', 'عام ماضي', 'العام السابق', 'عام سابق',
            'هذا الربع', 'الربع الحالي', 'ربع حالي', 'اليوم', 'يوم', 'امس', 'الامس', 'البارحه', 'بارحه',
        ];
        $currencies = ['ils', 'nis', 'shekel', 'shekels', 'usd', 'dollar', 'dollars', 'jod', 'dinar', 'dinars',
            'شيكل', 'الشيكل', 'شواكل', 'الشواكل', 'دولار', 'الدولار', 'دولارات', 'الدولارات', 'دينار', 'الدينار', 'دنانير', 'الدنانير'];
        $reports = [
            'how much did we sell', 'how much did we buy',
            'sales summary', 'total sales', 'sales', 'purchases summary', 'purchase summary', 'purchases',
            'customer balances', 'customer balance', 'client balances', 'client balance', 'receivables', 'who owes us',
            'vendor balances', 'vendor balance', 'supplier balances', 'supplier balance', 'payables', 'what do we owe vendors', 'what do we owe suppliers',
            'money balances', 'money balance', 'cash balance', 'bank balance', 'cash and bank balances', 'cash and bank', 'how much cash',
            'low stock', 'out of stock', 'stock shortage', 'items running low', 'item running low',
            'expiry report', 'inventory expiry', 'expired stock', 'expiring items', 'expiring item', 'lots expiring', 'lot expiring', 'expiration', 'expiry',
            'المبيعات', 'مبيعاتنا', 'مبيعات', 'المشتريات', 'مشترياتنا', 'مشتريات', 'بعنا', 'اشترينا',
            'ارصده customers', 'ارصده clients', 'ارصده suppliers', 'ارصده vendors',
            'ارصده العملاء', 'ارصده الزبائن', 'رصيد العملاء', 'رصيد الزبائن', 'ديون العملاء', 'ديون الزبائن', 'مستحقات العملاء', 'مستحقات الزبائن',
            'ارصده الموردين', 'رصيد الموردين', 'ديون الموردين', 'مستحقات الموردين', 'كم للموردين', 'كم علينا للموردين',
            'ارصده النقد', 'النقد والبنوك', 'النقد وبنوك', 'رصيد صندوق', 'رصيد الصندوق', 'رصيد بنك', 'رصيد البنك', 'رصيد bank',
            'رصيد الخزينه', 'رصيد خزينه', 'فلوس الصندوق', 'فلوس صندوق', 'كم في الصندوق', 'كم في صندوق', 'كم في البنك', 'كم في بنك',
            'المخزون المنخفض', 'المخزون منخفض', 'مخزون منخفض', 'مخزون المنخفض',
            'المخزون النافد', 'مخزون النافد', 'مخزون نافد', 'المخزون نافد',
            'المواد الناقصه', 'مواد الناقصه', 'مواد ناقصه', 'المواد ناقصه', 'البضاعه الناقصه', 'بضاعه ناقصه', 'بضاعه الناقصه',
            'نقص المخزون', 'نقص مخزون', 'نواقص المخزون', 'نواقص مخزون',
            'صلاحيه المواد', 'صلاحيه مواد', 'المخزون المنتهي', 'مخزون منتهي', 'البضاعه المنتهيه', 'بضاعه منتهيه',
            'تاريخ الصلاحيه', 'تاريخ صلاحيه', 'انتهاء الصلاحيه', 'انتهاء صلاحيه', 'الصلاحيه', 'صلاحيه',
            // Bounded provider-only synonyms. They still have to resolve to an allowed tool.
            'turnover', 'حصيله',
        ];
        foreach (["\x01" => $periods, "\x02" => $currencies, "\x03" => $reports] as $marker => $phrases) {
            usort($phrases, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $pattern = implode('|', array_map(fn (string $phrase): string => preg_quote($phrase, '/'), $phrases));
            $remaining = preg_replace('/(?<!\p{L})(?:[بلو]?(?=ال))?(?:'.$pattern.')(?!\p{L})/u', ' '.$marker.' ', $remaining) ?? $remaining;
        }
        // Only supported period/currency may follow these prepositions; never an entity/tool.
        if (substr_count($remaining, "\x03") > 1
            || preg_match('/\b(?:by|from|to|at|of)\b|\bfor\s+(?!\s*\x01)|\bin\s+(?!\s*[\x01\x02])|(?:^|\s)(?:من|ل|لل)\s|(?:^|\s)في\s+(?!\s*[\x01\x02])/u', $remaining)) {
            return false;
        }
        $fillers = 'show|me|please|what|about|how|much|did|we|our|the|total|summary|report|give|for|in|and|are|is|current|now|doing|business'
            .'|كم|ملخص|تقرير|اعطني|اظهر|عرض|ما|ماذا|عن|و|في|هذا|هذه|حاليا|الان';
        $remaining = preg_replace('/(?<!\p{L})(?:'.$fillers.')(?!\p{L})/u', ' ', $remaining) ?? $remaining;

        // Markers are ASCII; do not discard arbitrary punctuation or unknown residual words.
        return preg_match('/\A[\s\x01\x02\x03؟?,.!]*\z/u', $remaining) === 1;
    }

    public function isUnsupported(string $text): bool
    {
        return $this->hasUnsupportedQualifiers($text);
    }

    public function extractPresetFromText(string $text): ?string
    {
        $ar = $this->normalizeArabic($text);
        $en = strtolower($text);

        return $this->extractPreset($ar, $en);
    }

    public function extractCurrencyFromText(string $text): ?string
    {
        $ar = $this->normalizeArabic($text);
        $en = strtolower($text);

        return $this->extractCurrency($ar, $en);
    }

    private function normalizeArabic(string $text): string
    {
        // Strip tatweel and diacritics
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        // Normalize alifs
        $text = preg_replace('/[إأآ]/u', 'ا', $text) ?? $text;
        // Normalize yaa and taa marbuta
        $text = preg_replace('/ى/u', 'ي', $text) ?? $text;
        $text = preg_replace('/ة/u', 'ه', $text) ?? $text;

        return mb_strtolower(trim($text));
    }

    private function matchesTransaction(string $ar, string $en): bool
    {
        // Past-tense business questions are read requests, not draft instructions.
        // An explicit create/record command can still make a mixed request ambiguous.
        if ((preg_match('/^\s*(?:what|which|how|who|when)\b/i', $en)
                || preg_match('/^\s*(?:شو|ايش|كم|قديش|ماذا|مين|هل)(?:\s|$)/u', $ar))
            && ! preg_match('/\b(?:create|make|draft|prepare|add|issue|record|enter)\b.*\b(?:invoice|sale|purchase)\b/i', $en)
            && ! preg_match('/(?:^|\s)(?:انشئ|انشاء|اعمل|سوي|جهز|اكتب|اضف|سجل|طلع|حضر|اصدر)\s+(?:لي\s+)?(?:فاتور[ةه]|بيع[ةه]?|مبيعات|شراء|مشتريات)/u', $ar)) {
            return false;
        }

        if (preg_match('/\b(?:create|make|draft|prepare|add|issue|record|enter)\b.*\binvoice\b/i', $en)
            || (! preg_match('/\bhow much did we\b/i', $en) && preg_match('/\b(?:buy|bought|sell|sold)\s+(?!report\b|summary\b)/i', $en))
            || preg_match('/\b(?:we\s+sold|we\s+bought|record\s+a\s+sale|record\s+purchase)\b/i', $en)
            || preg_match('/(?:^|\s)(?:انشئ|انشاء|اعمل|سوي|جهز|اكتب|اضف|سجل|طلع|حضر|اصدر)\s+(?:لي\s+)?(?:فاتور[ةه]|بيع[ةه]?|مبيعات|شراء|مشتريات|طلبي[ةه])|(?:^|\s)فاتور[ةه]\s+جديد[ةه]/u', $ar)) {
            return true;
        }
        $arPatterns = [
            '/(?:^|\s|[^\p{L}])(بعت|بيع[ةه]?|فاتور[ةه]\s+(?:بيع|مبيعات|ل|لل|الى)|سجل\s+(?:لي\s+)?(?:بيع[ةه]?|مبيعات))(?:[^\p{L}]|\s|$)/u',
            '/(?:^|\s|[^\p{L}])(اشتريت|شراء|فاتور[ةه]\s+(?:شراء|مشتريات)|سجل\s+(?:لي\s+)?(?:شراء|مشتريات))(?:[^\p{L}]|\s|$)/u',
            '/(?:^|\s|[^\p{L}])(?<!كم\s)(?:بعنا|اشترينا)(?:[^\p{L}]|\s|$)/u',
            '/(?:^|\s|[^\p{L}])(بدي\s+|بدنا\s+)(?:نبيع|ابيع|نشتري|اشتري)(?:[^\p{L}]|\s|$)/u',
        ];

        // Explicit reporting keywords presence
        $hasReportKeyword = preg_match('/(?:^|\s|[^\p{L}])(تقرير|ملخص|كم\s+مبيعات|كم\s+مشتريات|كم\s+بعنا|كم\s+اشترينا)(?:[^\p{L}]|\s|$)/u', $ar)
            || preg_match('/\b(summary|balances?|report|how much did we)\b/i', $en);

        $hasTx = false;
        foreach ($arPatterns as $pattern) {
            if (preg_match($pattern, $ar)) {
                $hasTx = true;
                break;
            }
        }

        if (! $hasTx) {
            $enPatterns = [
                '/\b(sell to|sold to|invoice for|sales invoice)\b/i',
                '/\b(buy from|bought from|purchase from|purchase order)\b/i',
                '/\b(sell|sold)\b.*\bto\b/i',
                '/\b(buy|bought|purchase|purchased)\b.*\bfrom\b/i',
            ];
            foreach ($enPatterns as $pattern) {
                if (preg_match($pattern, $en)) {
                    $hasTx = true;
                    break;
                }
            }
        }

        if ($hasTx && $hasReportKeyword) {
            // Dual operation
            return true;
        }

        if ($hasReportKeyword) {
            return false;
        }

        return $hasTx;
    }

    /**
     * @return list<string>
     */
    private function matchReportKeys(string $ar, string $en): array
    {
        $matched = [];

        // If the query is an invoice command like "فاتورة مبيعات نقدية" without reporting keywords,
        // do not match sales.summary / purchases.summary.
        $isPureTxCommand = preg_match('/(?:^|\s|[^\p{L}])(فاتور[ةه]\s+(?:بيع|مبيعات|شراء|مشتريات|ل|لل|الى)|بعت|بعنا|اشتريت|اشترينا|سجل\s+(?:لي\s+)?(?:بيع[ةه]?|مبيعات|شراء|مشتريات|فاتور[ةه]))(?:[^\p{L}]|\s|$)/u', $ar)
            || preg_match('/(?:^|\s)(?:انشئ|انشاء|اعمل|سوي|جهز|اكتب|اضف|سجل|طلع|حضر|اصدر)\s+(?:لي\s+)?(?:فاتور[ةه]|بيع[ةه]?|مبيعات|شراء|مشتريات)(?:[^\p{L}]|\s|$)/u', $ar)
            || preg_match('/\b(sales invoice|purchase invoice|purchase order|invoice for|sell to|sold to|buy from|bought from|we sold|we bought|record a sale|record purchase)\b/i', $en);

        $hasReportKeyword = preg_match('/(?:^|\s|[^\p{L}])(تقرير|ملخص|كم\s+مبيعات|كم\s+مشتريات|كم\s+بعنا|كم\s+اشترينا)(?:[^\p{L}]|\s|$)/u', $ar)
            || preg_match('/\b(summary|balances?|report|how much did we)\b/i', $en);

        // 1. Customers balances
        if (preg_match('/(?:^|\s|[^\p{L}])(ارصد[ةه]\s+(?:ال)?(?:عملاء|زبائن|customers|clients)|رصيد\s+(?:ال)?(?:عملاء|زبائن|customers|clients)|ديون\s+(?:ال)?(?:عملاء|زبائن|customers|clients)|مستحقات\s+(?:ال)?(?:عملاء|زبائن|customers|clients))(?:[^\p{L}]|\s|$)/u', $ar)
            || preg_match('/\b(customer balances?|client balances?|receivables|who owes us)\b/i', $en)) {
            $matched[] = 'customers.balances';
        }

        // 2. Vendors balances
        if (preg_match('/(?:^|\s|[^\p{L}])(ارصد[ةه]\s+(?:ال)?(?:موردين|suppliers|vendors)|رصيد\s+(?:ال)?(?:موردين|suppliers|vendors)|ديون\s+(?:ال)?(?:موردين|suppliers|vendors)|مستحقات\s+(?:ال)?(?:موردين|suppliers|vendors)|كم\s+(?:علينا\s+)?(?:ل|لل)(?:ال)?موردين)(?:[^\p{L}]|\s|$)/u', $ar)
            || preg_match('/\b(vendor balances?|supplier balances?|payables|what do we owe (?:vendors|suppliers))\b/i', $en)) {
            $matched[] = 'vendors.balances';
        }

        // 3. Money balances (cash & bank)
        if (preg_match('/(?:^|\s|[^\p{L}])(ارصد[ةه]\s+(?:ال)?نقد|النقد\s+و(?:ال)?بنوك|رصيد\s+(?:ال)?صندوق|رصيد\s+(?:ال)?بنك|رصيد\s+bank|كم\s+في\s+(?:ال)?صندوق|كم\s+في\s+(?:ال)?بنك|رصيد\s+(?:ال)?خزين[ةه]|فلوس\s+(?:ال)?صندوق)(?:[^\p{L}]|\s|$)/u', $ar)
            || preg_match('/\b(money balances?|cash balance|bank balance|cash and bank|how much cash)\b/i', $en)) {
            $matched[] = 'money.balances';
        }

        // 4. Inventory low-stock
        if (preg_match('/(?:^|\s|[^\p{L}])((?:ال)?مخزون\s+(?:ال)?منخفض|(?:ال)?مخزون\s+(?:ال)?نافد|(?:ال)?مواد\s+(?:ال)?ناقص[ةه]|(?:ال)?بضاع[ةه]\s+(?:ال)?ناقص[ةه]|نقص\s+(?:ال)?مخزون|نواقص\s+(?:ال)?مخزون)(?:[^\p{L}]|\s|$)/u', $ar)
            || preg_match('/\b(low stock|out of stock|stock shortage|items? running low)\b/i', $en)) {
            $matched[] = 'inventory.low-stock';
        }

        // 5. Inventory expiry
        if (preg_match('/(?:^|\s|[^\p{L}])(تقرير\s+(?:ال)?صلاحي[ةه]|صلاحي[ةه]\s+(?:ال)?مواد|(?:ال)?مخزون\s+(?:ال)?منتهي|(?:ال)?بضاع[ةه]\s+(?:ال)?منتهي[ةه]|تاريخ\s+(?:ال)?صلاحي[ةه]|انتهاء\s+(?:ال)?صلاحي[ةه]|(?:ال)?صلاحي[ةه]|expiry)(?:[^\p{L}]|\s|$)/u', $ar)
            || preg_match('/\b(expiry report|expired stock|expiring items?|lots? expiring|expiration)\b/i', $en)) {
            $matched[] = 'inventory.expiry';
        }

        // 6. Purchases summary
        if (! $isPureTxCommand || $hasReportKeyword) {
            if (preg_match('/(?:^|\s|[^\p{L}])(ملخص\s+(?:ال)?مشتريات|تقرير\s+(?:ال)?مشتريات|ملخص\s+purchases|تقرير\s+purchases|كم\s+مشتريات|كم\s+اشترينا|(?:ال)?مشتريات|مشترياتنا)(?:[^\p{L}]|\s|$)/u', $ar)
                || preg_match('/\b(purchase summary|purchases summary|purchases|how much did we buy)\b/i', $en)) {
                $matched[] = 'purchases.summary';
            }
        }

        // 7. Sales summary
        if (! $isPureTxCommand || $hasReportKeyword) {
            if (preg_match('/(?:^|\s|[^\p{L}])(ملخص\s+(?:ال)?مبيعات|تقرير\s+(?:ال)?مبيعات|ملخص\s+sales|تقرير\s+sales|كم\s+مبيعات|كم\s+بعنا|(?:ال)?مبيعات|مبيعاتنا)(?:[^\p{L}]|\s|$)/u', $ar)
                || preg_match('/\b(sales summary|sales|how much did we sell|total sales)\b/i', $en)) {
                $matched[] = 'sales.summary';
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * @return list<string>
     */
    private function extractPresets(string $ar, string $en): array
    {
        $found = [];
        if (preg_match('/(?:^|\s|[^\p{L}])(?:[بللو]?(?:ال)?)?شهر\s+(?:ال)?(ماض[يى]|سابق)(?:[^\p{L}]|\s|$)/u', $ar) || preg_match('/\blast\s+month\b/i', $en)) {
            $found[] = ReportPeriod::PRESET_LAST_MONTH;
        }
        if (preg_match('/(?:^|\s|[^\p{L}])(هذا\s+(?:ال)?شهر|(?:[بللو]?(?:ال)?)?شهر\s+(?:ال)?حالي)(?:[^\p{L}]|\s|$)/u', $ar) || preg_match('/\bthis\s+month\b/i', $en)) {
            $found[] = ReportPeriod::PRESET_THIS_MONTH;
        }
        if (preg_match('/(?:^|\s|[^\p{L}])(?:[بللو]?(?:ال)?)?اسبوع\s+(?:ال)?(ماض[يى]|سابق)(?:[^\p{L}]|\s|$)/u', $ar) || preg_match('/\blast\s+week\b/i', $en)) {
            $found[] = ReportPeriod::PRESET_LAST_WEEK;
        }
        if (preg_match('/(?:^|\s|[^\p{L}])(هذا\s+(?:ال)?اسبوع|(?:[بللو]?(?:ال)?)?اسبوع\s+(?:ال)?حالي)(?:[^\p{L}]|\s|$)/u', $ar) || preg_match('/\bthis\s+week\b/i', $en)) {
            $found[] = ReportPeriod::PRESET_THIS_WEEK;
        }
        if (preg_match('/(?:^|\s|[^\p{L}])(?:[بللو]?(?:ال)?)?(?:سنه|عام)\s+(?:ال)?(ماضي[ةه]?|سابق[ةه]?)(?:[^\p{L}]|\s|$)/u', $ar) || preg_match('/\blast\s+year\b/i', $en)) {
            $found[] = ReportPeriod::PRESET_LAST_YEAR;
        }
        if (preg_match('/(?:^|\s|[^\p{L}])(هذ[ةه]\s+(?:ال)?سنه|هذا\s+(?:ال)?عام|(?:[بللو]?(?:ال)?)?(?:سنه|عام)\s+(?:ال)?حالي[ةه]?)(?:[^\p{L}]|\s|$)/u', $ar) || preg_match('/\bthis\s+year\b/i', $en)) {
            $found[] = ReportPeriod::PRESET_THIS_YEAR;
        }
        if (preg_match('/(?:^|\s|[^\p{L}])(هذا\s+(?:ال)?ربع|(?:[بللو]?(?:ال)?)?ربع\s+(?:ال)?حالي)(?:[^\p{L}]|\s|$)/u', $ar) || preg_match('/\bthis\s+quarter\b/i', $en)) {
            $found[] = ReportPeriod::PRESET_THIS_QUARTER;
        }
        if (preg_match('/(?:^|\s|[^\p{L}])(?:[بللو]?(?:ال)?)?(امس|بارح[ةه])(?:[^\p{L}]|\s|$)/u', $ar) || preg_match('/\byesterday\b/i', $en)) {
            $found[] = ReportPeriod::PRESET_YESTERDAY;
        }
        if (preg_match('/(?:^|\s|[^\p{L}])(?:[بللو]?(?:ال)?)?يوم(?:[^\p{L}]|\s|$)/u', $ar) || preg_match('/\btoday\b/i', $en)) {
            $found[] = ReportPeriod::PRESET_TODAY;
        }

        return array_values(array_unique($found));
    }

    private function extractPreset(string $ar, string $en): ?string
    {
        $presets = $this->extractPresets($ar, $en);
        if (count($presets) > 1) {
            throw new AssistantUnavailable('question_invalid');
        }

        return $presets[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function extractCurrencies(string $ar, string $en): array
    {
        $found = [];
        if (preg_match('/(?:^|\s|[^\p{L}])(?:[بللو]?(?:ال)?)?(شيكل|شواكل)(?:[^\p{L}]|\s|$)/ui', $ar)
            || preg_match('/\b(ils|nis|shekels?)\b/i', $en)) {
            $found[] = 'ILS';
        }
        if (preg_match('/(?:^|\s|[^\p{L}])(?:[بللو]?(?:ال)?)?(دولار|دولارات)(?:[^\p{L}]|\s|$)/ui', $ar)
            || preg_match('/\b(usd|dollars?)\b/i', $en)) {
            $found[] = 'USD';
        }
        if (preg_match('/(?:^|\s|[^\p{L}])(?:[بللو]?(?:ال)?)?(دينار|دنانير)(?:[^\p{L}]|\s|$)/ui', $ar)
            || preg_match('/\b(jod|dinars?)\b/i', $en)) {
            $found[] = 'JOD';
        }

        return array_values(array_unique($found));
    }

    private function extractCurrency(string $ar, string $en): ?string
    {
        $currencies = $this->extractCurrencies($ar, $en);
        if (count($currencies) > 1) {
            throw new AssistantUnavailable('question_invalid');
        }

        return $currencies[0] ?? null;
    }
}
