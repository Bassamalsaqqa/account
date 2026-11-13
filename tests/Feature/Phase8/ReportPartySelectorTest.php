<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Application\Reporting\Presentation\ReportFilterOptions;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Livewire\Pages\Reporting\ReportView;
use App\Livewire\Pages\Sales\CustomerStatementView;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\EnforcesDisposableDatabaseOwnership;

final class ReportPartySelectorTest extends Phase8TestCase
{
    use EnforcesDisposableDatabaseOwnership;

    protected function setUp(): void
    {
        if (getenv('PHASE8_ALLOW_DISPOSABLE_DB') === '1') {
            $this->assertOwnedDisposableDatabase();
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->isDisposableDatabaseOwnershipAdmitted()) {
            $this->revalidateOwnedDisposableDatabase();
        }
        parent::tearDown();
    }

    public function test_custom_statement_dates_show_inline_errors_and_recover(): void
    {
        $component = Livewire::test(ReportView::class, ['reportKey' => 'customers.statement'])
            ->set('filters.preset', 'custom')->set('filters.from', '')->set('filters.to', '')
            ->call('applyFilters')->assertHasErrors(['filters.from', 'filters.to']);
        $component->set('filters.from', '2026-11-10')->set('filters.to', '2026-11-01')
            ->call('applyFilters')->assertHasErrors('filters.to');
        $component->set('filters.to', '2026-11-11')->call('applyFilters')->assertHasNoErrors();
    }

