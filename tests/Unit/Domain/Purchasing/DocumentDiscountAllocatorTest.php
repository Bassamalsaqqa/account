<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Purchasing;

use App\Domain\Purchasing\DocumentDiscountAllocator;
use App\Domain\Purchasing\DocumentDiscountIntent;
use App\Domain\Purchasing\DocumentDiscountLineCandidate;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use Tests\TestCase;

class DocumentDiscountAllocatorTest extends TestCase
{
    private DocumentDiscountAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new DocumentDiscountAllocator;
    }

    /**
     * Vector 1: ILS Standard 2-Decimal Distribution (Owner Brief Benchmark)
     * Total Basis: 350.00 ILS (Line 1: 100.00, Line 2: 200.00, Line 3: 50.00)
     * Fixed discount: 17.00 ILS
     * Expected allocations:
     * Line 1: 4.86 ILS (Net: 95.14)
     * Line 2: 9.71 ILS (Net: 190.29)
     * Line 3: 2.43 ILS (Net: 47.57)
     * Exact sum = 17.00 ILS
     */
    public function test_vector_1_ils_standard_2_decimal_distribution(): void
    {
        $lines = [
            new DocumentDiscountLineCandidate(1, BigDecimal::of('100.00'), BigDecimal::of('1'), BigDecimal::of('100.00')),
            new DocumentDiscountLineCandidate(2, BigDecimal::of('200.00'), BigDecimal::of('1'), BigDecimal::of('200.00')),
            new DocumentDiscountLineCandidate(3, BigDecimal::of('50.00'), BigDecimal::of('1'), BigDecimal::of('50.00')),
        ];

        $intent = new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_FIXED, '17.00');
        $result = $this->allocator->allocate($intent, $lines, 'ILS', customMinorUnits: 2);

        $this->assertSame('4.860000', (string) $result->forLine(1));
        $this->assertSame('9.710000', (string) $result->forLine(2));
        $this->assertSame('2.430000', (string) $result->forLine(3));
        $this->assertSame('17.000000', (string) $result->totalAllocated);
        $this->assertSame('350.000000', (string) $result->totalBasis);
    }

    /**
     * Vector 2A: JOD 3-Decimal Single Minor Unit Distribution
     * Basis: 40.000 JOD (Line 1: 10.000, Line 2: 20.000, Line 3: 10.000)
     * Discount: 0.001 JOD (1 unit)
     * Line 2 has largest remainder -> gets 0.001 JOD
     */
    public function test_vector_2a_jod_single_minor_unit_distribution(): void
    {
        $lines = [
            new DocumentDiscountLineCandidate(1, BigDecimal::of('10.000'), BigDecimal::of('1'), BigDecimal::of('10.000')),
            new DocumentDiscountLineCandidate(2, BigDecimal::of('20.000'), BigDecimal::of('1'), BigDecimal::of('20.000')),
            new DocumentDiscountLineCandidate(3, BigDecimal::of('10.000'), BigDecimal::of('1'), BigDecimal::of('10.000')),
        ];

        $intent = new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_FIXED, '0.001');
        $result = $this->allocator->allocate($intent, $lines, 'JOD', customMinorUnits: 3);

        $this->assertSame('0.000000', (string) $result->forLine(1));
        $this->assertSame('0.001000', (string) $result->forLine(2));
        $this->assertSame('0.000000', (string) $result->forLine(3));
        $this->assertSame('0.001000', (string) $result->totalAllocated);
    }

    /**
     * Vector 2B: JOD 3-Decimal Tie Witness with Deterministic Ordinal Tie-Break
     * Basis: 40.000 JOD (Line 1: 10.000, Line 2: 20.000, Line 3: 10.000)
     * Discount: 0.002 JOD (2 units)
     * Line 2 gets 1 unit.
     * Line 1 and Line 3 tie at remainder 10000.
     * Tie-breaker: Line 1 (line_number = 1) wins over Line 3 (line_number = 3).
     * Line 1 gets 0.001 JOD, Line 3 gets 0.000 JOD.
     */
    public function test_vector_2b_jod_remainder_tie_break_witness(): void
    {
        $lines = [
            new DocumentDiscountLineCandidate(1, BigDecimal::of('10.000'), BigDecimal::of('1'), BigDecimal::of('10.000')),
            new DocumentDiscountLineCandidate(2, BigDecimal::of('20.000'), BigDecimal::of('1'), BigDecimal::of('20.000')),
            new DocumentDiscountLineCandidate(3, BigDecimal::of('10.000'), BigDecimal::of('1'), BigDecimal::of('10.000')),
        ];

        $intent = new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_FIXED, '0.002');
        $result = $this->allocator->allocate($intent, $lines, 'JOD', customMinorUnits: 3);

        $this->assertSame('0.001000', (string) $result->forLine(1));
        $this->assertSame('0.001000', (string) $result->forLine(2));
        $this->assertSame('0.000000', (string) $result->forLine(3));
        $this->assertSame('0.002000', (string) $result->totalAllocated);

        // Reordering witness: if Line 3 is placed first as line_number = 1, it wins the tie-break
        $reorderedLines = [
            new DocumentDiscountLineCandidate(1, BigDecimal::of('10.000'), BigDecimal::of('1'), BigDecimal::of('10.000'), identity: 'original_3'),
            new DocumentDiscountLineCandidate(2, BigDecimal::of('20.000'), BigDecimal::of('1'), BigDecimal::of('20.000'), identity: 'original_2'),
            new DocumentDiscountLineCandidate(3, BigDecimal::of('10.000'), BigDecimal::of('1'), BigDecimal::of('10.000'), identity: 'original_1'),
        ];
        $reorderedResult = $this->allocator->allocate($intent, $reorderedLines, 'JOD', customMinorUnits: 3);
        $this->assertSame('0.001000', (string) $reorderedResult->forLine(1)); // Was line 3
        $this->assertSame('0.001000', (string) $reorderedResult->forLine(2));
        $this->assertSame('0.000000', (string) $reorderedResult->forLine(3)); // Was line 1
    }

    /**
     * Vector 3: Fixed-Amount Discount on Mixed Tax-Exclusive and Exempt Lines
     * Basis: 300.00 ILS (Line 1: 100.00 @ 16% exclusive, Line 2: 200.00 @ 0% exempt)
     * Discount: 30.00 ILS
     * Expected allocations:
     * Line 1: 10.000000 ILS
     * Line 2: 20.000000 ILS
     */
    public function test_vector_3_mixed_tax_exclusive_and_exempt_lines(): void
    {
        $lines = [
            new DocumentDiscountLineCandidate(1, BigDecimal::of('10.00'), BigDecimal::of('10'), BigDecimal::of('100.00'), taxRate: BigDecimal::of('16')),
            new DocumentDiscountLineCandidate(2, BigDecimal::of('200.00'), BigDecimal::of('1'), BigDecimal::of('200.00'), taxRate: BigDecimal::of('0')),
        ];

        $intent = new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_FIXED, '30.00');
        $result = $this->allocator->allocate($intent, $lines, 'ILS', customMinorUnits: 2);

        $this->assertSame('10.000000', (string) $result->forLine(1));
        $this->assertSame('20.000000', (string) $result->forLine(2));
        $this->assertSame('30.000000', (string) $result->totalAllocated);
    }

    /**
     * Vector 4: Edge Cases & Validation Rejections
     */
    public function test_vector_4a_100_percent_discount(): void
    {
        $lines = [
            new DocumentDiscountLineCandidate(1, BigDecimal::of('100.00'), BigDecimal::of('1'), BigDecimal::of('100.00')),
            new DocumentDiscountLineCandidate(2, BigDecimal::of('200.00'), BigDecimal::of('1'), BigDecimal::of('200.00')),
        ];

        // 100% discount via percent
        $intentPercent = new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_PERCENT, '100');
        $resultPercent = $this->allocator->allocate($intentPercent, $lines, 'ILS', customMinorUnits: 2);
        $this->assertSame('100.000000', (string) $resultPercent->forLine(1));
        $this->assertSame('200.000000', (string) $resultPercent->forLine(2));
        $this->assertSame('300.000000', (string) $resultPercent->totalAllocated);

        // 100% discount via fixed matching subtotal
        $intentFixed = new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_FIXED, '300.00');
        $resultFixed = $this->allocator->allocate($intentFixed, $lines, 'ILS', customMinorUnits: 2);
        $this->assertSame('100.000000', (string) $resultFixed->forLine(1));
        $this->assertSame('200.000000', (string) $resultFixed->forLine(2));
        $this->assertSame('300.000000', (string) $resultFixed->totalAllocated);
    }

    public function test_vector_4b_zero_subtotal_with_nonzero_discount_rejected(): void
    {
        $lines = [
            new DocumentDiscountLineCandidate(1, BigDecimal::of('0.00'), BigDecimal::of('1'), BigDecimal::of('0.00')),
        ];

        $intent = new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_FIXED, '5.00');

        $this->expectException(InvalidArgumentException::class);
        $this->allocator->allocate($intent, $lines, 'ILS', customMinorUnits: 2);
    }

    public function test_vector_4c_discount_exceeds_subtotal_rejected(): void
    {
        $lines = [
            new DocumentDiscountLineCandidate(1, BigDecimal::of('100.00'), BigDecimal::of('1'), BigDecimal::of('100.00')),
        ];

        $intent = new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_FIXED, '100.01');

        $this->expectException(InvalidArgumentException::class);
        $this->allocator->allocate($intent, $lines, 'ILS', customMinorUnits: 2);
    }

    public function test_vector_4d_negative_discount_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_FIXED, '-5.00');
    }

    public function test_vector_4e_percentage_over_100_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_PERCENT, '100.01');
    }

    public function test_vector_4f_tax_inclusive_line_rejected_when_document_discount_active(): void
    {
        $lines = [
            new DocumentDiscountLineCandidate(1, BigDecimal::of('100.00'), BigDecimal::of('1'), BigDecimal::of('100.00'), taxInclusive: true),
        ];

        $intent = new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_FIXED, '10.00');

        $this->expectException(InvalidArgumentException::class);
        $this->allocator->allocate($intent, $lines, 'ILS', customMinorUnits: 2);
    }

    public function test_zero_cost_sample_isolated_receives_zero_discount(): void
    {
        $lines = [
            new DocumentDiscountLineCandidate(1, BigDecimal::of('100.00'), BigDecimal::of('1'), BigDecimal::of('100.00')),
            new DocumentDiscountLineCandidate(2, BigDecimal::of('0.00'), BigDecimal::of('5'), BigDecimal::of('0.00')), // ADR 0013 valid zero-cost sample
            new DocumentDiscountLineCandidate(3, BigDecimal::of('100.00'), BigDecimal::of('1'), BigDecimal::of('100.00')),
        ];

        $intent = new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_FIXED, '10.00');
        $result = $this->allocator->allocate($intent, $lines, 'ILS', customMinorUnits: 2);

        $this->assertSame('5.000000', (string) $result->forLine(1));
        $this->assertSame('0.000000', (string) $result->forLine(2));
        $this->assertSame('5.000000', (string) $result->forLine(3));
        $this->assertSame('10.000000', (string) $result->totalAllocated);
    }

    public function test_none_intent_allocates_exact_zero(): void
    {
        $lines = [
            new DocumentDiscountLineCandidate(1, BigDecimal::of('50.00'), BigDecimal::of('2'), BigDecimal::of('100.00')),
            new DocumentDiscountLineCandidate(2, BigDecimal::of('25.00'), BigDecimal::of('4'), BigDecimal::of('100.00')),
        ];

        $intent = new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_NONE, '0');
        $result = $this->allocator->allocate($intent, $lines, 'ILS', customMinorUnits: 2);

        $this->assertSame('0.000000', (string) $result->forLine(1));
        $this->assertSame('0.000000', (string) $result->forLine(2));
        $this->assertSame('0.000000', (string) $result->totalAllocated);
    }

    /**
     * Conservation Property: Sum of allocated minor units must strictly equal target discount minor units.
     */
    public function test_exact_minor_unit_conservation_property(): void
    {
        $subtotals = ['13.37', '29.99', '104.50', '3.15', '88.80', '12.00', '7.77'];
        $lines = [];
        foreach ($subtotals as $idx => $s) {
            $lines[] = new DocumentDiscountLineCandidate(
                $idx + 1,
                BigDecimal::of($s),
                BigDecimal::one(),
                BigDecimal::of($s)
            );
        }

        // Test every integer discount from 1 to 250 ILS
        for ($d = 1; $d <= 250; $d++) {
            $intent = new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_FIXED, (string) $d);
            $result = $this->allocator->allocate($intent, $lines, 'ILS', customMinorUnits: 2);

            $sumAllocated = BigDecimal::zero();
            foreach ($lines as $line) {
                $allocated = $result->forLine($line->lineNumber);
                $this->assertFalse($allocated->isNegative(), "Line {$line->lineNumber} discount cannot be negative");
                $this->assertTrue($allocated->isLessThanOrEqualTo($line->subtotal), "Line {$line->lineNumber} discount cannot exceed subtotal");
                $sumAllocated = $sumAllocated->plus($allocated);
            }

            $this->assertTrue(
                $sumAllocated->isEqualTo(BigDecimal::of($d)->toScale(6)),
                "Conservation failed for discount {$d}: expected {$d}, got {$sumAllocated}"
            );
        }
    }

    public function test_large_amount_overflow_conservation_jod(): void
    {
        // JOD has 3 decimal places -> 1,000,000 JOD = 1,000,000,000 minor units
        // Product of minor units across large amounts exceeds PHP_INT_MAX without arbitrary precision
        $lines = [
            new DocumentDiscountLineCandidate(1, BigDecimal::of('500000.000'), BigDecimal::of('2'), BigDecimal::of('1000000.000')),
            new DocumentDiscountLineCandidate(2, BigDecimal::of('1000000.000'), BigDecimal::of('1'), BigDecimal::of('1000000.000')),
        ];

        // 100% discount on 2,000,000.000 JOD = 2,000,000.000 JOD
        $intent = new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_PERCENT, '100');
        $result = $this->allocator->allocate($intent, $lines, 'JOD', customMinorUnits: 3);

        $this->assertSame('1000000.000000', (string) $result->forLine(1));
        $this->assertSame('1000000.000000', (string) $result->forLine(2));
        $this->assertSame('2000000.000000', (string) $result->totalAllocated);
        $this->assertSame('2000000.000000', (string) $result->totalBasis);
    }

    public function test_fixed_discount_overprecision_rejection(): void
    {
        $lines = [
            new DocumentDiscountLineCandidate(1, BigDecimal::of('10.00'), BigDecimal::of('1'), BigDecimal::of('10.00')),
        ];

        // ILS has 2 minor units. Fixed discount with 3 decimals (1.001) must be rejected
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('purchasing.invalid_discount_value'));

        $intent = new DocumentDiscountIntent(DocumentDiscountIntent::TYPE_FIXED, '1.001');
        $this->allocator->allocate($intent, $lines, 'ILS', customMinorUnits: 2);
    }
}
