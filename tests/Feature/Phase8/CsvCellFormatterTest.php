<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Application\Reporting\Support\CsvCellFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvCellFormatterTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function formulas(): iterable
    {
        yield 'equals' => ['=SUM(1,2)'];
        yield 'plus' => ['+SUM(1,2)'];
        yield 'minus descriptive' => ['-Vendor'];
        yield 'at' => ['@SUM(1,2)'];
        yield 'space prefix' => ['   =1+2'];
        yield 'tab prefix' => ["\t=1+2"];
        yield 'line prefix' => ["\r\n+1"];
        yield 'unicode space' => ["\u{00A0}@1"];
        yield 'BOM prefix' => ["\u{FEFF}=1"];
        yield 'zero width prefix' => ["\u{200B}=1"];
    }

    #[DataProvider('formulas')]
    public function test_formula_text_is_inert_without_discarding_original_copy(string $text): void
    {
        $this->assertSame("'".$text, CsvCellFormatter::text($text));
    }

    public function test_exact_negative_financial_values_remain_numeric(): void
    {
        foreach (['-0.001', '-123.450000', '0', '12.345678', '0001.230000'] as $value) {
            $this->assertSame($value, CsvCellFormatter::decimal($value));
        }
        $this->assertSame("'-123.45", CsvCellFormatter::text('-123.45'));
    }

    public function test_arabic_quotes_and_line_breaks_survive_csv_round_trip(): void
    {
        $text = "مورد \"قديم\",\nفرع ثان";
        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);
        fputcsv($stream, [CsvCellFormatter::text($text), CsvCellFormatter::decimal('-0.001')], ',', '"', '');
        rewind($stream);
        $this->assertSame([$text, '-0.001'], fgetcsv($stream, null, ',', '"', ''));
        fclose($stream);
    }

    public function test_a_numeric_column_cannot_smuggle_formula_text(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CsvCellFormatter::decimal('=1+2');
    }

    public function test_invalid_utf8_fails_instead_of_bypassing_text_formula_detection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CsvCellFormatter::text("\xFF=1");
    }

    public function test_empty_and_ordinary_text_are_preserved(): void
    {
        $this->assertSame('', CsvCellFormatter::text(null));
        $this->assertSame('', CsvCellFormatter::decimal(null));
        $this->assertSame('شركة الأمل', CsvCellFormatter::text('شركة الأمل'));
    }
}
