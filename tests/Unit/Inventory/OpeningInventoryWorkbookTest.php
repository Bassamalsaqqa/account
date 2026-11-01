<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Services\Inventory\OpeningInventoryWorkbook;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class OpeningInventoryWorkbookTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            unlink($file);
        }
        parent::tearDown();
    }

    /** @param array<string, string> $replace */
    private function workbook(array $replace = []): string
    {
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $head = '';
        foreach (['A' => 'Item name', 'B' => 'code', 'C' => 'Quantity', 'D' => 'Cost price', 'E' => 'Sale price', 'F' => 'EXP date'] as $col => $text) {
            $head .= '<c r="'.$col.'1" t="inlineStr"><is><t>'.$text.'</t></is></c>';
        }
        $members = [
            'xl/workbook.xml' => '<workbook xmlns="'.$ns.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr/><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/styles.xml' => '<styleSheet xmlns="'.$ns.'"><cellXfs><xf numFmtId="0"/><xf numFmtId="58"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="'.$ns.'"><sheetData><row r="1">'.$head.'</row><row r="2"><c r="A2" t="inlineStr"><is><t>Synthetic vial</t></is></c><c r="B2" t="inlineStr"><is><t>00123456789012</t></is></c><c r="C2"><v>3.25</v></c><c r="D2"><v>0.1</v></c><c r="E2"><v>1.20</v></c><c r="F2" s="1"><v>46844</v></c></row></sheetData></worksheet>',
        ];
        $path = tempnam(sys_get_temp_dir(), 'stocktake-test-');
        $this->assertNotFalse($path);
        $this->files[] = $path;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        foreach ($members as $name => $xml) {
            $zip->addFromString($name, str_replace(array_keys($replace), array_values($replace), $xml));
        }
        $zip->close();

        return $path;
    }

    public function test_exact_decimals_serial_date_and_leading_zero_text_are_preserved(): void
    {
        $file = $this->workbook();
        $result = (new OpeningInventoryWorkbook)->read($file, hash_file('sha256', $file));
        $this->assertSame('3.250000', $result['quantity']);
        $this->assertSame('0.325000', $result['value']);
        $this->assertSame('00123456789012', $result['rows'][0]['code']);
        $this->assertSame('2028-04-01', $result['rows'][0]['expiry_date']);
        $this->assertSame(2, $result['rows'][0]['source_row']);
        $this->assertSame('0.1', $result['rows'][0]['unit_cost']);
        $this->assertSame($result, (new OpeningInventoryWorkbook)->read($file, hash_file('sha256', $file)));
    }

    public function test_absent_code_and_expiry_remain_unknown_not_zero_or_generated(): void
    {
        $file = $this->workbook(['<t>00123456789012</t>' => '<t></t>', '<v>46844</v>' => '<v></v>']);
        $result = (new OpeningInventoryWorkbook)->read($file, hash_file('sha256', $file));
        $this->assertNull($result['rows'][0]['code']);
        $this->assertNull($result['rows'][0]['expiry_date']);
    }

    public function test_hash_mismatch_is_rejected_before_parsing(): void
    {
        $file = $this->workbook();
        $this->expectException(InvalidArgumentException::class);
        (new OpeningInventoryWorkbook)->read($file, str_repeat('0', 64));
    }

    #[DataProvider('invalidWorkbooks')]
    public function test_unsafe_or_ambiguous_workbook_is_rejected(string $from, string $to): void
    {
        $file = $this->workbook([$from => $to]);
        $this->expectException(InvalidArgumentException::class);
        (new OpeningInventoryWorkbook)->read($file, hash_file('sha256', $file));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidWorkbooks(): array
    {
        return [
            'formula' => ['<v>3.25</v>', '<f>1+2</f><v>3.25</v>'],
            'error' => ['<c r="D2">', '<c r="D2" t="e">'],
            'numeric barcode' => ['<c r="B2" t="inlineStr"><is><t>00123456789012</t></is></c>', '<c r="B2"><v>123456789012</v></c>'],
            'missing price' => ['<v>0.1</v>', '<v></v>'],
            'zero quantity' => ['<v>3.25</v>', '<v>0</v>'],
            'negative quantity' => ['<v>3.25</v>', '<v>-1</v>'],
            'exponent quantity' => ['<v>3.25</v>', '<v>1e3</v>'],
            'precision loss' => ['<v>3.25</v>', '<v>0.1234567</v>'],
            'misaligned cell' => ['r="C2"', 'r="G2"'],
            'extra row' => ['</sheetData>', '<row r="3"><c r="G3"><v>1</v></c></row></sheetData>'],
            'gap' => ['<row r="2">', '<row r="3">'],
            'hidden row' => ['<row r="2">', '<row r="2" hidden="1">'],
            'hidden row boolean text' => ['<row r="2">', '<row r="2" hidden="true">'],
            'wrong headers' => ['Cost price', 'Cost'],
            'wrong sheet' => ['name="Sheet1"', 'name="Other"'],
            '1904 dates' => ['<workbookPr/>', '<workbookPr date1904="1"/>'],
            'bad serial' => ['<v>46844</v>', '<v>60</v>'],
            'fractional date' => ['<v>46844</v>', '<v>46844.5</v>'],
            'non-date format' => ['numFmtId="58"', 'numFmtId="0"'],
            'bad ISO date' => ['<c r="F2" s="1"><v>46844</v></c>', '<c r="F2" t="inlineStr"><is><t>2027-02-30</t></is></c>'],
            'formula injection' => ['Synthetic vial', '=HYPERLINK()'],
            'external relationship' => ['Target="worksheets/sheet1.xml"', 'Target="worksheets/sheet1.xml" TargetMode="External"'],
            'DTD' => ['<worksheet xmlns=', '<!DOCTYPE worksheet [<!ENTITY leak SYSTEM "file:///not-a-real-file">]><worksheet xmlns='],
        ];
    }
}
