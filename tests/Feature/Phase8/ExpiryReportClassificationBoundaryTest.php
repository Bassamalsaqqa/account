<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Application\Reporting\Queries\ExpiryReportQuery;

class ExpiryReportClassificationBoundaryTest extends PositionTestCase
{
    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function filters(array $values = []): array
    {
        return ['from' => '2026-10-01', 'to' => '2026-10-10', ...$values];
    }

    public function test_expiry_boundary_classification_at_and_around_reference_date(): void
    {
        // Reference date is 2026-10-10 (as_of / endDate).
        // Boundaries:
        // 1. reference - 1 day: '2026-10-09' -> 'expired'
        // 2. equal date:        '2026-10-10' -> 'soon' (not expired, within 30-day window)
        // 3. reference + 1:     '2026-10-11' -> 'soon'
        // 4. exact 30-day end:  '2026-11-09' -> 'soon' (DATE_ADD('2026-10-10', INTERVAL 30 DAY))
        // 5. outside window:    '2026-11-10' -> 'valid' (> 30 days)
        // 6. null date:         null         -> 'unknown'
        $lots = [
            'LOT-REF-MINUS-1' => '2026-10-09',
            'LOT-REF-EQUAL' => '2026-10-10',
            'LOT-REF-PLUS-1' => '2026-10-11',
            'LOT-SOON-END' => '2026-11-09',
            'LOT-OUTSIDE' => '2026-11-10',
            'LOT-UNKNOWN' => null,
        ];

        foreach ($lots as $lotNumber => $expiryDate) {
            $this->postOpeningStock(
                product: $this->stockProduct,
                warehouse: $this->warehouseA,
                quantity: '5',
                unitCost: '10.000000',
                date: '2026-10-01',
                lotNumber: $lotNumber,
                expiryDate: $expiryDate
            );
        }

        $result = $this->assertZeroEconomicWrites(
            fn () => app(ExpiryReportQuery::class)->execute($this->company, $this->filters(), $this->owner)
        );

        $this->assertSame(6, $result->totals['total_active_lots']);
        $this->assertSame(1, $result->totals['expired_count']);
        $this->assertSame(3, $result->totals['soon_count']);
        $this->assertSame(1, $result->totals['valid_count']);
        $this->assertSame(1, $result->totals['unknown_count']);

        $this->assertIsArray($result->rows);
        $byLot = collect($result->rows)->keyBy('lot_number');

        $this->assertSame('expired', $byLot['LOT-REF-MINUS-1']['expiry_status']);
        $this->assertSame('2026-10-09', $byLot['LOT-REF-MINUS-1']['expiry_date']);

        $this->assertSame('soon', $byLot['LOT-REF-EQUAL']['expiry_status']);
        $this->assertSame('2026-10-10', $byLot['LOT-REF-EQUAL']['expiry_date']);

        $this->assertSame('soon', $byLot['LOT-REF-PLUS-1']['expiry_status']);
        $this->assertSame('2026-10-11', $byLot['LOT-REF-PLUS-1']['expiry_date']);

        $this->assertSame('soon', $byLot['LOT-SOON-END']['expiry_status']);
        $this->assertSame('2026-11-09', $byLot['LOT-SOON-END']['expiry_date']);

        $this->assertSame('valid', $byLot['LOT-OUTSIDE']['expiry_status']);
        $this->assertSame('2026-11-10', $byLot['LOT-OUTSIDE']['expiry_date']);

        $this->assertSame('unknown', $byLot['LOT-UNKNOWN']['expiry_status']);
        $this->assertNull($byLot['LOT-UNKNOWN']['expiry_date']);

        // Verify status-specific filters
        $expiredResult = app(ExpiryReportQuery::class)->execute($this->company, $this->filters(['status' => 'expired']), $this->owner);
        $this->assertCount(1, $expiredResult->rows);
        $this->assertSame('LOT-REF-MINUS-1', $expiredResult->rows[0]['lot_number']);

        $soonResult = app(ExpiryReportQuery::class)->execute($this->company, $this->filters(['status' => 'soon']), $this->owner);
        $this->assertCount(3, $soonResult->rows);
        $soonLotNumbers = collect($soonResult->rows)->pluck('lot_number')->all();
        $this->assertEqualsCanonicalizing(['LOT-REF-EQUAL', 'LOT-REF-PLUS-1', 'LOT-SOON-END'], $soonLotNumbers);

        $validResult = app(ExpiryReportQuery::class)->execute($this->company, $this->filters(['status' => 'valid']), $this->owner);
        $this->assertCount(1, $validResult->rows);
        $this->assertSame('LOT-OUTSIDE', $validResult->rows[0]['lot_number']);

        $unknownResult = app(ExpiryReportQuery::class)->execute($this->company, $this->filters(['status' => 'unknown']), $this->owner);
        $this->assertCount(1, $unknownResult->rows);
        $this->assertSame('LOT-UNKNOWN', $unknownResult->rows[0]['lot_number']);
    }
}
