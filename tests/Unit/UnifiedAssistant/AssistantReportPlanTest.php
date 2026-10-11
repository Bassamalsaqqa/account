<?php

declare(strict_types=1);

namespace Tests\Unit\UnifiedAssistant;

use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantReportPlan;
use App\Application\AI\AssistantUnavailable;
use App\Application\Reporting\DTO\ReportPeriod;
use Tests\TestCase;

class AssistantReportPlanTest extends TestCase
{
    /** @return array<string, mixed> */
    private function plan(string $key = 'sales.summary', array $filters = ['preset' => 'this_month']): array
    {
        return ['version' => 1, 'type' => 'report_query', 'report_key' => $key, 'filters' => $filters, 'display' => 'summary'];
    }

    public function test_all_curated_tools_use_explicit_canonical_periods_or_current_truth_and_bounded_rows(): void
    {
        foreach (AssistantReportCatalog::KEYS as $key) {
            $definition = app(AssistantReportCatalog::class)->definition($key);
            $filters = $definition['current'] ? [] : ['preset' => 'last_month'];
            $plan = AssistantReportPlan::fromArray($this->plan($key, $filters));
            $this->assertSame($key, $plan->reportKey);
            $this->assertSame([...$filters, 'page' => 1, 'per_page' => 5], $plan->queryFilters());
        }
    }

    public function test_every_bounded_canonical_preset_is_preserved_without_inventing_dates(): void
    {
        foreach (array_diff(ReportPeriod::ALLOWED_PRESETS, ['custom']) as $preset) {
            $this->assertSame(['preset' => $preset, 'currency_code' => 'JOD'], AssistantReportPlan::fromArray($this->plan(filters: ['preset' => $preset, 'currency_code' => 'JOD']))->filters);
        }
    }

    public function test_untrusted_shape_sql_actor_tenant_urls_and_unbounded_filters_are_refused(): void
    {
        $valid = $this->plan();
        $cases = [[], [...$valid, 'company_id' => 2], [...$valid, 'actor_id' => 2], [...$valid, 'sql' => 'SELECT * FROM users'],
            [...$valid, 'url' => 'https://example.invalid'], [...$valid, 'version' => '1'], [...$valid, 'type' => 'post_invoice'],
            [...$valid, 'display' => 'raw'], [...$valid, 'filters' => null], [...$valid, 'report_key' => ['sales.summary']],
            $this->plan(filters: []), $this->plan(filters: ['preset' => 'custom']), $this->plan(filters: ['preset' => null]),
            $this->plan(filters: ['preset' => ['today']]), $this->plan(filters: ['preset' => 'all_time']),
            $this->plan(filters: ['preset' => 'today', 'page' => 10000]), $this->plan(filters: ['preset' => 'today', 'per_page' => 50000]),
            $this->plan(filters: ['preset' => 'today', 'customer_id' => 2]), $this->plan(filters: ['preset' => 'today', 'company_id' => 2]),
            $this->plan(filters: ['preset' => 'today', 'currency_code' => 'usd']), $this->plan(filters: ['preset' => 'today', 'currency_code' => 'USD/ILS']),
            $this->plan(filters: ['preset' => 'today', 'currency_code' => null]), $this->plan(filters: ['preset' => 'today', 'currency_code' => ['ILS']]),
            $this->plan('customers.balances', ['preset' => 'last_month']), $this->plan('money.balances', ['preset' => 'yesterday']),
            $this->plan('inventory.low-stock', ['currency_code' => 'ILS']), $this->plan('sales.gross-profit'), $this->plan('users'),
        ];
        foreach ($cases as $index => $input) {
            try {
                AssistantReportPlan::fromArray($input);
                $this->fail('Untrusted plan accepted: '.$index);
            } catch (AssistantUnavailable $error) {
                $this->assertContains($error->reason, ['question_invalid', 'question_unsupported']);
            }
        }
    }
}
