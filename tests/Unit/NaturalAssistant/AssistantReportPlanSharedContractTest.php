<?php

declare(strict_types=1);

namespace Tests\Unit\NaturalAssistant;

use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantReportPlan;
use App\Application\AI\AssistantUnavailable;
use Tests\TestCase;

class AssistantReportPlanSharedContractTest extends TestCase
{
    /** @return array<string, mixed> */
    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function plan(string $key = 'sales.summary', array $filters = ['preset' => 'this_month']): array
    {
        return [
            'version' => 1,
            'type' => 'report_query',
            'report_key' => $key,
            'filters' => $filters,
            'display' => 'summary',
        ];
    }

    public function test_all_curated_catalog_reports_accept_standard_plan(): void
    {
        foreach (AssistantReportCatalog::KEYS as $key) {
            $definition = app(AssistantReportCatalog::class)->definition($key);
            $filters = $key === 'sales.last-invoice' ? ['customer_name' => 'Synthetic Ahmed']
                : ($definition['current'] ? [] : ['preset' => 'last_month']);
            $plan = AssistantReportPlan::fromArray($this->plan($key, $filters));
            $this->assertSame($key, $plan->reportKey);
            $this->assertSame([...$filters, 'page' => 1, 'per_page' => 5], $plan->queryFilters());
        }
    }

    public function test_explicit_start_and_end_date_range_is_accepted_for_temporal_reports(): void
    {
        $plan = AssistantReportPlan::fromArray($this->plan('sales.summary', [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-15',
        ]));

        $this->assertSame('sales.summary', $plan->reportKey);
        $this->assertSame('2026-10-01', $plan->filters['start_date']);
        $this->assertSame('2026-10-15', $plan->filters['end_date']);
        $this->assertSame([
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-15',
            'page' => 1,
            'per_page' => 5,
        ], $plan->queryFilters());
    }

    public function test_temporal_reports_accept_compare_previous_period(): void
    {
        $plan = AssistantReportPlan::fromArray($this->plan('sales.by-period', [
            'preset' => 'this_month',
            'compare' => 'previous_period',
        ]));

        $this->assertSame('previous_period', $plan->filters['compare']);
        // queryFilters strips compare for backward compatibility
        $this->assertSame([
            'preset' => 'this_month',
            'page' => 1,
            'per_page' => 5,
        ], $plan->queryFilters());
    }

    public function test_compare_is_rejected_on_current_reports(): void
    {
        $this->expectException(AssistantUnavailable::class);
        AssistantReportPlan::fromArray($this->plan('customers.balances', [
            'compare' => 'previous_period',
        ]));
    }

    public function test_compare_rejects_unsupported_comparison_mode(): void
    {
        $this->expectException(AssistantUnavailable::class);
        AssistantReportPlan::fromArray($this->plan('sales.summary', [
            'preset' => 'this_month',
            'compare' => 'next_period',
        ]));
    }

    public function test_entity_name_filters_accepted_only_when_report_supports_corresponding_id(): void
    {
        // sales.by-customer supports customer_id -> customer_name allowed
        $plan = AssistantReportPlan::fromArray($this->plan('sales.by-customer', [
            'preset' => 'this_month',
            'customer_name' => 'Acme Corp',
        ]));
        $this->assertSame('Acme Corp', $plan->filters['customer_name']);

        // purchases.by-vendor supports vendor_id -> vendor_name allowed
        $plan2 = AssistantReportPlan::fromArray($this->plan('purchases.by-vendor', [
            'preset' => 'this_month',
            'vendor_name' => 'Global Supplier',
        ]));
        $this->assertSame('Global Supplier', $plan2->filters['vendor_name']);

        // sales.by-product supports product_id -> product_name allowed
        $plan3 = AssistantReportPlan::fromArray($this->plan('sales.by-product', [
            'preset' => 'this_month',
            'product_name' => 'Widget A',
        ]));
        $this->assertSame('Widget A', $plan3->filters['product_name']);

        // inventory.stock supports warehouse_id and category_id
        $plan4 = AssistantReportPlan::fromArray($this->plan('inventory.stock', [
            'preset' => 'this_month',
            'warehouse_name' => 'Main Warehouse',
            'category_name' => 'Electronics',
        ]));
        $this->assertSame('Main Warehouse', $plan4->filters['warehouse_name']);
        $this->assertSame('Electronics', $plan4->filters['category_name']);
    }