    public function test_statement_selectors_use_shared_bounded_search_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach (['customers.statement' => 'customer_id', 'vendors.statement' => 'vendor_id'] as $key => $field) {
                Livewire::test(ReportView::class, ['reportKey' => $key])
                    ->assertSee('report-'.$field, false)
                    ->set('selectorSearch.'.$field, str_repeat('x', 101))
                    ->assertHasErrors('selectorSearch');
            }
        }
    }

    public function test_lookup_party_livewire_endpoint_enforces_field_and_query_length(): void
    {
        $component = Livewire::test(ReportView::class, ['reportKey' => 'customers.statement']);

        // Unsupported arbitrary field throws 422
        $component->call('lookupParty', 'unsupported_field', '')->assertStatus(422);

        // Allowed field for reports, but NOT in customers.statement filters (e.g. employee_id) throws 422
        $component->call('lookupParty', 'employee_id', '')->assertStatus(422);

        // Query exceeding 100 characters throws 422
        $component->call('lookupParty', 'customer_id', str_repeat('a', 101))->assertStatus(422);

        // Valid query length <= 100 on supported field succeeds
        $component->call('lookupParty', 'customer_id', str_repeat('a', 100))->assertStatus(200);
    }

    public function test_lookup_party_livewire_endpoint_covers_customer_vendor_and_employee(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل تجريبي', 'name_en' => 'Test Customer', 'active' => true, 'created_by' => $this->owner->id]);
        $vendor = Vendor::create(['company_id' => $this->company->id, 'name_ar' => 'مورد تجريبي', 'name_en' => 'Test Vendor', 'active' => true, 'created_by' => $this->owner->id]);
        $employee = Employee::create(['company_id' => $this->company->id, 'name' => 'Test Employee', 'active' => true, 'created_by' => $this->owner->id]);

        // Customer statement callsite
        $customerComponent = Livewire::test(ReportView::class, ['reportKey' => 'customers.statement']);
        $customerComponent->assertSee('report-customer_id', false);
        $customerResults = $customerComponent->instance()->lookupParty('customer_id', 'Test Customer');
        $this->assertNotEmpty($customerResults);
        $this->assertSame((string) $customer->id, $customerResults[0]['id']);
        $this->assertStringContainsString('Test Customer', $customerResults[0]['label']);

        // Vendor statement callsite
        $vendorComponent = Livewire::test(ReportView::class, ['reportKey' => 'vendors.statement']);
        $vendorComponent->assertSee('report-vendor_id', false);
        $vendorResults = $vendorComponent->instance()->lookupParty('vendor_id', 'Test Vendor');
        $this->assertNotEmpty($vendorResults);
        $this->assertSame((string) $vendor->id, $vendorResults[0]['id']);
        $this->assertStringContainsString('Test Vendor', $vendorResults[0]['label']);

        // Payroll summary callsite
        $payrollComponent = Livewire::test(ReportView::class, ['reportKey' => 'payroll.summary']);
        $payrollComponent->assertSee('report-employee_id', false);
        $payrollResults = $payrollComponent->instance()->lookupParty('employee_id', 'Test Employee');
        $this->assertNotEmpty($payrollResults);
        $this->assertSame((string) $employee->id, $payrollResults[0]['id']);
        $this->assertStringContainsString('Test Employee', $payrollResults[0]['label']);
    }

    public function test_party_lookup_bounds_results_and_retains_selected_label(): void
    {
        app()->setLocale('en');
        $selected = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'مختار', 'name_en' => 'ZZ Selected', 'active' => true, 'created_by' => $this->owner->id]);
        for ($i = 0; $i < 30; $i++) {
            Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل '.$i, 'name_en' => sprintf('Customer %02d', $i), 'active' => true, 'created_by' => $this->owner->id]);
        }
        $definition = app(ReportRegistry::class)->definition('customers.statement');
        DB::enableQueryLog();
        $options = app(ReportFilterOptions::class)->forReport($this->company, $definition, ['customer_id' => $selected->id]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(26, $options['customer_id']);
        $this->assertSame((string) $selected->id, $options['customer_id'][0]['id']);
        $partyQueries = array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'from `customers`'));
        $this->assertCount(2, $partyQueries);
        $this->assertStringContainsString('limit 25', array_values($partyQueries)[0]['query']);
    }

    public function test_customer_detail_statement_rechecks_membership_on_refresh(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل', 'name_en' => 'Customer', 'active' => true, 'created_by' => $this->owner->id]);
        $component = Livewire::test(CustomerStatementView::class, ['publicId' => $customer->public_id]);
        $component->set('fromDate', '')->assertHasErrors('fromDate');
        $this->company->memberships()->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        $component->call('$refresh')->assertStatus(403);
    }

    public function test_party_lookup_and_statement_reject_foreign_party(): void
    {
        $context = app(CompanyContext::class);
        $context->clear();
        $otherOwner = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة أخرى',
            'base_currency_code' => 'ILS',
        ]);
        $context->setCompany($otherCompany, $otherOwner);
        $foreignCustomer = Customer::create([
            'company_id' => $otherCompany->id,
            'name_ar' => 'عميل أجنبي',
            'name_en' => 'Foreign Customer',
            'active' => true,
            'created_by' => $otherOwner->id,
        ]);
        $context->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        $definition = app(ReportRegistry::class)->definition('customers.statement');
        $options = app(ReportFilterOptions::class)->forReport($this->company, $definition, ['customer_id' => $foreignCustomer->id]);
        $this->assertEmpty($options['customer_id']);

        Livewire::test(CustomerStatementView::class, ['publicId' => $foreignCustomer->public_id])
            ->assertStatus(404);
    }

    public function test_report_view_denies_revoked_membership(): void
    {
        $this->company->memberships()->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        Livewire::test(ReportView::class, ['reportKey' => 'customers.statement'])
            ->assertStatus(403);
    }

    public function test_limited_roles_cannot_view_unauthorized_statement(): void
    {
        $limitedUser = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $limitedUser->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        $this->actingAs($limitedUser);
        app(CompanyContext::class)->setCompany($this->company, $limitedUser);

        Livewire::test(ReportView::class, ['reportKey' => 'customers.statement'])
            ->assertStatus(403);
    }

    public function test_report_export_with_party_filter_and_revalidates_permissions(): void
    {
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل تقرير',
            'name_en' => 'Report Customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->get(route('reports.export', [
            'reportKey' => 'customers.statement',
            'filters' => [
                'customer_id' => $customer->id,
                'preset' => 'this_month',
            ],
        ]));
        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $invalidResponse = $this->get(route('reports.export', [
            'reportKey' => 'customers.statement',
            'filters' => ['preset' => 'this_month'],
        ]));
        $invalidResponse->assertStatus(422);
    }

    public function test_dates_default_in_company_timezone(): void
    {
        $this->company->update(['timezone' => 'Asia/Riyadh']);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل توقيت',
            'name_en' => 'Timezone Customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $component = Livewire::test(CustomerStatementView::class, ['publicId' => $customer->public_id]);
        $expectedFrom = Carbon::now('Asia/Riyadh')->startOfYear()->toDateString();
        $expectedTo = Carbon::now('Asia/Riyadh')->toDateString();

        $this->assertSame($expectedFrom, $component->get('fromDate'));
        $this->assertSame($expectedTo, $component->get('toDate'));
    }

    public function test_customer_statement_maintains_exact_balances_and_currency_separation(): void
    {
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل أرصدة',
            'name_en' => 'Balance Customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        // Canonical non-empty balance fixture
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'currency_code' => 'USD',
            'exchange_rate' => '3.50',
            'issue_date' => '2026-10-01',
            'lines' => [['product_id' => null, 'item_description' => 'Test Service', 'quantity' => '1', 'unit_price' => '150.00']],
        ]);
        app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);

        $statementQuery = app(CustomerStatementQuery::class);
        $result = $statementQuery->execute($customer);

        $this->assertArrayHasKey('currencies', $result);
        $this->assertNotEmpty($result['currencies']);
        $currencyStatement = $result['currencies'][0];
        $this->assertSame('USD', $currencyStatement['currency']);
        $this->assertSame('150.00', $currencyStatement['closing_balance']);
        $this->assertSame('150.00', $currencyStatement['total_debits']);
        $this->assertSame('0.00', $currencyStatement['total_credits']);
        $this->assertSame('0.00', $currencyStatement['opening_balance']);
    }

    public function test_lookup_party_livewire_endpoint_rejects_foreign_party(): void
    {
        $context = app(CompanyContext::class);
        $context->clear();
        $otherOwner = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة أجنبية',
            'base_currency_code' => 'ILS',
        ]);
        $context->setCompany($otherCompany, $otherOwner);
        $foreignCustomer = Customer::create([
            'company_id' => $otherCompany->id,
            'name_ar' => 'عميل أجنبي',
            'name_en' => 'Foreign Customer',
            'active' => true,
            'created_by' => $otherOwner->id,
        ]);
        $context->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        $component = Livewire::test(ReportView::class, ['reportKey' => 'customers.statement'])
            ->set('filters.customer_id', (string) $foreignCustomer->id);

        $results = $component->instance()->lookupParty('customer_id', '');
        $ids = array_column($results, 'id');
        $this->assertNotContains((string) $foreignCustomer->id, $ids);
    }

    public function test_lookup_party_livewire_endpoint_enforces_master_view_permissions(): void
    {
        Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل سري', 'name_en' => 'Secret Customer', 'active' => true, 'created_by' => $this->owner->id]);

        $limitedUser = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $limitedUser->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        $limitedUser->givePermissionTo('reports.customers.view');
        $this->actingAs($limitedUser);
        app(CompanyContext::class)->setCompany($this->company, $limitedUser);

        $component = Livewire::test(ReportView::class, ['reportKey' => 'customers.statement']);
        $results = $component->instance()->lookupParty('customer_id', '');
        $this->assertEmpty($results);

        $limitedUser->givePermissionTo('customers.view');
        $resultsAfterGrant = $component->instance()->lookupParty('customer_id', '');
        $this->assertNotEmpty($resultsAfterGrant);
    }

    public function test_lookup_party_livewire_endpoint_denies_revoked_membership(): void
    {
        $component = Livewire::test(ReportView::class, ['reportKey' => 'customers.statement']);
        $this->company->memberships()->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        $component->call('lookupParty', 'customer_id', '')->assertStatus(403);
    }

    public function test_combobox_markup_and_localized_labels_in_both_locales(): void
    {
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل عربي',
            'name_en' => 'English Customer',
            'code' => 'C-100',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        // Arabic locale
        app()->setLocale('ar');
        $arComponent = Livewire::test(ReportView::class, ['reportKey' => 'customers.statement']);
        $arComponent->assertSeeHtml('role="combobox"')
            ->assertSeeHtml('role="listbox"')
            ->assertSeeHtml('dir="auto"')
            ->assertSeeHtml('min-h-[44px]');
        $arResults = $arComponent->instance()->lookupParty('customer_id', '');
        $this->assertSame('عميل عربي (C-100)', $arResults[0]['label']);

        // English locale
        app()->setLocale('en');
        $enComponent = Livewire::test(ReportView::class, ['reportKey' => 'customers.statement']);
        $enResults = $enComponent->instance()->lookupParty('customer_id', '');
        $this->assertSame('English Customer (C-100)', $enResults[0]['label']);
    }
}
