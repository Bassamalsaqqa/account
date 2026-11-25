<?php

declare(strict_types=1);

namespace App\Support\Mobile;

use InvalidArgumentException;
use Throwable;

final class SettlementTargetsCursorCodec
{
    public const int CURSOR_VERSION = 1;

    public const int MAX_CURSOR_LENGTH = 512;

    /**
     * Encode an opaque, versioned, integrity-protected cursor bound to tenant company,
     * party (customer/supplier), currency filter, and chronological position.
     */
    public static function encode(
        string $companyPublicId,
        string $partyPublicId,
        ?string $currencyCode,
        string $businessDate,
        string $targetType,
        string $targetPublicId
    ): string {
        $payload = [
            'v' => self::CURSOR_VERSION,
            'c' => $companyPublicId,
            'p' => $partyPublicId,
            'curr' => $currencyCode !== null ? strtoupper(trim($currencyCode)) : null,
            'd' => $businessDate,
            't' => $targetType,
            'i' => $targetPublicId,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $key = (string) config('app.key');
        $mac = hash_hmac('sha256', $json, $key);

        return rtrim(strtr(base64_encode($json.'.'.$mac), '+/', '-_'), '=');
    }

    /**
     * Decode and strictly validate an integrity-protected pagination cursor.
     *
     * @return array{business_date: string, target_type: string, target_id: string}
     *
     * @throws InvalidArgumentException
     */
    public static function decode(
        ?string $cursor,
        string $companyPublicId,
        string $partyPublicId,
        ?string $currencyCode
    ): ?array {
        if ($cursor === null || trim($cursor) === '') {
            return null;
        }

        if (strlen($cursor) > self::MAX_CURSOR_LENGTH) {
            throw new InvalidArgumentException('Cursor exceeds maximum allowed length.');
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
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Malformed cursor payload JSON.', 0, $e);
        }

        if (! is_array($data)
            || ! isset($data['v'], $data['c'], $data['p'], $data['d'], $data['t'], $data['i'])
            || ! is_int($data['v'])
            || $data['v'] !== self::CURSOR_VERSION
            || ! is_string($data['c'])
            || ! is_string($data['p'])
            || ! is_string($data['d'])
            || ! is_string($data['t'])
            || ! is_string($data['i'])) {
            throw new InvalidArgumentException('Invalid pagination cursor payload.');
        }

        if ($data['c'] !== $companyPublicId) {
            throw new InvalidArgumentException('Cross-tenant pagination cursor reuse is rejected.');
        }

        if ($data['p'] !== $partyPublicId) {
            throw new InvalidArgumentException('Cross-party pagination cursor reuse is rejected.');
        }

        $expectedCurrency = $currencyCode !== null ? strtoupper(trim($currencyCode)) : null;
        $storedCurrency = isset($data['curr']) && is_string($data['curr']) ? strtoupper(trim($data['curr'])) : null;
        if ($storedCurrency !== $expectedCurrency) {
            throw new InvalidArgumentException('Cross-currency pagination cursor reuse is rejected.');
        }

        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $data['d']) !== 1) {
            throw new InvalidArgumentException('Invalid business_date format in cursor.');
        }

        if (! in_array($data['t'], ['invoice', 'purchase', 'opening_trade_item'], true)) {
            throw new InvalidArgumentException('Invalid target_type in cursor.');
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $data['i']) !== 1) {
            throw new InvalidArgumentException('Invalid target_id ULID in cursor.');
        }

        return [
            'business_date' => $data['d'],
            'target_type' => $data['t'],
            'target_id' => $data['i'],
        ];
    }
}
