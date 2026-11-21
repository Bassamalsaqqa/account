<?php

declare(strict_types=1);

namespace App\Support\Mobile;

use InvalidArgumentException;

final class SalesInvoiceCursorCodec
{
    public const int CURSOR_VERSION = 1;

    public const int MAX_CURSOR_LENGTH = 255;

    /**
     * Encode an opaque, versioned, integrity-protected cursor bound to tenant, filters, and ULID position.
     *
     * @param  array{status: ?string, search: ?string, customer_id: ?string, date_from: ?string, date_to: ?string}  $filters
     */
    public static function encode(string $companyPublicId, array $filters, string $lastPublicId): string
    {
        $filterHash = self::hashFilters($filters);

        $payload = [
            'v' => self::CURSOR_VERSION,
            'c' => $companyPublicId,
            'fh' => $filterHash,
            'p' => $lastPublicId,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $key = (string) config('app.key');
        $mac = hash_hmac('sha256', $json, $key);

        return rtrim(strtr(base64_encode($json.'.'.$mac), '+/', '-_'), '=');
    }

    /**
     * Decode and strictly validate an integrity-protected pagination cursor.
     *
     * @param  array{status: ?string, search: ?string, customer_id: ?string, date_from: ?string, date_to: ?string}  $filters
     *
     * @throws InvalidArgumentException
     */
    public static function decode(?string $cursor, string $companyPublicId, array $filters): ?string
    {
        if ($cursor === null || trim($cursor) === '') {
            return null;
        }

        if (strlen($cursor) > self::MAX_CURSOR_LENGTH) {
            throw new InvalidArgumentException('Cursor exceeds maximum length of 255 characters.');
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Malformed pagination cursor encoding.');
        }

        $lastDot = strrpos($decoded, '.');
        if ($lastDot === false) {
            throw new InvalidArgumentException('Missing cursor integrity signature.');
        }

        $json = substr($decoded, 0, $lastDot);
        $providedMac = substr($decoded, $lastDot + 1);

        $key = (string) config('app.key');
        $expectedMac = hash_hmac('sha256', $json, $key);

        if (! hash_equals($expectedMac, $providedMac)) {
            throw new InvalidArgumentException('Tampered pagination cursor signature.');
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Malformed cursor payload JSON.', 0, $e);
        }

        if (! is_array($data)
            || ! isset($data['v'], $data['c'], $data['fh'], $data['p'])
            || ! is_int($data['v'])
            || $data['v'] !== self::CURSOR_VERSION
            || ! is_string($data['c'])
            || ! is_string($data['fh'])
            || ! is_string($data['p'])) {
            throw new InvalidArgumentException('Invalid pagination cursor payload.');
        }

        if ($data['c'] !== $companyPublicId) {
            throw new InvalidArgumentException('Cross-tenant pagination cursor reuse is rejected.');
        }

        $expectedFilterHash = self::hashFilters($filters);
        if (! hash_equals($expectedFilterHash, $data['fh'])) {
            throw new InvalidArgumentException('Cross-filter pagination cursor reuse is rejected.');
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $data['p']) !== 1) {
            throw new InvalidArgumentException('Invalid pagination cursor position.');
        }

        return $data['p'];
    }

    /**
     * @param  array{status: ?string, search: ?string, customer_id: ?string, date_from: ?string, date_to: ?string}  $filters
     */
    public static function hashFilters(array $filters): string
    {
        $normalized = [
            'status' => $filters['status'] ?? null,
            'search' => $filters['search'] ?? null,
            'customer_id' => $filters['customer_id'] ?? null,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
        ];

        return hash('xxh128', json_encode($normalized, JSON_THROW_ON_ERROR));
    }
}
