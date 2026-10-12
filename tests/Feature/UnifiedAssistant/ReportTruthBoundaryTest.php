<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedAssistant;

use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantReportExecutor;
use App\Application\AI\AssistantReportPlan;
use App\Application\AI\AssistantUnavailable;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Models\AiCompanyPolicy;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class ReportTruthBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
        $this->travelTo(now()->setDate(2026, 10, 12));
        config(['ai_assistant.enabled' => true, 'ai_assistant.questions.enabled' => true,
            'ai_assistant.provider_approved' => false, 'ai_assistant.provider' => 'none']);
        Http::preventStrayRequests();
        AiCompanyPolicy::create(['company_id' => $this->company->id, 'enabled' => true,
            'settings' => ['questions' => ['enabled' => true, 'allowed_reports' => AssistantReportCatalog::KEYS]]]);
    }

    public function test_all_seven_tools_preserve_their_actual_canonical_total_contract(): void
    {
        $contracts = [
            'sales.summary' => ['gross_sales_base', 'net_sales_base', 'tax_base', 'currencies'],
            'customers.balances' => ['total_customers', 'outstanding_by_currency'],
            'purchases.summary' => ['commercial_purchases_base', 'net_purchases_base', 'currencies'],
            'vendors.balances' => ['total_vendors', 'balance_by_currency'],
            'inventory.low-stock' => ['low_stock_count', 'out_of_stock_count', 'total_alert_count'],
            'inventory.expiry' => ['expired_count', 'soon_count', 'valid_count', 'unknown_count', 'total_active_lots'],
            'money.balances' => ['total_base_balance', 'currency_balances', 'accounts_count'],
        ];
        foreach ($contracts as $key => $totalKeys) {
            $plan = $this->plan($key);
            $canonical = app(ReportRegistry::class)->execute($this->company, $key, $plan->queryFilters());
            $answer = app(AssistantReportExecutor::class)->execute($this->company, $this->owner, $plan)->toArray();
            foreach ($totalKeys as $totalKey) {
                $this->assertArrayHasKey($totalKey, $answer['totals'], $key.' / '.$totalKey);
                $this->assertSame($canonical->totals[$totalKey], $answer['totals'][$totalKey]);
            }
            $this->assertLessThanOrEqual(5, count($answer['rows']));
            $this->assertStringContainsString('/reports/'.$key, $answer['source_url']);
            $this->assertSame($key, $answer['report_key']);
        }
        Http::assertNothingSent();
    }

    public function test_sales_totals_cover_all_rows_and_preserve_native_currencies_without_economic_writes(): void
    {
        for ($index = 0; $index < 6; $index++) {
            $this->invoice('10.25', '3.50', 'USD');
        }
        $this->invoice('5.125', '1', 'ILS');
        $this->invoice('3.125', '5', 'JOD');
        $tables = ['sales_invoices', 'sales_invoice_lines', 'posting_batches', 'posting_lines', 'stock_movements', 'document_sequences'];
        $before = $this->counts($tables);
        $plan = $this->plan('sales.summary');
        $canonical = app(ReportRegistry::class)->execute($this->company, 'sales.summary', $plan->queryFilters());
        $answer = app(AssistantReportExecutor::class)->execute($this->company, $this->owner, $plan)->toArray();
        $this->assertCount(5, $answer['rows']);
        $this->assertSame(8, $answer['total_rows']);
        $this->assertSame('ILS', $answer['currency']['base_currency_code']);
        $this->assertSame($canonical->totals['net_sales_base'], $answer['totals']['net_sales_base']);
        $this->assertSame($canonical->totals['currencies'], $answer['totals']['currencies']);
        $this->assertSame(['ILS', 'JOD', 'USD'], array_keys($answer['totals']['currencies']));
        $this->assertSame($before, $this->counts($tables));
        Http::assertNothingSent();
    }

    public function test_customer_names_and_balances_are_localized_and_original_currency_truth(): void
    {
        $this->invoice('10.25', '3.50', 'USD');
        $this->invoice('3.125', '5', 'JOD');
        foreach (['ar' => $this->customer->name_ar, 'en' => $this->customer->name_en] as $locale => $name) {
            app()->setLocale($locale);
            $answer = app(AssistantReportExecutor::class)->execute($this->company, $this->owner,
                $this->plan('customers.balances'))->toArray();
            $this->assertSame(['JOD' => '3.125000', 'USD' => '10.250000'], $answer['totals']['outstanding_by_currency']);
            $this->assertSame($name, $answer['rows'][0]['customer_name']);
            $this->assertTrue($answer['is_current']);
        }
        Http::assertNothingSent();
    }

    public function test_nullable_and_unavailable_money_and_expiry_preserve_null_and_false(): void
    {
        // 1. Expiry with null expiry date
        $expiryPlan = $this->plan('inventory.expiry');
        $expiryResult = new ReportResult(
            reportType: 'inventory.expiry',
            filters: ['period' => ['preset' => 'this_month', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'timezone' => (string) $this->company->timezone]],
            totals: [
                'expired_count' => 0,
                'soon_count' => 1,
                'valid_count' => 2,
                'unknown_count' => 1,
                'total_active_lots' => 4,
            ],
            rows: [
                [
                    'product_name' => 'Lot Product',
                    'sku' => 'LOT-SKU',
                    'warehouse_name' => 'Main Warehouse',
                    'lot_number' => 'LOT-001',
                    'expiry_date' => null,
                    'expiry_status' => 'unknown',
                    'remaining_quantity' => '10.000000',
                ],
            ],
            currency: ['base_currency_code' => 'ILS'],
            pagination: ['total' => 1, 'per_page' => 5, 'current_page' => 1, 'last_page' => 1],
            generatedAt: '2026-10-12T00:00:00+03:00'
        );
        $expiryAnswer = app(AssistantReportExecutor::class)->project($this->company, $this->owner, $expiryPlan, $expiryResult)->toArray();
        $this->assertNull($expiryAnswer['rows'][0]['expiry_date']);
        $this->assertSame('10.000000', $expiryAnswer['rows'][0]['remaining_quantity']);

        // 2. Money with null native balance, false availability, and null currency total
        $moneyPlan = $this->plan('money.balances');
        $moneyResult = new ReportResult(
            reportType: 'money.balances',
            filters: ['period' => ['preset' => 'today', 'start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'timezone' => (string) $this->company->timezone]],
            totals: [
                'total_base_balance' => '100.000000',
                'currency_balances' => ['USD' => null, 'ILS' => '0.000000'],
                'accounts_count' => 2,
            ],
            rows: [
                [
                    'name' => 'USD Bank Account',
                    'account_type' => 'bank',
                    'currency_code' => 'USD',
                    'balance_currency' => null,
                    'balance_base' => '50.000000',
                    'currency_balance_available' => false,
                    'is_active' => false,
                ],
            ],
            currency: ['base_currency_code' => 'ILS'],
            pagination: ['total' => 1, 'per_page' => 5, 'current_page' => 1, 'last_page' => 1],
            generatedAt: '2026-10-12T00:00:00+03:00'
        );
        $moneyAnswer = app(AssistantReportExecutor::class)->project($this->company, $this->owner, $moneyPlan, $moneyResult)->toArray();
        $this->assertNull($moneyAnswer['rows'][0]['balance_currency']);
        $this->assertFalse($moneyAnswer['rows'][0]['currency_balance_available']);
        $this->assertFalse($moneyAnswer['rows'][0]['is_active']);
        $this->assertNull($moneyAnswer['totals']['currency_balances']['USD']);
        $this->assertSame('0.000000', $moneyAnswer['totals']['currency_balances']['ILS']);
    }

    public function test_frozen_nested_period_source_and_metadata(): void
    {
        $plan = $this->plan('sales.summary');
        $result = new ReportResult(
            reportType: 'sales.summary',
            filters: [
                'period' => [
                    'preset' => 'this_month',
                    'start_date' => '2026-10-01',
                    'end_date' => '2026-10-31',
                    'timezone' => (string) $this->company->timezone,
                ],
            ],
            totals: [
                'gross_sales_base' => '0.000000',
                'net_sales_base' => '0.000000',
                'tax_base' => '0.000000',
                'currencies' => [],
            ],
            rows: [],
            currency: ['base_currency_code' => 'ILS'],
            pagination: ['total' => 0, 'per_page' => 5, 'current_page' => 1, 'last_page' => 1],
            generatedAt: '2026-10-12T00:00:00+03:00'
        );
        $answer = app(AssistantReportExecutor::class)->project($this->company, $this->owner, $plan, $result)->toArray();

        $queryStr = parse_url($answer['source_url'], PHP_URL_QUERY);
        $this->assertNotNull($queryStr);
        parse_str($queryStr, $params);

        $this->assertArrayHasKey('filters', $params);
        $this->assertSame('custom', $params['filters']['preset']);
        $this->assertSame('2026-10-01', $params['filters']['from']);
        $this->assertSame('2026-10-31', $params['filters']['to']);
        $this->assertArrayNotHasKey('from', $params);
        $this->assertArrayNotHasKey('to', $params);

        $this->assertSame('2026-10-01', $answer['period']['start_date']);
        $this->assertSame('2026-10-31', $answer['period']['end_date']);
        $this->assertSame('2026-10-31', $answer['as_of']);
        $this->assertSame((string) $this->company->timezone, $answer['timezone']);
    }

    public function test_malicious_nested_extra_fields_and_sensitive_columns_are_stripped(): void
    {
        $plan = $this->plan('sales.summary');
        $result = new ReportResult(
            reportType: 'sales.summary',
            filters: ['period' => ['preset' => 'this_month', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'timezone' => (string) $this->company->timezone]],
            totals: [
                'gross_sales_base' => '100.000000',
                'net_sales_base' => '100.000000',
                'tax_base' => '0.000000',
                'currencies' => [
                    'USD' => [
                        'currency_code' => 'USD',
                        'gross_sales' => '100.000000',
                        'discounts' => '0.000000',
                        'returns' => '0.000000',
                        'commercial_net_sales' => '100.000000',
                        'net_sales' => '100.000000',
                        'tax' => '0.000000',
                        'invoice_count' => 1,
                        'cogs' => '50.000000',
                        'profit' => '50.000000',
                        'secret_internal_meta' => 'leak',
                    ],
                ],
                'unauthorized_financial_metric' => 'leak',
            ],
            rows: [
                [
                    'document_number' => 'INV-001',
                    'business_date' => '2026-10-05',
                    'event_type' => 'invoice',
                    'currency_code' => 'USD',
                    'gross_sales_base' => '100.000000',
                    'returns_base' => '0.000000',
                    'net_sales_base' => '100.000000',
                    'tax_base' => '0.000000',
                    'customer_id' => 999,
                    'company_id' => 1,
                    'raw_sql' => 'DROP TABLE',
                ],
            ],
            currency: ['base_currency_code' => 'ILS'],
            pagination: ['total' => 1, 'per_page' => 5, 'current_page' => 1, 'last_page' => 1],
            generatedAt: '2026-10-12T00:00:00+03:00'
        );
        $answer = app(AssistantReportExecutor::class)->project($this->company, $this->owner, $plan, $result)->toArray();

        $this->assertArrayNotHasKey('unauthorized_financial_metric', $answer['totals']);
        $this->assertArrayNotHasKey('customer_id', $answer['rows'][0]);
        $this->assertArrayNotHasKey('company_id', $answer['rows'][0]);
        $this->assertArrayNotHasKey('raw_sql', $answer['rows'][0]);

        $usdEntry = $answer['totals']['currencies']['USD'];
        $this->assertSame('100.000000', $usdEntry['gross_sales']);
        $this->assertArrayNotHasKey('cogs', $usdEntry);
        $this->assertArrayNotHasKey('profit', $usdEntry);
        $this->assertArrayNotHasKey('secret_internal_meta', $usdEntry);
    }

    public function test_byte_bounds_fail_closed(): void
    {
        $plan = $this->plan('sales.summary');
        $result = new ReportResult(
            reportType: 'sales.summary',
            filters: ['period' => ['preset' => 'this_month', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'timezone' => (string) $this->company->timezone]],
            totals: [
                'gross_sales_base' => str_repeat('1', 70000),
                'net_sales_base' => '0.000000',
                'tax_base' => '0.000000',
                'currencies' => [],
            ],
            rows: [],
            currency: ['base_currency_code' => 'ILS'],
            pagination: ['total' => 0, 'per_page' => 5, 'current_page' => 1, 'last_page' => 1],
            generatedAt: '2026-10-12T00:00:00+03:00'
        );

        $this->expectException(AssistantUnavailable::class);
        app(AssistantReportExecutor::class)->project($this->company, $this->owner, $plan, $result);
    }

    public function test_stale_company_attributes_are_ignored_and_persisted_company_used(): void
    {
        $plan = $this->plan('sales.summary');
        $staleCompany = clone $this->company;
        $staleCompany->timezone = 'America/New_York';
        $staleCompany->base_currency_code = 'EUR';

        $result = new ReportResult(
            reportType: 'sales.summary',
            filters: ['period' => ['preset' => 'this_month', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'timezone' => (string) $this->company->timezone]],
            totals: [
                'gross_sales_base' => '0.000000',
                'net_sales_base' => '0.000000',
                'tax_base' => '0.000000',
                'currencies' => [],
            ],
            rows: [],
            currency: ['base_currency_code' => 'ILS'],
            pagination: ['total' => 0, 'per_page' => 5, 'current_page' => 1, 'last_page' => 1],
            generatedAt: '2026-10-12T00:00:00+03:00'
        );

        $answer = app(AssistantReportExecutor::class)->project($staleCompany, $this->owner, $plan, $result)->toArray();
        $this->assertSame((string) $this->company->timezone, $answer['timezone']);
        $this->assertSame((string) $this->company->base_currency_code, $answer['currency']['base_currency_code']);
    }

    public function test_purchasing_and_vendor_queries_deny_without_purchasing_cost_view(): void
    {
        $restrictedUser = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $restrictedUser->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $restrictedUser->givePermissionTo(['ai.assistant.use', 'reports.purchases.view', 'purchasing.purchase.view', 'vendors.statement.view']);

        $purchasesPlan = $this->plan('purchases.summary');

        $this->expectException(AuthorizationException::class);
        app(AssistantReportExecutor::class)->execute($this->company, $restrictedUser, $purchasesPlan);
    }

    private function plan(string $key): AssistantReportPlan
    {
        $current = app(AssistantReportCatalog::class)->definition($key)['current'];

        return AssistantReportPlan::fromArray(['version' => 1, 'type' => 'report_query', 'report_key' => $key,
            'filters' => $current ? [] : ['preset' => 'this_month'], 'display' => 'summary']);
    }

    /** @param list<string> $tables
     * @return array<string, int>
     */
    private function counts(array $tables): array
    {
        return array_combine($tables, array_map(fn (string $table): int => DB::table($table)->count(), $tables));
    }
}
