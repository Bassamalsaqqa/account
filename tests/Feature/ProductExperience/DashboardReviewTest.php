<?php

declare(strict_types=1);

namespace Tests\Feature\ProductExperience;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Presentation\DashboardReports;
use App\Livewire\Pages\Reporting\Dashboard;
use App\Models\Customer;
use App\Models\PostingLine;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Feature\Phase8\Phase8TestCase;

final class DashboardReviewTest extends Phase8TestCase
{
    public function test_dashboard_comparison_uses_posted_values_and_preserves_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00'));
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل اختباري',
            'name_en' => 'Synthetic Customer', 'active' => true, 'created_by' => $this->owner->id]);
        foreach (['2026-10-05' => '800', '2026-09-05' => '450'] as $date => $amount) {
            $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1', 'issue_date' => $date,
                'lines' => [['item_description' => 'Synthetic service', 'quantity' => '1', 'unit_price' => $amount]],
            ]);
            app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        }
        $before = PostingLine::where('company_id', $this->company->id)->orderBy('id')->get()->toJson();
        $data = app(DashboardReports::class)->read($this->company, 'this_month');
        $this->assertSame('800.00', $data['comparison']['current_display']);
        $this->assertSame('450.00', $data['comparison']['previous_display']);
        $this->assertSame('350.00', $data['comparison']['difference_display']);
        $this->assertSame('77.78', $data['comparison']['percent']);
        $this->assertSame('2026-09-01', $data['comparison']['previous_period']['start_date']);
        $this->assertSame('2026-09-30', $data['comparison']['previous_period']['end_date']);
        $this->assertSame($before, PostingLine::where('company_id', $this->company->id)->orderBy('id')->get()->toJson());
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(Dashboard::class)->assertSee(__('dashboard.title'))->assertSee('800.00 ILS');
        }
    }

    public function test_no_percent_is_invented_for_zero_previous_sales_and_denied_profit_is_omitted(): void
    {
        Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail()->revokePermissionTo('reports.cost.view');
        $data = app(DashboardReports::class)->read($this->company, 'this_month');
        $this->assertNull($data['comparison']['percent']);
        $this->assertArrayNotHasKey('profit', $data['activity']);
        $this->assertArrayNotHasKey('inventory.valuation', $data['positions']);
    }

    public function test_previous_period_handles_leap_month_and_equal_custom_ranges(): void
    {
        $february = ReportPeriod::custom('2024-03-01', '2024-03-31', 'Asia/Hebron')->previous();
        $this->assertSame('2024-02-01', $february->startDate);
        $this->assertSame('2024-02-29', $february->endDate);
        $custom = ReportPeriod::custom('2026-10-04', '2026-10-10', 'Asia/Hebron')->previous();
        $this->assertSame('2026-09-27', $custom->startDate);
        $this->assertSame('2026-10-03', $custom->endDate);
    }
}
