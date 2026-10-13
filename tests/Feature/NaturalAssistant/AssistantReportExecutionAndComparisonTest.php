<?php

declare(strict_types=1);

namespace Tests\Feature\NaturalAssistant;

use App\Application\AI\AssistantReportCatalog;
use App\Application\AI\AssistantReportEntityResolver;
use App\Application\AI\AssistantReportExecutor;
use App\Application\AI\AssistantReportPlan;
use App\Application\AI\AssistantUnavailable;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Security\ReportingGuard;
use App\Models\AiCompanyPolicy;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class AssistantReportExecutionAndComparisonTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
        $this->travelTo(now()->setDate(2026, 10, 12));
        config([
            'ai_assistant.enabled' => true,
            'ai_assistant.questions.enabled' => true,
            'ai_assistant.provider_approved' => false,
            'ai_assistant.provider' => 'none',
        ]);
        Http::preventStrayRequests();
        AiCompanyPolicy::create([
            'company_id' => $this->company->id,
            'enabled' => true,
            'settings' => [
                'questions' => [
                    'enabled' => true,
                    'allowed_reports' => AssistantReportCatalog::KEYS,
                ],
            ],
        ]);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
    }

    public function test_prioritized_reports_execute_via_canonical_registry_and_produce_bounded_dto(): void
    {
        // Add sample transactions
        $this->invoice('100.00', '1', 'ILS');
        $this->invoice('200.00', '2', 'USD');

        $tables = ['sales_invoices', 'sales_invoice_lines', 'purchases', 'posting_batches', 'posting_lines', 'stock_movements', 'document_sequences'];
        $before = $this->economicFingerprint($tables);
        $reportsToTest = [];
        foreach (AssistantReportCatalog::KEYS as $key) {
            if ($key === 'sales.last-invoice') {
                continue; // Dedicated issued-document source has its own acceptance test.
            }
            $reportsToTest[$key] = app(AssistantReportCatalog::class)->definition($key)['current'] ?? false ? [] : ['preset' => 'this_month'];
        }
        $this->assertCount(23, $reportsToTest);

        foreach ($reportsToTest as $key => $filters) {
            $plan = AssistantReportPlan::fromArray([
                'version' => 1,
                'type' => 'report_query',
                'report_key' => $key,
                'filters' => $filters,
                'display' => 'summary',
            ]);

            $canonical = app(ReportRegistry::class)->execute($this->company, $key, AssistantReportEntityResolver::resolve($this->company, $this->owner, $plan));
            $this->assertContains($canonical->reportType, AssistantReportExecutor::EXPECTED_RESULT_TYPES[$key], $key);
            $dto = app(AssistantReportExecutor::class)->execute($this->company, $this->owner, $plan);
            $data = $dto->toArray();

            $this->assertSame($key, $data['report_key']);
            $this->assertNotEmpty($data['title']);
            $this->assertLessThanOrEqual(5, count($data['rows']));
            $this->assertStringContainsString('/reports/'.$key, $data['source_url']);
            $this->assertIsArray($data['totals']);
            if ($key === 'sales.summary') {
                $this->assertSame('500.000000', $data['totals']['net_sales_base']);
                $this->assertSame(2, $data['totals']['original_invoice_count']);
            }
        }
        $this->assertSame($before, $this->economicFingerprint($tables));
        Http::assertNothingSent();
    }

    public function test_comparison_mode_executes_two_adjacent_periods_and_appends_comparison_data(): void
    {
        $plan = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => 'sales.summary',
            'filters' => [
                'preset' => 'this_month',
                'compare' => 'previous_period',
            ],
            'display' => 'summary',
        ]);

        $dto = app(AssistantReportExecutor::class)->execute($this->company, $this->owner, $plan);
        $data = $dto->toArray();

        $this->assertNotNull($data['comparison']);
        $this->assertArrayHasKey('period', $data['comparison']);
        $this->assertArrayHasKey('totals', $data['comparison']);
        $this->assertArrayHasKey('source_url', $data['comparison']);
        $this->assertSame('2026-09-01', $data['comparison']['period']['start_date']);
        $this->assertSame('2026-09-30', $data['comparison']['period']['end_date']);
        $this->assertSame('custom', $data['comparison']['period']['preset']);
    }

    public function test_purchases_and_vendor_reports_deny_actors_without_purchasing_cost_view(): void
    {
        $restrictedUser = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $restrictedUser->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $restrictedUser->givePermissionTo([
            'ai.assistant.use',
            'reports.purchases.view',
            'purchasing.purchase.view',
            'vendors.statement.view',
            'vendors.view',
        ]);

        $this->actingAs($restrictedUser);
        app(CompanyContext::class)->setCompany($this->company, $restrictedUser);
        $reports = [
            'purchases.summary',
            'purchases.by-period',
            'purchases.by-vendor',
            'purchases.by-product',
            'purchases.unpaid',
            'vendors.balances',
            'vendors.aging',
        ];

        foreach ($reports as $key) {
            $definition = app(AssistantReportCatalog::class)->definition($key);
            $requiredPermissions = array_values(array_diff($definition['permissions'], ['purchasing.cost.view']));
            $restrictedUser->givePermissionTo($requiredPermissions);
            foreach ($requiredPermissions as $permission) {
                $this->assertTrue($restrictedUser->hasPermissionTo($permission), $key.': '.$permission);
            }
            $this->assertNotContains($key, app(AssistantReportCatalog::class)->permittedKeys($this->company, $restrictedUser));
            $this->assertFalse(app(ReportingGuard::class)->canViewPurchasingCost($restrictedUser));
            $isCurrent = app(AssistantReportCatalog::class)->definition($key)['current'] ?? false;
            $plan = AssistantReportPlan::fromArray([
                'version' => 1,
                'type' => 'report_query',
                'report_key' => $key,
                'filters' => $isCurrent ? [] : ['preset' => 'this_month'],
                'display' => 'summary',
            ]);

            try {
                app(AssistantReportExecutor::class)->execute($this->company, $restrictedUser, $plan);
                $this->fail("Expected AuthorizationException for {$key} without purchasing.cost.view.");
            } catch (AuthorizationException $e) {
                $this->assertSame(in_array('purchasing.cost.view', $definition['permissions'], true)
                    ? 'The required report/source permission is missing.'
                    : 'Purchasing cost read permission is required.', $e->getMessage());
            }
        }
    }

    public function test_source_url_contains_all_applied_canonical_filters(): void
    {
        $plan = AssistantReportPlan::fromArray([
            'version' => 1,
            'type' => 'report_query',
            'report_key' => 'sales.by-customer',
            'filters' => [
                'preset' => 'this_month',
                'currency_code' => 'ILS',
                'customer_name' => $this->customer->name_ar,
            ],
            'display' => 'summary',
        ]);

        $dto = app(AssistantReportExecutor::class)->execute($this->company, $this->owner, $plan);
        $sourceUrl = $dto->sourceUrl;

        $queryStr = parse_url($sourceUrl, PHP_URL_QUERY);
        $this->assertNotNull($queryStr);
        parse_str($queryStr, $params);

        $this->assertArrayHasKey('filters', $params);
        $this->assertSame((string) $this->customer->id, (string) $params['filters']['customer_id']);
        $this->assertSame('ILS', $params['filters']['currency_code']);
        $this->assertSame('custom', $params['filters']['preset']);
        $this->assertSame('2026-10-01', $params['filters']['from']);
        $this->assertSame('2026-10-31', $params['filters']['to']);
    }

    /** @param list<string> $tables @return array<string,string> */
    private function economicFingerprint(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
        }

        return $result;
    }

    #[DataProvider('calendarCases')]
    public function test_adjacent_comparison_is_anchored_to_actual_calendar_period(string $preset, string $start, string $end, string $previousStart, string $previousEnd): void
    {
        $result = new ReportResult('sales.summary', ['period' => ['preset' => $preset, 'start_date' => $start, 'end_date' => $end]], [], [], []);
        $method = new \ReflectionMethod(AssistantReportExecutor::class, 'resolvePreviousPeriod');
        $actual = $method->invoke(app(AssistantReportExecutor::class), $result, $this->company, [], 'previous_period');
        $this->assertSame(['start_date' => $previousStart, 'end_date' => $previousEnd], $actual);
    }

    public static function calendarCases(): array
    {
        return [
            ['last_month', '2024-03-01', '2024-03-31', '2024-02-01', '2024-02-29'],
            ['last_month', '2023-03-01', '2023-03-31', '2023-02-01', '2023-02-28'],
            ['last_month', '2024-02-01', '2024-02-29', '2024-01-01', '2024-01-31'],
            ['this_month', '2026-01-01', '2026-01-31', '2025-12-01', '2025-12-31'],
            ['this_quarter', '2026-01-01', '2026-03-31', '2025-10-01', '2025-12-31'],
            ['last_year', '2025-01-01', '2025-12-31', '2024-01-01', '2024-12-31'],
            ['last_week', '2026-01-05', '2026-01-11', '2025-12-29', '2026-01-04'],
            ['custom', '2024-03-01', '2024-03-31', '2024-01-30', '2024-02-29'],
            ['custom', '2026-10-05', '2026-10-08', '2026-10-01', '2026-10-04'],
            ['yesterday', '2026-01-01', '2026-01-01', '2025-12-31', '2025-12-31'],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_comparison_period_refuses_without_guessing(?array $period): void
    {
        $result = new ReportResult('sales.summary', $period === null ? [] : ['period' => $period], [], [], []);
        $this->expectException(AssistantUnavailable::class);
        $method = new \ReflectionMethod(AssistantReportExecutor::class, 'resolvePreviousPeriod');
        $method->invoke(app(AssistantReportExecutor::class), $result, $this->company, [], 'previous_period');
    }

    public static function invalidPeriods(): array
    {
        return [
            [null],
            [['preset' => 'custom', 'start_date' => '2026-02-30', 'end_date' => '2026-03-01']],
            [['preset' => 'custom', 'start_date' => '2026-10-08', 'end_date' => '2026-10-01']],
            [['preset' => 'unknown', 'start_date' => '2026-10-01', 'end_date' => '2026-10-02']],
        ];
    }
}
