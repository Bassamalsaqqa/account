<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Domain\Sales\Documents\DocumentData;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/** Versioned financial allowlist; decrypted content is never reconstructed from current masters. */
final class IssuedDocumentContent
{
    public const VERSION = 1;

    public const MAX_PLAINTEXT = 2 * 1024 * 1024;

    public const MAX_CIPHERTEXT = 4 * 1024 * 1024;

    /** @return array{encrypted_snapshot:string,content_hash:string,content_version:int} */
    public function seal(DocumentData $document): array
    {
        $data = $document->toArray();
        $this->validate($data);
        $json = $this->canonical($data);
        if (strlen($json) > self::MAX_PLAINTEXT) {
            throw new InvalidArgumentException('Issued content exceeds the plaintext limit.');
        }
        $encrypted = Crypt::encryptString($json);
        if (strlen($encrypted) > self::MAX_CIPHERTEXT || ! hash_equals($json, Crypt::decryptString($encrypted))) {
            throw new InvalidArgumentException('Issued content could not be verified.');
        }

        return ['encrypted_snapshot' => $encrypted, 'content_hash' => hash('sha256', $json), 'content_version' => self::VERSION];
    }

    public function open(string $encrypted, string $hash, int $version): DocumentData
    {
        if ($version !== self::VERSION || strlen($encrypted) > self::MAX_CIPHERTEXT || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new InvalidArgumentException('Unsupported issued content.');
        }
        $json = Crypt::decryptString($encrypted);
        if (strlen($json) > self::MAX_PLAINTEXT || ! hash_equals($hash, hash('sha256', $json))) {
            throw new InvalidArgumentException('Issued content integrity failure.');
        }
        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new InvalidArgumentException('Invalid issued content.');
        }
        $this->validate($data);
        if (! hash_equals($json, $this->canonical($data))) {
            throw new InvalidArgumentException('Noncanonical issued content.');
        }

