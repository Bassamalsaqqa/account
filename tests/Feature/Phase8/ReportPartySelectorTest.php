<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Company\CreateCompanyAction;
use App\Application\Reporting\Presentation\ReportFilterOptions;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Livewire\Pages\Reporting\ReportView;
use App\Livewire\Pages\Sales\CustomerStatementView;
use App\Models\Customer;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

final class ReportPartySelectorTest extends Phase8TestCase
{
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
                    ->assertSee('report-selector-'.$field, false)
                    ->set('selectorSearch.'.$field, str_repeat('x', 101))
                    ->assertHasErrors('selectorSearch');
            }
        }
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

        $statementQuery = app(CustomerStatementQuery::class);
        $result = $statementQuery->execute($customer);

        $this->assertArrayHasKey('currencies', $result);
        foreach ($result['currencies'] as $currencyStatement) {
            $this->assertNotEmpty($currencyStatement['currency']);
            $this->assertMatchesRegularExpression('/^-?\d+(\.\d+)?$/', $currencyStatement['closing_balance']);
        }
    }
}
