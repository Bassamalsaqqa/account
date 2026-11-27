<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

trait FindsEmptyDocumentLine
{
    /**
     * Locate the first untouched empty placeholder line so quick search or barcode
     * scanning can fill it cleanly while preserving row_key identity and never
     * overwriting filled rows, even after row deletions.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function firstTrulyEmptyLineIndex(array $lines, string $unitAmountKey = 'unit_price'): ?int
    {
        foreach ($lines as $index => $line) {
            $isEmpty = empty($line['product_id'])
                && empty($line['id'])
                && empty($line['public_id'])
                && trim((string) ($line['item_description'] ?? '')) === ''
                && empty($line['lots'])
                && in_array((string) ($line['quantity'] ?? ''), ['1', '1.000000', '1.00', ''], true)
                && in_array((string) ($line[$unitAmountKey] ?? ''), ['0', '0.00', '0.000000', ''], true)
                && (($line['discount_type'] ?? 'none') === 'none')
                && empty($line['tax_rate_id']);

            if ($isEmpty) {
                return (int) $index;
            }
        }

        return null;
    }
}