    public function test_unsupported_entity_filter_throws_question_unsupported(): void
    {
        try {
            // inventory.stock does not support customer_id -> customer_name must throw question_unsupported
            AssistantReportPlan::fromArray($this->plan('inventory.stock', [
                'preset' => 'this_month',
                'customer_name' => 'Acme Corp',
            ]));
            $this->fail('Expected customer_name on inventory.stock to be rejected.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('question_unsupported', $e->reason);
        }

        try {
            // sales.by-customer does not support vendor_id -> vendor_name must throw question_unsupported
            AssistantReportPlan::fromArray($this->plan('sales.by-customer', [
                'preset' => 'this_month',
                'vendor_name' => 'Global Supplies',
            ]));
            $this->fail('Expected vendor_name on sales.by-customer to be rejected.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('question_unsupported', $e->reason);
        }
    }

    public function test_model_ids_and_untrusted_fields_are_strictly_rejected(): void
    {
        $rejectedCases = [
            $this->plan('sales.summary', ['preset' => 'today', 'customer_id' => '1']),
            $this->plan('purchases.summary', ['preset' => 'today', 'vendor_id' => '2']),
            $this->plan('sales.by-product', ['preset' => 'today', 'product_id' => '3']),
            $this->plan('inventory.stock', ['preset' => 'today', 'warehouse_id' => '4']),
            $this->plan('expenses.summary', ['preset' => 'today', 'category_id' => '5']),
            $this->plan('sales.summary', ['preset' => 'today', 'company_id' => '1']),
            $this->plan('sales.summary', ['preset' => 'today', 'page' => '1']),
            $this->plan('sales.summary', ['preset' => 'today', 'per_page' => '5']),
            $this->plan('sales.summary', ['preset' => 'today', 'sql' => 'SELECT 1']),
            $this->plan('sales.summary', ['preset' => 'today', 'url' => 'http://malicious.test']),
            $this->plan('sales.summary', ['preset' => 'today', 'unknown_filter' => 'value']),
        ];

        foreach ($rejectedCases as $case) {
            try {
                AssistantReportPlan::fromArray($case);
                $this->fail('Untrusted plan accepted: '.json_encode($case['filters']));
            } catch (AssistantUnavailable $e) {
                $this->assertContains($e->reason, ['question_invalid', 'question_unsupported']);
            }
        }
    }

    public function test_non_scalar_string_filters_are_rejected(): void
    {
        $nonScalars = [
            $this->plan('sales.summary', ['preset' => ['this_month']]),
            $this->plan('sales.summary', ['preset' => 123]),
            $this->plan('sales.summary', ['preset' => null]),
            $this->plan('sales.summary', ['preset' => true]),
            $this->plan('sales.by-customer', ['preset' => 'this_month', 'customer_name' => ['Acme']]),
            $this->plan('sales.by-customer', ['preset' => 'this_month', 'customer_name' => 456]),
        ];

        foreach ($nonScalars as $case) {
            try {
                AssistantReportPlan::fromArray($case);
                $this->fail('Non-scalar plan accepted.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason);
            }
        }
    }

    public function test_inverted_or_malformed_dates_are_rejected(): void
    {
        $badDates = [
            $this->plan('sales.summary', ['start_date' => '2026-10-31', 'end_date' => '2026-10-01']),
            $this->plan('sales.summary', ['start_date' => '2026/10/01', 'end_date' => '2026/10/31']),
            $this->plan('sales.summary', ['start_date' => 'invalid-date', 'end_date' => '2026-10-31']),
            $this->plan('sales.summary', ['start_date' => '2026-10-01']), // missing end_date
        ];

        foreach ($badDates as $case) {
            try {
                AssistantReportPlan::fromArray($case);
                $this->fail('Bad date plan accepted.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('question_invalid', $e->reason);
            }
        }
    }
}
