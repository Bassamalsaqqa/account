<?php

declare(strict_types=1);

namespace App\Application\AI;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Structural command normalization only; PurchaseDraftBuilder owns all business validation. */
final class PurchaseDraftPayload
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function canonicalize(array $payload): array
    {
        $headers = ['vendor_id', 'vendor_invoice_number', 'warehouse_id', 'purchase_date', 'due_date',
            'currency_code', 'exchange_rate', 'document_locale', 'notes', 'lines'];
        if (array_diff(array_keys($payload), $headers) !== [] || strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 65536) {
            throw new InvalidArgumentException('Invalid purchase draft command fields.');
        }
        foreach (['vendor_id', 'warehouse_id', 'purchase_date', 'currency_code', 'exchange_rate', 'document_locale', 'lines'] as $required) {
            if (! array_key_exists($required, $payload)) {
                throw new InvalidArgumentException('Purchase draft requires explicit form fields.');
            }
        }
        foreach (['vendor_invoice_number', 'due_date', 'notes'] as $field) {
            $payload[$field] ??= null;
        }
        $payload['exchange_rate'] = $this->decimal($payload['exchange_rate'], 10);
        foreach (['vendor_invoice_number', 'purchase_date', 'due_date', 'currency_code', 'document_locale', 'notes'] as $field) {
            if ($payload[$field] !== null && ! is_string($payload[$field])) {
                throw new InvalidArgumentException('Purchase text fields must be strings.');
            }
            if (is_string($payload[$field])) {
                $payload[$field] = trim($payload[$field]);
                if (in_array($field, ['vendor_invoice_number', 'due_date', 'notes'], true) && $payload[$field] === '') {
                    $payload[$field] = null;
                }
            }
        }
        foreach (['vendor_id', 'warehouse_id'] as $field) {
            $payload[$field] = $this->identity($payload[$field]);
        }
        if (! is_array($payload['lines']) || ! array_is_list($payload['lines']) || count($payload['lines']) < 1 || count($payload['lines']) > 100) {
            throw new InvalidArgumentException('Purchase lines must be a bounded list.');
        }
        foreach ($payload['lines'] as &$line) {
            if (! is_array($line) || array_diff(array_keys($line), ['product_id', 'product_unit_id', 'item_description', 'quantity', 'unit_cost', 'discount_type', 'discount_value', 'tax_rate_id', 'lots']) !== []) {
                throw new InvalidArgumentException('Invalid purchase line fields.');
            }
            $line['product_id'] = $this->identity($line['product_id'] ?? null);
            $line['product_unit_id'] = $this->identity($line['product_unit_id'] ?? null);
            $line['tax_rate_id'] = $this->identity($line['tax_rate_id'] ?? null);
            $line['quantity'] = $this->decimal($line['quantity'] ?? null, 6);
            $line['unit_cost'] = $this->decimal($line['unit_cost'] ?? null, 6);
            $line['discount_type'] = $line['discount_type'] ?? 'none';
            $line['discount_value'] = $this->decimal($line['discount_value'] ?? '0', 6);
            $line['lots'] ??= [];
            if (! is_array($line['lots']) || ! array_is_list($line['lots']) || count($line['lots']) > 100) {
                throw new InvalidArgumentException('Purchase lots must be a bounded list.');
            }
            foreach ($line['lots'] as &$lot) {
                if (! is_array($lot) || array_diff(array_keys($lot), ['product_unit_id', 'lot_number', 'expiry_date', 'quantity']) !== []) {
                    throw new InvalidArgumentException('Invalid purchase lot fields.');
                }
                $lot['product_unit_id'] = $this->identity($lot['product_unit_id'] ?? null);
                $lot['quantity'] = $this->decimal($lot['quantity'] ?? null, 6);
                $lot['lot_number'] ??= null;
                $lot['expiry_date'] ??= null;
            }
            unset($lot);
        }
        unset($line);

        return $this->sort($payload);
    }

    /** @param array<string, mixed> $payload */
    public function hash(array $payload): string
    {
        return hash('sha256', json_encode($this->sort($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function identity(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException('Purchase identities must be positive integers.');
        }

        return $value;
    }

    private function decimal(mixed $value, int $scale): string
    {
        if (! is_string($value) || ! preg_match('/\A\d{1,10}(?:\.\d{1,'.$scale.'})?\z/', $value)) {
            throw new InvalidArgumentException('Purchase decimals must be exact bounded strings.');
        }

        return (string) BigDecimal::of($value)->strippedOfTrailingZeros();
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function sort(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->sort($item);
            } elseif (is_float($item) || is_object($item) || is_resource($item)) {
                throw new InvalidArgumentException('Purchase commands cannot contain floats or objects.');
            }
        }
        unset($item);

        return $value;
    }
}
