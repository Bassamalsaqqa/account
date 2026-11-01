<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use ZipArchive;

/** Synthetic stock only. No customer workbook or identity is committed. */
final class OpeningInventoryWorkbookFixture
{
    public static function create(bool $unknownVialExpiry = false): string
    {
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $header = '';
        foreach (['A' => 'Item name', 'B' => 'code', 'C' => 'Quantity', 'D' => 'Cost price', 'E' => 'Sale price', 'F' => 'EXP date'] as $column => $value) {
            $header .= '<c r="'.$column.'1" t="inlineStr"><is><t>'.$value.'</t></is></c>';
        }
        $rows = '';
        foreach ([2 => ['Synthetic serum', '00123456789012', '2', '10', '15', '46844'], 3 => ['Synthetic serum', '00123456789012', '3', '10', '15', '46617'], 4 => ['Synthetic vial', '', '1', '7', '12', '47000']] as $number => $values) {
            $rows .= '<row r="'.$number.'">';
            foreach (array_combine(range('A', 'F'), $values) as $col => $value) {
                $rows .= in_array($col, ['A', 'B'], true) ? '<c r="'.$col.$number.'" t="inlineStr"><is><t>'.$value.'</t></is></c>' : '<c r="'.$col.$number.'"'.($col === 'F' ? ' s="1"' : '').'><v>'.$value.'</v></c>';
            }
            $rows .= '</row>';
        }
        $path = tempnam(sys_get_temp_dir(), 'opening-fixture-');
        if ($path === false) {
            throw new RuntimeException('Cannot allocate synthetic workbook.');
        }
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="'.$ns.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr/><sheets><sheet name="Sheet1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<styleSheet xmlns="'.$ns.'"><cellXfs><xf numFmtId="0"/><xf numFmtId="58"/></cellXfs></styleSheet>');
        if ($unknownVialExpiry) {
            $rows = str_replace('<c r="F4" s="1"><v>47000</v></c>', '<c r="F4" s="1"><v></v></c>', $rows);
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="'.$ns.'"><sheetData><row r="1">'.$header.'</row>'.$rows.'</sheetData></worksheet>');
        $zip->close();

        return $path;
    }
}
