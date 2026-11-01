<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

/** Deliberately narrow six-column stocktake reader; unsupported workbooks fail closed. */
final class OpeningInventoryWorkbook
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @return array{source_sha256: string, rows: list<array<string, mixed>>, rows_sha256: string, quantity: string, value: string} */
    public function read(string $path, string $expectedSha): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $expectedSha) || ! is_file($path) || filesize($path) > 5000000) {
            throw new InvalidArgumentException('A bounded workbook and exact SHA-256 are required.');
        }
        // Read/hash the same bytes: replacing the input path cannot substitute a workbook after hashing.
        $bytes = file_get_contents($path, false, null, 0, 5000001);
        if ($bytes === false || strlen($bytes) > 5000000 || ! hash_equals($expectedSha, hash('sha256', $bytes))) {
            throw new InvalidArgumentException('Workbook checksum mismatch.');
        }
        $copy = tmpfile();
        if ($copy === false) {
            throw new InvalidArgumentException('Cannot allocate private workbook reader.');
        }
        $zip = new ZipArchive;
        $opened = false;
        try {
            fwrite($copy, $bytes);
            if ($zip->open(stream_get_meta_data($copy)['uri']) !== true) {
                throw new InvalidArgumentException('Invalid XLSX archive.');
            }
            $opened = true;
            if ($zip->numFiles > 100) {
                throw new InvalidArgumentException('Unexpected workbook archive size.');
            }
            $size = 0;
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false || isset($entries[$stat['name']])) {
                    throw new InvalidArgumentException('Invalid or duplicate archive member.');
                }
                $entries[$stat['name']] = true;
                $size += $stat['size'];
            }
            if ($size > 20000000) {
                throw new InvalidArgumentException('Workbook expands beyond the supported limit.');
            }
            $book = $this->xml($zip, 'xl/workbook.xml');
            if (count($book->sheets->sheet) !== 1 || (string) $book->sheets->sheet['name'] !== 'Sheet1'
                || in_array((string) $book->workbookPr['date1904'], ['1', 'true'], true)) {
                throw new InvalidArgumentException('Expected one Sheet1 using the 1900 calendar.');
            }
            $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels', 'http://schemas.openxmlformats.org/package/2006/relationships');
            $relation = (string) $book->sheets->sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $targets = [];
            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['TargetMode'] === 'External') {
                    throw new InvalidArgumentException('External workbook relationships are unsupported.');
                }
                if ((string) $rel['Id'] === $relation) {
                    $targets[] = (string) $rel['Target'];
                }
            }
            if ($targets !== ['worksheets/sheet1.xml']) {
                throw new InvalidArgumentException('Unexpected worksheet relationship.');
            }
            $strings = [];
            if (isset($entries['xl/sharedStrings.xml'])) {
                foreach ($this->xml($zip, 'xl/sharedStrings.xml')->si as $si) {
                    $strings[] = $this->text($si);
                }
            }
            $styles = [];
            foreach ($this->xml($zip, 'xl/styles.xml')->cellXfs->xf as $style) {
                $styles[] = (int) $style['numFmtId'];
            }
            $sheet = $this->xml($zip, 'xl/worksheets/sheet1.xml');
            if (isset($sheet->mergeCells) || isset($sheet->autoFilter)) {
                throw new InvalidArgumentException('Merged or filtered stocktake sheets require explicit normalization.');
            }
            $rows = [];
            $last = 0;
            foreach ($sheet->sheetData->row as $row) {
                $number = (int) $row['r'];
                if ($number !== $last + 1 || $number > 1001 || (string) $row['hidden'] === '1') {
                    throw new InvalidArgumentException('Noncontiguous, hidden or oversized stocktake rows.');
                }
                $last = $number;
                $cells = array_fill_keys(range('A', 'F'), '');
                $seen = [];
                foreach ($row->c as $cell) {
                    $ref = (string) $cell['r'];
                    if (! preg_match('/^([A-F])'.$number.'$/D', $ref, $m) || isset($seen[$ref]) || isset($cell->f)) {
                        throw new InvalidArgumentException("Row {$number}: formula, duplicate or misaligned cell.");
                    }
                    $seen[$ref] = true;
                    $type = (string) $cell['t'];
                    $raw = (string) $cell->v;
                    $value = match ($type) {
                        's' => ctype_digit($raw) && array_key_exists((int) $raw, $strings) ? $strings[(int) $raw] : throw new InvalidArgumentException("Row {$number}: bad shared string."),
                        'inlineStr' => $this->text($cell->is),
                        '', 'n' => $raw,
                        default => throw new InvalidArgumentException("Row {$number}: unsupported/error cell."),
                    };
                    if ($number > 1 && $m[1] === 'B' && $value !== '' && ! in_array($type, ['s', 'inlineStr'], true)) {
                        throw new InvalidArgumentException("Row {$number}: codes must be lossless text, not numeric cells.");
                    }
                    if ($number > 1 && $m[1] === 'F' && $value !== '' && in_array($type, ['', 'n'], true)) {
                        if (! ctype_digit($value) || (int) $value < 61 || (int) $value > 109574
                            || ! in_array($styles[(int) $cell['s']] ?? -1, [14, 15, 16, 17, 58], true)) {
                            throw new InvalidArgumentException("Row {$number}: unsupported expiry serial/format.");
                        }
                        $value = (new DateTimeImmutable('1899-12-30'))->modify('+'.$value.' days')->format('Y-m-d');
                    }
                    $cells[$m[1]] = $value;
                }
                if ($number === 1) {
                    if (array_values($cells) !== ['Item name', 'code', 'Quantity', 'Cost price', 'Sale price', 'EXP date']) {
                        throw new InvalidArgumentException('Stocktake headers do not match the reviewed contract.');
                    }

                    continue;
                }
                if (trim($cells['A']) === '' || mb_strlen($cells['A']) > 255 || mb_strlen($cells['B']) > 128
                    || preg_match('/^\s*[=+@\-]/u', $cells['A']) || preg_match('/^\s*[=+@\-]/u', $cells['B'])
                    || ($cells['B'] !== '' && trim($cells['B']) !== $cells['B']) || preg_match('/[\x00-\x1F\x7F]/', $cells['A'].$cells['B'])) {
                    throw new InvalidArgumentException("Row {$number}: invalid source identity.");
                }
                foreach (['C', 'D', 'E'] as $column) {
                    if (! preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $cells[$column])) {
                        throw new InvalidArgumentException("Row {$number}: nondecimal or blank quantity/price.");
                    }
                }
                if (! BigDecimal::of($cells['C'])->isPositive()) {
                    throw new InvalidArgumentException("Row {$number}: quantity must be positive.");
                }
                $date = $cells['F'];
                $parsedDate = $date === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if ($date !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                    || $parsedDate === false || $parsedDate->format('Y-m-d') !== $date)) {
                    throw new InvalidArgumentException("Row {$number}: invalid expiry date.");
                }
                $rows[] = ['source_row' => $number, 'name_en' => $cells['A'], 'code' => $cells['B'] === '' ? null : $cells['B'],
                    'quantity' => $cells['C'], 'unit_cost' => $cells['D'], 'sale_price' => $cells['E'], 'expiry_date' => $date === '' ? null : $date];
            }
            if ($rows === []) {
                throw new InvalidArgumentException('Stocktake has no rows.');
            }
            $quantity = BigDecimal::zero();
            $value = BigDecimal::zero();
            foreach ($rows as $row) {
                $quantity = $quantity->plus($row['quantity']);
                $value = $value->plus(BigDecimal::of($row['quantity'])->multipliedBy($row['unit_cost']));
            }

            return ['source_sha256' => $expectedSha, 'rows' => $rows, 'rows_sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
                'quantity' => (string) $quantity->toScale(6), 'value' => (string) $value->toScale(6)];
        } finally {
            if ($opened) {
                $zip->close();
            }
            fclose($copy);
        }
    }

    private function xml(ZipArchive $zip, string $name, string $namespace = self::NS): SimpleXMLElement
    {
        $bytes = $zip->getFromName($name);
        if ($bytes === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes)) {
            throw new InvalidArgumentException('Missing or unsafe workbook XML.');
        }
        $prior = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($bytes, SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false || ($xml->getDocNamespaces()[''] ?? null) !== $namespace) {
                throw new InvalidArgumentException('Invalid workbook XML namespace.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
    }

    private function text(SimpleXMLElement $node): string
    {
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $text = '';
        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }
}