        return $this->document($data);
    }

    /** @param array<string,mixed> $data */
    public function document(array $data): DocumentData
    {
        $this->validate($data);

        return new DocumentData($data['type'], $data['document_locale'], $data['company'], $data['customer'], $data['document'], $data['lines'], $data['statement'], $data['presentation'], []);
    }

    /** @param array<array-key,mixed> $data */
    public function canonical(array $data): string
    {
        return json_encode($this->ordered($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<array-key,mixed> $data
     * @return array<array-key,mixed>
     */
    private function ordered(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data, SORT_STRING);
        }
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->ordered($value);
            } elseif (is_float($value) || is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('Issued values must be exact primitives.');
            }
        }

        return $data;
    }

    /** @param array<string,mixed> $data */
    public function validate(array $data): void
    {
        $this->keys($data, ['type', 'document_locale', 'company', 'customer', 'document', 'lines', 'statement', 'presentation', 'applications']);
        if (! in_array($data['type'] ?? null, ['quotation', 'sales_invoice', 'sales_return', 'customer_payment', 'customer_statement'], true)
            || ! in_array($data['document_locale'] ?? null, ['ar', 'en'], true) || ($data['applications'] ?? null) !== []) {
            throw new InvalidArgumentException('Invalid issued document scope.');
        }
        foreach (['company', 'customer'] as $party) {
            $this->textMap($data[$party] ?? [], ['name', 'phone', 'email', 'tax_number', 'registration_number', 'address', 'business_name']);
            if (! is_string($data[$party]['name'] ?? null) || $data[$party]['name'] === '') {
                throw new InvalidArgumentException('Issued party identity is missing.');
            }
        }
        $this->textMap($data['document'] ?? [], ['number', 'status', 'issue_date', 'currency_code', 'subtotal', 'discount_total', 'tax_total', 'grand_total', 'notes', 'terms', 'base_currency_code', 'exchange_rate', 'amount_base', 'payment_status', 'original_reference', 'reference', 'money_account', 'payment_method', 'from_date', 'to_date', 'due_date', 'expiry_date', 'valid_until', 'issued_at', 'timezone']);
        if (! is_array($data['lines'] ?? null) || ! array_is_list($data['lines']) || count($data['lines']) > 500) {
            throw new InvalidArgumentException('Invalid issued line scope.');
        }
        foreach ($data['lines'] as $line) {
            $this->textMap($line, ['line_number', 'item_description', 'unit_name', 'sku', 'quantity', 'unit_price', 'discount', 'tax', 'total', 'image', 'currency_code', 'payment_currency_code', 'payment_currency_amount', 'base_currency_code', 'settlement_base_value']);
        }
        $presentation = $data['presentation'] ?? [];
        $this->keys($presentation, ['logo', 'footer', 'show_qr']);
        foreach ($presentation as $key => $value) {
            if ($key === 'show_qr' ? ! is_bool($value) : (! is_string($value) && $value !== null)) {
                throw new InvalidArgumentException('Invalid issued presentation.');
            }
        }
        $statement = $data['statement'] ?? null;
        if ($statement !== null) {
            $this->keys($statement, ['from_date', 'to_date', 'currencies']);
            $this->textMap(array_diff_key($statement, ['currencies' => true]), ['from_date', 'to_date']);
            if (! is_array($statement['currencies'] ?? null)) {
                throw new InvalidArgumentException('Invalid issued statement currencies.');
            }
            $count = 0;
            foreach ($statement['currencies'] as $currency => $group) {
                if (! in_array($currency, ['ILS', 'USD', 'JOD'], true)) {
                    throw new InvalidArgumentException('Invalid statement currency.');
                }
                $this->keys($group, ['currency', 'opening_balance', 'closing_balance', 'total_debits', 'total_credits', 'entries', 'aging']);
                $this->textMap(array_diff_key($group, ['entries' => true, 'aging' => true]), ['currency', 'opening_balance', 'closing_balance', 'total_debits', 'total_credits']);
                if (! is_array($group['entries'] ?? null) || ! array_is_list($group['entries'])) {
                    throw new InvalidArgumentException('Invalid statement entries.');
                }
                $count += count($group['entries']);
                foreach ($group['entries'] as $entry) {
                    $this->textMap($entry, ['date', 'type', 'number', 'reference', 'description', 'debit', 'credit', 'balance']);
                }
                $this->textMap($group['aging'] ?? [], ['unspecified', 'current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus', 'total']);
            }
            if ($count > 1000) {
                throw new InvalidArgumentException('Issued statement exceeds the entry limit.');
            }
        }
        app(DocumentRenderLimits::class)->assertDocument(new DocumentData($data['type'], $data['document_locale'], $data['company'], $data['customer'], $data['document'], $data['lines'], $statement, $presentation));
    }

    /** @param array<array-key,mixed> $map
     * @param  list<string>  $allowed
     */
    private function keys(array $map, array $allowed): void
    {
        if (array_diff(array_keys($map), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown issued content field.');
        }
    }

    /** @param array<array-key,mixed> $map
     * @param  list<string>  $allowed
     */
    private function textMap(array $map, array $allowed): void
    {
        $this->keys($map, $allowed);
        foreach ($map as $key => $value) {
            if (! is_string($value) && $value !== null) {
                throw new InvalidArgumentException('Invalid issued text or decimal.');
            }
            if (is_string($value) && in_array($key, ['quantity', 'unit_price', 'discount', 'tax', 'total', 'subtotal', 'discount_total', 'tax_total', 'grand_total', 'exchange_rate', 'amount_base', 'payment_currency_amount', 'settlement_base_value', 'opening_balance', 'closing_balance', 'total_debits', 'total_credits', 'debit', 'credit', 'balance', 'unspecified', 'current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'], true)
                && ! preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
                throw new InvalidArgumentException('Invalid exact issued value.');
            }
        }
    }
}
