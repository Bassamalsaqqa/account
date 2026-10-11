<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Application\Reporting\DTO\ReportPeriod;

/** Untrusted planner output. Contains no actor, tenant, SQL, URL or raw entity identity. */
final readonly class AssistantReportPlan
{
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
        $filters = $input['filters'];
        if (array_diff(array_keys($filters), ['preset', 'currency_code']) !== []) {
            throw new AssistantUnavailable('question_invalid');
        }
        if ($definition['current']) {
            // A current balance must never be labelled as historical activity.
            if (array_key_exists('preset', $filters)) {
                throw new AssistantUnavailable('question_invalid');
            }
        } elseif (! isset($filters['preset']) || ! is_string($filters['preset'])
            || ! in_array($filters['preset'], array_diff(ReportPeriod::ALLOWED_PRESETS, ['custom']), true)) {
            // Ask for an explicit bounded period rather than silently assuming one.
            throw new AssistantUnavailable('question_invalid');
        }
        if (array_key_exists('currency_code', $filters)
            && (! in_array('currency_code', $definition['filters'], true)
                || ! is_string($filters['currency_code']) || ! preg_match('/\A[A-Z]{3}\z/D', $filters['currency_code']))) {
            throw new AssistantUnavailable('question_invalid');
        }

        return new self($input['report_key'], $filters);
    }

    /** Canonical ReportFilters still validates company currencies and timezone on execution.
     * @return array<string, string|int>
     */
    public function queryFilters(): array
    {
        return [...$this->filters, 'page' => 1, 'per_page' => 5];
    }
}
