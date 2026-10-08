<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Payroll\PostSalaryEntryAction;
use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Presentation\DashboardReports;
use App\Application\Reporting\Presentation\ReportPresenter;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Livewire\Pages\Reporting\ReportHub;
use App\Livewire\Pages\Reporting\ReportView;
use App\Models\Customer;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

final class UiReportPresentationTest extends Phase8TestCase
{
    public function test_hub_discovers_all_required_families_and_variants_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $component = Livewire::test(ReportHub::class);
            $component->assertSee(__('reports.title'));
            $visible = app(ReportRegistry::class)->visible($this->company);
            $this->assertGreaterThanOrEqual(65, count($visible));
            foreach (['sales.by-period', 'customers.statement', 'purchases.price-history', 'vendors.aging', 'inventory.adjustments', 'money.incoming-checks', 'money.due-checks', 'expenses.trend', 'expenses.fuel', 'payroll.outstanding-advances', 'profit'] as $key) {
                $component->assertSee(app(ReportRegistry::class)->title($key));
                $this->assertArrayHasKey($key, $visible);
            }
        }
    }

    public function test_every_registered_report_renders_without_exception_using_valid_required_selections(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل التقارير', 'name_en' => 'Report Customer', 'active' => true, 'created_by' => $this->owner->id]);
        foreach (app(ReportRegistry::class)->visible($this->company) as $key => $definition) {
            $filters = [];
            foreach ($definition['required'] as $field) {
                $filters[$field] = match ($field) {
                    'customer_id' => $customer->id,'vendor_id' => $this->vendor->id,'employee_id' => $this->employee->id,'money_account_id' => $this->ilsCashAccount->id
                };
            }
            $component = Livewire::withQueryParams(['filters' => $filters])->test(ReportView::class, ['reportKey' => $key]);
            $component->assertStatus(200)->assertSee(app(ReportRegistry::class)->title($key));
            $this->assertFalse($component->instance()->getErrorBag()->has('filters'), 'Unexpected report filter failure: '.$key);
        }
    }

    public function test_current_customer_balance_does_not_display_a_historical_filter(): void
    {
        Livewire::test(ReportView::class, ['reportKey' => 'customers.balances'])->assertDontSee('id="report-preset"', false)->assertHasNoErrors();
    }

    public function test_invalid_filter_is_a_form_error_and_financial_result_is_not_public_state(): void
    {
        $component = Livewire::test(ReportView::class, ['reportKey' => 'sales.summary'])->set('filters.customer_id', 'not-a-number')->call('applyFilters');
        $component->assertHasErrors('filters')->assertSee(__('reports.error'));
        $state = $component->instance()->all();
        $this->assertArrayNotHasKey('result', $state);
        $this->assertArrayNotHasKey('totals', $state);
        $this->assertArrayNotHasKey('rows', $state);
    }

    public function test_permission_revocation_closes_an_open_report_and_csv_access(): void
    {
        $component = Livewire::test(ReportView::class, ['reportKey' => 'profit'])->assertStatus(200);
        Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail()->revokePermissionTo('reports.cost.view');
        $component->call('$refresh')->assertStatus(403);
        $this->get(route('reports.export', ['reportKey' => 'profit']))->assertForbidden();
    }

    public function test_restricted_dashboard_does_not_query_denied_financial_domains(): void
    {
        $reader = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $reader->id, 'status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'ReportNavigationOnly', 'guard_name' => 'web']);
        $reader->assignRole($role);
        app(CompanyContext::class)->setCompany($this->company, $reader);
        $this->activateUser($reader);
        DB::enableQueryLog();
        $data = app(DashboardReports::class)->read($this->company, 'this_month');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $data['activity']);
        $this->assertSame([], $data['positions']);
        $this->assertSame([], $data['alerts']);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(posting_lines|sales_invoices|salary_entries|expenses|stock_movements|checks|customer_payments|vendor_payments)\b/i', $query['query']);
        }
        Livewire::test(ReportHub::class)->assertSee(__('reports.restricted'));
    }

    public function test_snapshot_locale_fallback_and_exact_decimal_presentation(): void
    {
        $presenter = app(ReportPresenter::class);
        $row = ['product_name_ar' => 'هوية تاريخية', 'product_name_en' => null, 'unit_price' => '12.345678', 'quantity' => '1.234', 'amount' => '0.001'];
        app()->setLocale('en');
        $this->assertSame('هوية تاريخية', $presenter->value($row, 'product_name'));
        foreach (['unit_price' => '12.345678', 'quantity' => '1.234', 'amount' => '0.001'] as $key => $value) {
            $this->assertSame($value, $presenter->value($row, $key));
        }
        $result = new ReportResult('inventory.stock', [], [], [['quantity_on_hand' => '1.000000']], ['base_currency_code' => 'ILS']);
        $columns = $presenter->columns([['key' => 'quantity_on_hand', 'type' => 'decimal'], ['key' => 'value_base', 'type' => 'decimal']], $result);
        $this->assertSame(['quantity_on_hand'], array_column($columns, 'key'));
    }

    public function test_columns_remain_stable_when_field_null_on_page_one_and_populated_on_page_two(): void
    {
        $presenter = app(ReportPresenter::class);
        $schema = [
            ['key' => 'document_number', 'type' => 'text'],
            ['key' => 'customer_code', 'type' => 'text'],
            ['key' => 'gross_sales_base', 'type' => 'decimal'],
        ];

        $page1Result = new ReportResult(
            'sales.summary',
            [],
            [],
            [
                ['document_number' => 'INV-001', 'customer_code' => null, 'gross_sales_base' => '100.000000'],
                ['document_number' => 'INV-002', 'customer_code' => null, 'gross_sales_base' => '200.000000'],
            ],
            ['base_currency_code' => 'ILS'],
            ['current_page' => 1, 'per_page' => 2, 'total' => 4, 'last_page' => 2]
        );

        $columns = $presenter->columns($schema, $page1Result);
        $keys = array_column($columns, 'key');
        $this->assertContains('customer_code', $keys, 'Customer code must not be dropped when null on page 1.');

        $page2Row = ['document_number' => 'INV-003', 'customer_code' => 'CUST-001', 'gross_sales_base' => '150.000000'];
        $exported = $presenter->exportRow($page2Row, $columns);
        $this->assertSame('CUST-001', $exported['customer_code']);
    }

    public function test_quantities_counts_and_percentages_are_not_labelled_as_money(): void
    {
        $presenter = app(ReportPresenter::class);
        $totals = [
            'product_count' => 5,
            'quantity_base' => '10.000000',
            'gross_margin' => '0.2500',
            'sales_revenue_base' => '1000.000000',
        ];

        $presented = $presenter->totals($totals, 'ILS');
        $byValue = [];
        foreach ($presented as $item) {
            $byValue[$item['value']] = $item['currency'];
        }

        $this->assertSame('', $byValue['5'], 'Count must not have a currency.');
        $this->assertSame('', $byValue['10.000000'], 'Quantity must not have a currency.');
        $this->assertSame('', $byValue['0.2500'], 'Margin percentage must not have a currency.');
        $this->assertSame('ILS', $byValue['1000.000000'], 'Monetary base amount must have base currency.');
    }

    public function test_native_currency_summary_preserves_currency_identity(): void
    {
        $totals = app(ReportPresenter::class)->totals([
            'currency_totals' => ['USD' => '100.000000', 'JOD' => '0.001000'],
        ], 'ILS');
        $this->assertSame(['USD', 'JOD'], array_column($totals, 'currency'));
    }

    public function test_dashboard_period_link_can_be_changed_by_report_controls(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $component = Livewire::withQueryParams(['filters' => ['period' => [
            'preset' => 'this_month', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'timezone' => $this->company->timezone,
        ]]])->test(ReportView::class, ['reportKey' => 'sales.summary']);
        $component->set('filters.preset', 'custom')->set('filters.from', '2026-09-01')->set('filters.to', '2026-09-30')->call('applyFilters');
        $result = $component->instance()->render()->getData()['result'];
        $this->assertSame('2026-09-01', $result->filters['period']['start_date']);
        $this->assertSame('2026-09-30', $result->filters['period']['end_date']);
    }

    public function test_custom_period_can_switch_back_to_preset(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $component = Livewire::withQueryParams(['filters' => ['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']])
            ->test(ReportView::class, ['reportKey' => 'sales.summary']);
        $component->set('filters.preset', 'this_month')->call('applyFilters');
        $result = $component->instance()->render()->getData()['result'];
        $this->assertSame('2026-10-01', $result->filters['period']['start_date']);
        $this->assertSame('2026-10-31', $result->filters['period']['end_date']);
    }

    public function test_payroll_statement_schema_exposes_actual_event_reference_and_native_amount(): void
    {
        app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'recognition_date' => '2026-09-30',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'currency_code' => 'USD', 'exchange_rate' => '3.50', 'base_salary' => '100',
            'bonus' => '0', 'deduction' => '0', 'advances' => [], 'idempotency_key' => 'codex-review-salary-perm',
        ]);
        $registry = app(ReportRegistry::class);
        $result = $registry->execute($this->company, 'payroll.statement', [
            'employee_id' => $this->employee->id, 'from' => '2026-09-01', 'to' => '2026-09-30',
        ]);
        $this->assertCount(1, $result->rows);
        $columns = app(ReportPresenter::class)->columns($registry->definition('payroll.statement')['columns'], $result);
        $keys = array_column($columns, 'key');
        $this->assertContains('reference_number', $keys);
        $this->assertContains('amount', $keys);
        $this->assertContains('source_type', $keys);
    }

    public function test_period_preset_change_clears_stale_dates_in_pagination_and_export_url(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $component = Livewire::withQueryParams(['filters' => ['preset' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30']])
            ->test(ReportView::class, ['reportKey' => 'sales.summary']);
        $component->set('filters.preset', 'today')->call('applyFilters');
        $viewData = $component->instance()->render()->getData();
        $this->assertStringNotContainsString('2026-09-01', $viewData['exportUrl']);
        $this->assertStringContainsString('today', $viewData['exportUrl']);
    }

    public function test_nested_native_currency_summary_and_ui_rendering_in_ar_and_en(): void
    {
        $presenter = app(ReportPresenter::class);
        $totals = [
            'base_net_sales' => '1000.000000',
            'total_invoices' => 3,
            'currencies' => [
                'USD' => ['net_sales' => '100.000000', 'invoice_count' => 1],
                'JOD' => ['net_sales' => '50.000000', 'invoice_count' => 2],
            ],
        ];

        $presented = $presenter->totals($totals, 'ILS');
        $this->assertSame('1000.000000', $presented[0]['value']);
        $this->assertSame('ILS', $presented[0]['currency']);
        $this->assertSame('3', $presented[1]['value']);
        $this->assertSame('', $presented[1]['currency']);

        $this->assertSame('100.000000', $presented[2]['value']);
        $this->assertSame('USD', $presented[2]['currency']);
        $this->assertSame('1', $presented[3]['value']);
        $this->assertSame('', $presented[3]['currency']);

        $this->assertSame('50.000000', $presented[4]['value']);
        $this->assertSame('JOD', $presented[4]['currency']);
        $this->assertSame('2', $presented[5]['value']);
        $this->assertSame('', $presented[5]['currency']);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $rendered = view('livewire.pages.reporting.report', [
                'result' => new ReportResult('sales.summary', ['period' => ['start_date' => '2026-10-01', 'end_date' => '2026-10-31']], $totals, [], ['base_currency_code' => 'ILS']),
                'columns' => [],
                'totals' => $presented,
                'definition' => ['current' => false, 'required' => [], 'options' => []],
                'options' => [],
                'title' => 'Test Report',
                'exportUrl' => 'http://localhost/test',
                'missing' => false,
                'company' => $this->company,
                'sourceLinks' => [],
                'errors' => new ViewErrorBag,
            ])->render();

            $this->assertStringContainsString('100.000000 USD', $rendered);
            $this->assertStringContainsString('50.000000 JOD', $rendered);
            $this->assertStringContainsString('1000.000000 ILS', $rendered);
        }
    }

    public function test_bookmarked_explicit_period_freezes_as_custom_and_preserves_dates(): void
    {
        Carbon::setTestNow('2026-11-15 09:00:00');
        $component = Livewire::withQueryParams(['filters' => ['period' => [
            'preset' => 'this_month', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
            'timezone' => $this->company->timezone,
        ]]])->test(ReportView::class, ['reportKey' => 'sales.summary']);

        $result = $component->instance()->render()->getData()['result'];
        $this->assertNotNull($result);
        $this->assertSame('2026-10-01', $result->filters['period']['start_date']);
        $this->assertSame('2026-10-31', $result->filters['period']['end_date']);
        $this->assertSame('custom', $component->get('filters.preset'));
        $this->assertSame('2026-10-01', $component->get('filters.from'));
        $this->assertSame('2026-10-31', $component->get('filters.to'));
    }

    public function test_malformed_nested_period_shows_controlled_error_and_no_result(): void
    {
        $component = Livewire::withQueryParams(['filters' => ['period' => [
            'preset' => 'this_month', 'start_date' => 'bad-date', 'end_date' => '2026-10-31',
        ]]])->test(ReportView::class, ['reportKey' => 'sales.summary']);

        $component->assertHasErrors('filters');
        $data = $component->instance()->render()->getData();
        $this->assertNull($data['result']);
        $this->assertEmpty($data['totals']);
    }

    public function test_explicit_bookmark_matching_initial_clock_normalizes_to_custom_and_preserves_after_clock_advances(): void
    {
        Carbon::setTestNow('2026-10-10 12:00:00');
        $component = Livewire::withQueryParams(['filters' => ['period' => [
            'preset' => 'this_month', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
            'timezone' => $this->company->timezone,
        ]]])->test(ReportView::class, ['reportKey' => 'sales.summary']);

        $first = $component->instance()->render()->getData()['result'];
        $this->assertNotNull($first);
        $this->assertSame('2026-10-01', $first->filters['period']['start_date']);
        $this->assertSame('2026-10-31', $first->filters['period']['end_date']);
        $this->assertSame('custom', $component->get('filters.preset'));
        $this->assertSame('2026-10-01', $component->get('filters.from'));
        $this->assertSame('2026-10-31', $component->get('filters.to'));

        $firstExportDecoded = urldecode($component->instance()->render()->getData()['exportUrl']);
        $this->assertStringContainsString('filters[from]=2026-10-01', $firstExportDecoded);
        $this->assertStringContainsString('filters[to]=2026-10-31', $firstExportDecoded);
        $this->assertStringContainsString('filters[preset]=custom', $firstExportDecoded);

        Carbon::setTestNow('2026-11-10 12:00:00');
        $component->call('$refresh');
        $next = $component->instance()->render()->getData()['result'];
        $this->assertNotNull($next);
        $this->assertSame('2026-10-01', $next->filters['period']['start_date']);
        $this->assertSame('2026-10-31', $next->filters['period']['end_date']);
        $this->assertSame('custom', $component->get('filters.preset'));
        $this->assertSame('2026-10-01', $component->get('filters.from'));
        $this->assertSame('2026-10-31', $component->get('filters.to'));

        $nextExportDecoded = urldecode($component->instance()->render()->getData()['exportUrl']);
        $this->assertStringContainsString('filters[from]=2026-10-01', $nextExportDecoded);
        $this->assertStringContainsString('filters[to]=2026-10-31', $nextExportDecoded);
        $this->assertStringContainsString('filters[preset]=custom', $nextExportDecoded);
    }

    public function test_nested_period_timezone_metadata_normalizes_to_company_timezone_without_shifting_dates(): void
    {
        $filters = ReportFilters::fromArray($this->company, ['period' => [
            'preset' => 'custom', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
            'timezone' => 'Pacific/Honolulu',
        ]]);

        $this->assertSame($this->company->timezone, $filters->period->timezone);
        $this->assertSame('2026-10-01', $filters->period->startDate);
        $this->assertSame('2026-10-31', $filters->period->endDate);

        $dto = new ReportPeriod('custom', '2026-10-01', '2026-10-31', 'America/New_York');
        $filtersDto = ReportFilters::fromArray($this->company, ['period' => $dto]);
        $this->assertSame($this->company->timezone, $filtersDto->period->timezone);
        $this->assertSame('2026-10-01', $filtersDto->period->startDate);
        $this->assertSame('2026-10-31', $filtersDto->period->endDate);

        $this->expectException(InvalidReportFilterException::class);
        ReportFilters::fromArray($this->company, ['period' => [
            'preset' => 'custom', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
            'timezone' => 'Invalid/NonExistentZone',
        ]]);
    }
}
