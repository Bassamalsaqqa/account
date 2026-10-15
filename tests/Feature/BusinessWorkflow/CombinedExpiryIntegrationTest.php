<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Actions\Company\UpdateExpiryWarningDaysAction;
use App\Application\Reporting\Queries\ExpiryReportQuery;
use App\Livewire\Pages\Inventory\ExpiryCenter;
use Carbon\Carbon;
use Livewire\Livewire;
use Tests\Feature\Phase8\PositionTestCase;

final class CombinedExpiryIntegrationTest extends PositionTestCase
{
    public function test_reference_day_is_soon_while_center_warning_window_remains_a_separate_preference(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00', 'UTC'));
        try {
            app(UpdateExpiryWarningDaysAction::class)->execute($this->company, 7, $this->owner);
            foreach (['expired' => '2026-10-14', 'today' => '2026-10-15', 'report-only-soon' => '2026-11-04', 'valid' => '2026-11-15'] as $name => $expiry) {
                $this->postOpeningStock($this->stockProduct, $this->warehouseA, '3', '2', lotNumber: $name, expiryDate: $expiry);
            }
            $this->assertZeroEconomicWrites(function (): void {
                $report = app(ExpiryReportQuery::class)->execute($this->company, ['from' => '2026-10-01', 'to' => '2026-10-15'], $this->owner);
                $this->assertSame(2, $report->totals['soon_count']);
                $this->assertSame(1, $report->totals['expired_count']);
                $this->assertSame(1, $report->totals['valid_count']);
                foreach (['ar', 'en'] as $locale) {
                    app()->setLocale($locale);
                    Livewire::test(ExpiryCenter::class)->set('timeframeFilter', 'warning')->assertViewHas('savedWarningDays', 7)
                        ->assertViewHas('lotBalances', fn ($lots) => $lots->pluck('lot.lot_number')->all() === ['today']);
                }
            });
        } finally {
            Carbon::setTestNow();
        }
    }
}
