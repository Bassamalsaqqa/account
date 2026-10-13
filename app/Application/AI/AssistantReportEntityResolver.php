<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Security\ReportingGuard;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;

final class AssistantReportEntityResolver
{
    /** Refuse broad results when a known same-company entity mentioned by the user was omitted.
     * This bounded local lookup never sends names or records to the provider.
     *
     * @param  list<array<string, mixed>>  $proposals
     */
    public static function assertMentionedEntities(Company $company, User $actor, string $text, array $proposals): void
    {
        $company = app(ReportingGuard::class)->company($company, $actor);
        $catalog = app(AssistantReportCatalog::class);
        $supportedFilters = [];
        foreach ($catalog->permittedKeys($company, $actor) as $key) {
            $supportedFilters = [...$supportedFilters, ...$catalog->definition($key)['filters']];
        }
        foreach ([
            ['customer_name', 'customer_id', Customer::class, ['name_ar', 'name_en', 'code']],
            ['vendor_name', 'vendor_id', Vendor::class, ['name_ar', 'name_en', 'code']],
            ['product_name', 'product_id', Product::class, ['name_ar', 'name_en', 'sku']],
            ['warehouse_name', 'warehouse_id', Warehouse::class, ['name_ar', 'name_en', 'code']],
            ['category_name', 'category_id', ExpenseCategory::class, ['name_ar', 'name_en', 'code']],
            ['category_name', 'category_id', ProductCategory::class, ['name_ar', 'name_en']],
        ] as [$label, $filter, $model, $columns]) {
            if (! in_array($filter, $supportedFilters, true)) {
                continue;
            }
            $candidates = $model::withoutGlobalScopes()->where('company_id', $company->id)
                ->where(function ($query) use ($columns, $text): void {
                    foreach ($columns as $column) {
                        // Columns are fixed application identifiers; text is always a bound value.
                        $query->orWhereRaw("(`{$column}` <> '' AND LOCATE(`{$column}`, ?) > 0)", [$text]);
                    }
                })->limit(21)->get($columns);
            if ($candidates->count() > 20) {
                throw new AssistantUnavailable('entity_ambiguous');
            }
            foreach ($candidates as $entity) {
                $names = [];
                $mentioned = false;
                foreach ($columns as $column) {
                    $name = TenantSpeechCandidates::normalize((string) $entity->getAttribute($column));
                    if ($name === '') {
                        continue;
                    }
                    $names[] = $name;
                    if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', TenantSpeechCandidates::normalize($text))) {
                        $mentioned = true;
                    }
                }
                if (! $mentioned) {
                    continue;
                }
                foreach ($proposals as $proposal) {
                    $reference = $proposal['filters'][$label] ?? null;
                    if (! is_string($reference) || ! in_array(TenantSpeechCandidates::normalize($reference), $names, true)) {
                        throw new AssistantUnavailable('question_invalid');
                    }
                }
            }
        }
    }

    /**
     * Resolves human entity names in an untrusted AssistantReportPlan into canonical same-company IDs.
     * Removes compare parameter and enforces bounded pagination window (page 1, per_page 5).
     *
     * @return array<string, mixed>
     */
    public static function resolve(Company $company, User $actor, AssistantReportPlan $plan): array
    {
        app(AssistantReportCatalog::class)->authorize($company, $actor, $plan->reportKey);
        $company = app(ReportingGuard::class)->company($company, $actor);
        $definition = app(AssistantReportCatalog::class)->definition($plan->reportKey);
        $canonical = [
            'page' => 1,
            'per_page' => 5,
        ];

        // 1. Period / dates
        if (isset($plan->filters['preset'])) {
            $period = ReportPeriod::fromPreset($plan->filters['preset'], $company);
            AssistantReportPlan::assertBoundedPeriod($period);
            $canonical['period'] = $period;
        } elseif (isset($plan->filters['start_date'], $plan->filters['end_date'])) {
            $period = ReportPeriod::custom($plan->filters['start_date'], $plan->filters['end_date'], $company);
            AssistantReportPlan::assertBoundedPeriod($period);
            $canonical['period'] = $period;
        }

        // 2. Currency
        if (isset($plan->filters['currency_code'])) {
            if (! in_array('currency_code', $definition['filters'], true)) {
                throw new AssistantUnavailable('question_unsupported');
            }
            if (! CompanyCurrency::withoutGlobalScopes()->where('company_id', $company->id)->where('currency_code', $plan->filters['currency_code'])->exists()) {
                throw new AssistantUnavailable('question_invalid');
            }
            $canonical['currency_code'] = $plan->filters['currency_code'];
        }

        // 3. Grouping
        if (isset($plan->filters['grouping'])) {
            if (! in_array('grouping', $definition['filters'], true)) {
                throw new AssistantUnavailable('question_unsupported');
            }
            $canonical['grouping'] = $plan->filters['grouping'];
        }

        // 4. Entity names strictly resolved to same-company IDs
        if (isset($plan->filters['customer_name'])) {
            if (! in_array('customer_id', $definition['filters'], true)) {
                throw new AssistantUnavailable('question_unsupported');
            }
            $canonical['customer_id'] = self::resolveEntityId($company, Customer::class, ['name_ar', 'name_en', 'code'], $plan->filters['customer_name']);
        }

        if (isset($plan->filters['vendor_name'])) {
            if (! in_array('vendor_id', $definition['filters'], true)) {
                throw new AssistantUnavailable('question_unsupported');
            }
            $canonical['vendor_id'] = self::resolveEntityId($company, Vendor::class, ['name_ar', 'name_en', 'code'], $plan->filters['vendor_name']);
        }

        if (isset($plan->filters['product_name'])) {
            if (! in_array('product_id', $definition['filters'], true)) {
                throw new AssistantUnavailable('question_unsupported');
            }
            $canonical['product_id'] = self::resolveProductEntityId($company, $plan->filters['product_name']);
        }

        if (isset($plan->filters['warehouse_name'])) {
            if (! in_array('warehouse_id', $definition['filters'], true)) {
                throw new AssistantUnavailable('question_unsupported');
            }
            $canonical['warehouse_id'] = self::resolveEntityId($company, Warehouse::class, ['name_ar', 'name_en', 'code'], $plan->filters['warehouse_name']);
        }

        if (isset($plan->filters['category_name'])) {
            if (! in_array('category_id', $definition['filters'], true)) {
                throw new AssistantUnavailable('question_unsupported');
            }
            if (str_starts_with($plan->reportKey, 'expenses.')) {
                $canonical['category_id'] = self::resolveEntityId($company, ExpenseCategory::class, ['name_ar', 'name_en', 'code'], $plan->filters['category_name']);
            } else {
                $canonical['category_id'] = self::resolveEntityId($company, ProductCategory::class, ['name_ar', 'name_en'], $plan->filters['category_name']);
            }
        }

        // Verify no unsupported meaningful constraint was supplied
        $handledKeys = [
            'preset', 'start_date', 'end_date', 'currency_code', 'grouping',
            'customer_name', 'vendor_name', 'product_name', 'warehouse_name', 'category_name',
            'compare',
        ];

        foreach (array_keys($plan->filters) as $filterKey) {
            if (! in_array($filterKey, $handledKeys, true)) {
                throw new AssistantUnavailable('question_unsupported');
            }
        }

        return $canonical;
    }

    /**
     * @param  class-string  $modelClass
     * @param  list<string>  $columns
     */
    private static function resolveEntityId(Company $company, string $modelClass, array $columns, string $reference): int
    {
        $trimmed = trim($reference);
        if ($trimmed === '') {
            throw new AssistantUnavailable('entity_missing');
        }

        // Tier 1: Exact identifier / code / exact name match (direct SQL, scoped strictly to active company)
        $exactMatches = $modelClass::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where(function ($q) use ($columns, $trimmed): void {
                foreach ($columns as $column) {
                    $q->orWhere($column, $trimmed);
                }
            })
            ->limit(2)
            ->get();

        if ($exactMatches->count() === 1) {
            return (int) $exactMatches->first()->getKey();
        }

        if ($exactMatches->count() > 1) {
            throw new AssistantUnavailable('entity_ambiguous');
        }

        // Tier 2: Normalized exact match (Arabic alef/tatweel/diacritics, English case/whitespace)
        $normRef = TenantSpeechCandidates::normalize($trimmed);
        $candidates = $modelClass::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->limit(TenantSpeechCandidates::FINITE_SCAN_CAP + 1)
            ->get();

        if ($candidates->count() > TenantSpeechCandidates::FINITE_SCAN_CAP) {
            throw new AssistantUnavailable('entity_missing');
        }

        $normalizedMatches = $candidates->filter(function ($row) use ($columns, $normRef): bool {
            foreach ($columns as $col) {
                $val = $row->getAttribute($col);
                if ($val !== null && TenantSpeechCandidates::normalize((string) $val) === $normRef) {
                    return true;
                }
            }

            return false;
        });

        if ($normalizedMatches->count() === 1) {
            return (int) $normalizedMatches->first()->getKey();
        }

        if ($normalizedMatches->count() > 1) {
            throw new AssistantUnavailable('entity_ambiguous');
        }

        throw new AssistantUnavailable('entity_missing');
    }

    private static function resolveProductEntityId(Company $company, string $reference): int
    {
        $trimmed = trim($reference);
        if ($trimmed === '') {
            throw new AssistantUnavailable('entity_missing');
        }

        // Tier 1: Exact SKU, name_ar, name_en, or barcode match
        $exactMatches = Product::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where(function ($q) use ($trimmed, $company): void {
                $q->where('sku', $trimmed)
                    ->orWhere('name_ar', $trimmed)
                    ->orWhere('name_en', $trimmed)
                    ->orWhereHas('barcodes', fn ($b) => $b->where('company_id', $company->id)->where('barcode', $trimmed));
            })
            ->limit(2)
            ->get();

        if ($exactMatches->count() === 1) {
            return (int) $exactMatches->first()->getKey();
        }

        if ($exactMatches->count() > 1) {
            throw new AssistantUnavailable('entity_ambiguous');
        }

        // Tier 2: Normalized match
        $normRef = TenantSpeechCandidates::normalize($trimmed);
        $candidates = Product::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->with(['barcodes' => fn ($b) => $b->where('company_id', $company->id)])
            ->limit(TenantSpeechCandidates::FINITE_SCAN_CAP + 1)
            ->get();

        if ($candidates->count() > TenantSpeechCandidates::FINITE_SCAN_CAP) {
            throw new AssistantUnavailable('entity_missing');
        }

        $normalizedMatches = $candidates->filter(function (Product $row) use ($normRef): bool {
            foreach (['sku', 'name_ar', 'name_en'] as $col) {
                $val = $row->getAttribute($col);
                if ($val !== null && TenantSpeechCandidates::normalize((string) $val) === $normRef) {
                    return true;
                }
            }
            if ($row->relationLoaded('barcodes')) {
                foreach ($row->barcodes as $barcode) {
                    if (TenantSpeechCandidates::normalize((string) $barcode->barcode) === $normRef) {
                        return true;
                    }
                }
            }

            return false;
        });

        if ($normalizedMatches->count() === 1) {
            return (int) $normalizedMatches->first()->getKey();
        }

        if ($normalizedMatches->count() > 1) {
            throw new AssistantUnavailable('entity_ambiguous');
        }

        throw new AssistantUnavailable('entity_missing');
    }
}
