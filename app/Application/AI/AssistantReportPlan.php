<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Reporting\DTO\ReportPeriod;

/** Untrusted planner output contains names, never actor, tenant, SQL, URL or entity IDs. */
final readonly class AssistantReportPlan
{
    public const int MAX_INCLUSIVE_DAYS = 366;

    /** @param array<string, string> $filters */
    private function __construct(public string $reportKey, public array $filters) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['display', 'filters', 'report_key', 'type', 'version']
            || $input['version'] !== 1 || $input['type'] !== 'report_query' || $input['display'] !== 'summary'
            || ! is_string($input['report_key']) || ! is_array($input['filters'])) {
            throw new AssistantUnavailable('question_invalid');
        }

        $definition = app(AssistantReportCatalog::class)->definition($input['report_key']);
        try {
            $filters = AssistantIntentProposal::fromArray($input)->filters;
        } catch (AssistantUnavailable) {
            throw new AssistantUnavailable('question_invalid');
        }
        // The SDK proposes explicit custom dates; canonical filters use the date pair.
        if (($filters['preset'] ?? null) === 'custom') {
            unset($filters['preset']);
        }

        $hasPreset = array_key_exists('preset', $filters);
        $hasStartDate = array_key_exists('start_date', $filters);
        $hasEndDate = array_key_exists('end_date', $filters);

        if ($definition['current']) {
            // Current balance reports must not have historical or temporal constraints
            if ($hasPreset || $hasStartDate || $hasEndDate || array_key_exists('compare', $filters)) {
                throw new AssistantUnavailable('question_invalid');
            }
        } else {
            if (! in_array('period', $definition['filters'], true)) {
                throw new AssistantUnavailable('question_unsupported');
            }
            // Temporal reports require either preset OR start_date+end_date
            if ($hasPreset && ($hasStartDate || $hasEndDate)) {
                throw new AssistantUnavailable('question_invalid');
            }
            if (! $hasPreset && (! $hasStartDate || ! $hasEndDate)) {
                throw new AssistantUnavailable('question_invalid');
            }

            if ($hasPreset) {
                if (! in_array($filters['preset'], array_diff(ReportPeriod::ALLOWED_PRESETS, ['custom']), true)) {
                    throw new AssistantUnavailable('question_invalid');
                }
            } else {
                try {
                    self::assertBoundedPeriod(ReportPeriod::custom($filters['start_date'], $filters['end_date'], 'UTC'));
                } catch (\InvalidArgumentException) {
                    throw new AssistantUnavailable('question_invalid');
                }
                if (! preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $filters['start_date'])
                    || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $filters['end_date'])
                    || $filters['start_date'] > $filters['end_date']) {
                    throw new AssistantUnavailable('question_invalid');
                }
            }

            if (array_key_exists('compare', $filters)) {
                if (! in_array($filters['compare'], ['previous_period', 'previous_year'], true)) {
                    throw new AssistantUnavailable('question_invalid');
                }
            }
        }

        if (array_key_exists('currency_code', $filters)) {
            if (! in_array('currency_code', $definition['filters'], true)) {
                throw new AssistantUnavailable('question_unsupported');
            }
            if (! preg_match('/\A[A-Z]{3}\z/D', $filters['currency_code'])) {
                throw new AssistantUnavailable('question_invalid');
            }
        }

        if (array_key_exists('grouping', $filters)) {
            if (! in_array('grouping', $definition['filters'], true)) {
                throw new AssistantUnavailable('question_unsupported');
            }
            $allowedGrouping = $definition['options']['grouping'] ?? [];
            if (! in_array($filters['grouping'], $allowedGrouping, true)) {
                throw new AssistantUnavailable('question_invalid');
            }
        }

        $entityMapping = [
            'customer_name' => 'customer_id',
            'vendor_name' => 'vendor_id',
            'product_name' => 'product_id',
            'warehouse_name' => 'warehouse_id',
            'category_name' => 'category_id',
        ];

        foreach ($entityMapping as $nameFilter => $idFilter) {
            if (array_key_exists($nameFilter, $filters)) {
                if (! in_array($idFilter, $definition['filters'], true)) {
                    throw new AssistantUnavailable('question_unsupported');
                }
                if (trim($filters[$nameFilter]) === '') {
                    throw new AssistantUnavailable('question_invalid');
                }
            }
        }

        foreach ($definition['required'] as $required) {
            $nameKey = array_search($required, $entityMapping, true);
            if ($nameKey !== false && ! isset($filters[$nameKey])) {
                throw new AssistantUnavailable('question_invalid');
            }
        }

        return new self($input['report_key'], $filters);
    }

    public static function assertBoundedPeriod(ReportPeriod $period): void
    {
        $start = new \DateTimeImmutable($period->startDate, new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable($period->endDate, new \DateTimeZone('UTC'));
        if ($start->diff($end)->days + 1 > self::MAX_INCLUSIVE_DAYS) {
            throw new AssistantUnavailable('question_invalid');
        }
    }

    /**
     * Backward-compatible filter array for legacy callers.
     * Retains page/per_page bounding and strips compare parameter.
     *
     * @return array<string, string|int>
     */
    public function queryFilters(): array
    {
        $filters = $this->filters;
        unset($filters['compare']);

        return [...$filters, 'page' => 1, 'per_page' => 5];
    }
}
