<?php

declare(strict_types=1);

namespace Tests\Feature\Phase10;

use App\Actions\Company\CreateCompanyAction;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Presentation\ReportSourceNavigation;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase8\TradeTestCase;

final class ReportNavigationPerformanceTest extends TradeTestCase
{
    public function test_navigation_existence_preserves_visibility_with_fewer_owner_queries(): void
    {
        $this->activateUser($this->owner);
        $registry = app(ReportRegistry::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $visible = $registry->visible($this->company);
        $full = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->assertTrue($registry->hasVisible($this->company));
        $existence = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertNotEmpty($visible);
        $this->assertLessThan($full / 10, $existence);
        foreach ([[], ['reports.sales.view'], ['reports.sales.view', 'sales.invoice.view'],
            ['reports.money.view', 'money.cash.view'], ['reports.payroll.view', 'employees.view']] as $permissions) {
            $actor = $this->customActor($permissions);
            $this->activateUser($actor);
            $this->assertSame($registry->visible($this->company) !== [], $registry->hasVisible($this->company));
        }
    }

    public function test_same_instance_rechecks_role_membership_and_company_after_prior_success(): void
    {
        $reader = $this->customActor(['reports.sales.view', 'sales.invoice.view']);
        $this->activateUser($reader);
        $registry = app(ReportRegistry::class);
        $this->assertTrue($registry->hasVisible($this->company));
        $role = $reader->roles->sole();
        $role->syncPermissions([]);
        // Keep the actor's loaded relations deliberately stale.
        $this->assertFalse($registry->hasVisible($this->company));
        $role->syncPermissions(['reports.sales.view', 'sales.invoice.view']);
        $this->assertTrue($registry->hasVisible($this->company));
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $reader->id)->update(['status' => 'suspended']);
        $this->assertFalse($registry->hasVisible($this->company));
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $reader->id)->update(['status' => 'active']);
        $this->assertTrue($registry->hasVisible($this->company));
        $this->company->update(['status' => 'suspended']);
        $this->assertFalse($registry->hasVisible($this->company));
    }

    public function test_foreign_company_never_has_visible_navigation(): void
    {
        $otherOwner = User::factory()->create();
        app(CompanyContext::class)->clear();
        $foreign = app(CreateCompanyAction::class)->execute($otherOwner, ['name_ar' => 'Foreign navigation', 'base_currency_code' => 'ILS']);
        $this->activateUser($this->owner);
        $this->assertFalse(app(ReportRegistry::class)->hasVisible($foreign));
    }

    public function test_empty_source_rows_keep_one_fresh_guard_and_skip_irrelevant_source_lookups(): void
    {
        $this->activateUser($this->owner);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $links = app(ReportSourceNavigation::class)->forRows($this->company, 'sales.summary', $this->reportResult([]));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $links);
        $this->assertLessThan(10, count($queries));
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('sales_invoices', $query['query']);
            $this->assertStringNotContainsString('sales_returns', $query['query']);
        }
    }

    public function test_existing_row_authority_is_rechecked_before_source_lookup(): void
    {
        $invoice = $this->createAndPostSalesInvoice(['lines' => [['item_description' => 'Navigation service fixture', 'quantity' => '1', 'unit_price' => '10']]]);
        $reader = $this->customActor(['reports.sales.view', 'sales.invoice.view']);
        $this->activateUser($reader);
        $navigation = app(ReportSourceNavigation::class);
        $rows = $this->reportResult([['document_id' => $invoice->id, 'event_type' => 'invoice']]);
        $this->assertSame(route('invoices.show', ['publicId' => $invoice->public_id]), $navigation->forRows($this->company, 'sales.summary', $rows)[0][0]['url']);
        $reader->roles->sole()->syncPermissions(['reports.sales.view']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame([], $navigation->forRows($this->company, 'sales.summary', $rows));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('sales_invoices', $query['query']);
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function reportResult(array $rows): ReportResult
    {
        return new ReportResult(reportType: 'sales.summary', filters: [], totals: [], rows: $rows, currency: ['code' => 'ILS'], pagination: []);
    }
}
