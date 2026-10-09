<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Security\ReportPermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Feature\Phase7\Phase7TestCase;

class ReportingFoundationBoundaryTest extends Phase7TestCase
{
    public function test_company_disable_is_honored_with_stale_context_model(): void
    {
        DB::table('companies')->where('id', $this->company->id)->update(['status' => 'inactive']);

        $this->expectException(AuthorizationException::class);
        app(ReportingGuard::class)->authorize($this->company, $this->owner, ['reports.profit.view']);
    }

    public function test_week_presets_have_identical_boundaries_in_both_ui_languages(): void
    {
        $clock = CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC');
        foreach (['this_week', 'last_week'] as $preset) {
            app()->setLocale('ar');
            $arabic = ReportPeriod::fromPreset($preset, $this->company, $clock);
            app()->setLocale('en');
            $english = ReportPeriod::fromPreset($preset, $this->company, $clock);
            $this->assertSame($arabic->startDate, $english->startDate);
            $this->assertSame($arabic->endDate, $english->endDate);
        }
    }

    public function test_disabled_company_currency_remains_a_valid_historical_filter(): void
    {
        $this->company->currencies()->where('currency_code', 'USD')->update(['enabled' => false]);
        $filters = ReportFilters::fromArray($this->company, ['currency_code' => 'USD']);
        $this->assertSame('USD', $filters->currencyCode);
    }

    public function test_malformed_entity_identity_is_not_coerced_into_a_valid_own_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReportFilters::fromArray($this->company, ['product_id' => $this->product->id.'garbage']);
    }

    public function test_direct_constructor_cannot_create_unbounded_pagination(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReportFilters(ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company), perPage: 1000000);
    }

    public function test_financial_catalog_never_grants_source_cost_from_report_permission_alone(): void
    {
        $this->assertContains('purchasing.cost.view', ReportPermissionCatalog::PURCHASES_COMMERCIAL);
        $this->assertContains('sales.invoice.view', ReportPermissionCatalog::SALES_SUMMARY);
        $this->assertContains('inventory.stock.view', ReportPermissionCatalog::INVENTORY_QUANTITY);
        $this->assertContains('reports.profit.view', ReportPermissionCatalog::SALES_PROFIT);
        $this->assertContains('inventory.cost.view', ReportPermissionCatalog::SALES_PROFIT);
        $this->assertContains('money.expense.view', ReportPermissionCatalog::EXPENSES);
    }
}
