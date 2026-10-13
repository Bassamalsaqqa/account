<?php

declare(strict_types=1);

namespace Tests\Unit\AiV22;

use App\Application\AI\AssistantIntentProposal;
use App\Application\AI\AssistantUnavailable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssistantIntentProposalTest extends TestCase
{
    /** @param array<string, mixed> $filters
     * @return array<string, mixed> */
    private static function proposal(array $filters): array
    {
        return ['version' => 1, 'type' => 'report_query', 'report_key' => 'sales.summary', 'filters' => $filters, 'display' => 'summary'];
    }

    public function test_raw_names_dates_comparisons_are_data_only_not_query_authority(): void
    {
        $filters = ['customer_name' => 'أحمد', 'product_name' => 'Soap & Oil', 'vendor_name' => 'QA Vendor',
            'warehouse_name' => 'QA Store', 'category_name' => 'Supplies', 'preset' => 'custom',
            'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'currency_code' => 'ILS',
            'grouping' => 'monthly', 'compare' => 'previous_year'];
        $proposal = AssistantIntentProposal::fromArray(self::proposal($filters));
        $this->assertSame('sales.summary', $proposal->reportKey);
        $this->assertSame($filters, $proposal->filters);
        $reflection = new \ReflectionClass(AssistantIntentProposal::class);
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->getConstructor()->isPrivate());
        $this->assertSame(['__construct', 'fromArray'], array_map(fn ($method) => $method->name, $reflection->getMethods()));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidFilters(): array
    {
        return [
            'raw customer ID' => [['customer_id' => '3']],
            'tenant ID' => [['company_id' => '3']],
            'sql' => [['sql' => 'select * from purchases']],
            'arbitrary field' => [['invented' => 'value']],
            'empty name' => [['customer_name' => '']],
            'blank name' => [['customer_name' => '  ']],
            'padded name' => [['customer_name' => ' Ahmed']],
            'overlong name' => [['customer_name' => str_repeat('a', 256)]],
            'control name' => [['customer_name' => "Ahmed\nAnother"]],
            'name URL' => [['customer_name' => 'https://example.test']],
            'non-string name' => [['customer_name' => 12]],
            'array name' => [['customer_name' => ['Ahmed']]],
            'invalid preset' => [['preset' => 'next_five_years']],
            'missing custom dates' => [['preset' => 'custom']],
            'missing end' => [['start_date' => '2026-01-01']],
            'missing start' => [['end_date' => '2026-01-01']],
            'invalid calendar' => [['start_date' => '2026-02-30', 'end_date' => '2026-03-01']],
            'non-ISO date' => [['start_date' => '1/1/2026', 'end_date' => '2026-03-01']],
            'reversed dates' => [['start_date' => '2026-03-01', 'end_date' => '2026-01-01']],
            'oversized span' => [['start_date' => '2024-01-01', 'end_date' => '2025-01-01']],
            'conflicting period' => [['preset' => 'today', 'start_date' => '2026-01-01', 'end_date' => '2026-01-02']],
            'lowercase currency' => [['currency_code' => 'usd']],
            'unknown grouping' => [['grouping' => 'raw_records']],
            'unknown comparison' => [['preset' => 'this_month', 'compare' => 'all_companies']],
            'comparison without period' => [['compare' => 'previous_period']],
        ];
    }

    /** @param array<string, mixed> $filters */
    #[DataProvider('invalidFilters')]
    public function test_invalid_or_authority_bearing_filters_are_rejected(array $filters): void
    {
        $this->expectException(AssistantUnavailable::class);
        AssistantIntentProposal::fromArray(self::proposal($filters));
    }

    public function test_unapproved_report_and_extra_envelope_fields_are_rejected(): void
    {
        foreach ([['report_key' => 'payroll.salaries'], ['company_id' => 1], ['display' => 'raw_dump']] as $override) {
            try {
                AssistantIntentProposal::fromArray(array_replace(self::proposal([]), $override));
                $this->fail('Unapproved envelope accepted.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('provider_invalid', $e->reason);
            }
        }
    }
}
