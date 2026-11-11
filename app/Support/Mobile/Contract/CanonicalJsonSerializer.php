<?php

declare(strict_types=1);

namespace App\Support\Mobile\Contract;

use App\Support\Mobile\Contract\Exceptions\CanonicalSerializationException;
use Brick\Math\BigDecimal;
use stdClass;

final class CanonicalJsonSerializer
{
    /**
     * Serializes any supported value to canonical JSON string according to
     * Small Trader Restricted Canonical JSON Profile v1 (RFC 8785 subset).
     */
    public static function serialize(mixed $obj, int $depth = 0): string
    {
        return self::serializeInternal($obj, $depth);
    }

    /**
     * Returns canonical JSON bytes encoded as UTF-8.
     */
    public static function serializeToUtf8Bytes(mixed $obj): string
    {
        return self::serialize($obj);
    }

    /**
     * Computes 'sha256:<64 lowercase hex>' digest over canonical JSON bytes.
     */
    public static function computeDigest(mixed $obj): string
    {
        $bytes = self::serializeToUtf8Bytes($obj);

        return 'sha256:'.hash('sha256', $bytes);
    }

    /**
     * Parses a raw JSON string using StrictJsonParser (rejecting duplicate keys,
     * floats, negative zero, and unpaired surrogates) and serializes it canonically.
     */
    public static function serializeRaw(string $rawJson): string
    {
        $element = StrictJsonParser::parse($rawJson);

        return self::serialize($element);
    }

    /**
     * Parses a raw JSON string strictly and computes its canonical SHA-256 digest.
     */
    public static function computeDigestForRawJson(string $rawJson): string
    {
        $element = StrictJsonParser::parse($rawJson);

        return self::computeDigest($element);
    }

    private static function serializeInternal(mixed $obj, int $depth): string
    {
        if ($depth > ContractConstants::MAX_NESTING_DEPTH) {
            throw new CanonicalSerializationException(
                'Serialization recursion depth exceeded maximum allowed '.ContractConstants::MAX_NESTING_DEPTH
            );
        }

        if ($obj === null) {
            return 'null';
        }

        if (is_bool($obj)) {
            return $obj ? 'true' : 'false';
        }

        if (is_float($obj)) {
            throw new CanonicalSerializationException(
                'IEEE 754 floats are strictly forbidden in Restricted Canonical JSON Profile; all decimals must be string-encoded'
            );
        }

        if ($obj instanceof BigDecimal) {
            throw new CanonicalSerializationException(
                'Raw BigDecimal cannot be emitted directly as a JSON number in Restricted Canonical JSON Profile; format as a scaled decimal string'
            );
        }

        if (is_int($obj)) {
            if ($obj < ContractConstants::MIN_SAFE_INTEGER || $obj > ContractConstants::MAX_SAFE_INTEGER) {
                throw new CanonicalSerializationException(
                    "Integer '{$obj}' exceeds canonical safe integer domain [-(2^53-1), 2^53-1]"
                );
            }

            return (string) $obj;
        }

        if ($obj instanceof DecimalLexeme) {
            return self::escapeString($obj->toPlainString());
        }

        if (is_string($obj)) {
            return self::escapeString($obj);
        }

        if ($obj instanceof StrictJsonElement) {
            return match (true) {
                $obj instanceof StrictJsonNull => 'null',
                $obj instanceof StrictJsonBoolean => $obj->value ? 'true' : 'false',
                $obj instanceof StrictJsonString => self::escapeString($obj->value),
                $obj instanceof StrictJsonNumber => self::serializeNumberElement($obj),
                $obj instanceof StrictJsonArray => self::serializeArrayElements($obj->elements, $depth),
                $obj instanceof StrictJsonObject => self::serializeObjectProperties($obj->properties, $depth),
                default => throw new CanonicalSerializationException('Unsupported StrictJsonElement: '.get_class($obj)),
            };
        }

        if ($obj instanceof stdClass) {
            $props = (array) $obj;

            return self::serializeAssociativeArray($props, $depth);
        }

        if (is_array($obj)) {
            if ($obj === [] || array_is_list($obj)) {
                return self::serializeArrayElements($obj, $depth);
            }

            return self::serializeAssociativeArray($obj, $depth);
        }

        throw new CanonicalSerializationException(
            'Unsupported data type for canonical serialization: '.get_debug_type($obj)
        );
    }

    private static function serializeNumberElement(StrictJsonNumber $number): string
    {
        if (! $number->isInt) {
            throw new CanonicalSerializationException(
                'IEEE 754 floats are strictly forbidden in Restricted Canonical JSON Profile; all decimals must be string-encoded'
            );
        }

        if ($number->rawString === '-0' || ($number->rawString !== '0' && preg_match('/^-0+$/', $number->rawString))) {
            throw new CanonicalSerializationException("Negative zero '-0' is forbidden in canonical JSON profile");
        }

        if ($number->value < ContractConstants::MIN_SAFE_INTEGER || $number->value > ContractConstants::MAX_SAFE_INTEGER) {
            throw new CanonicalSerializationException(
                "Integer '{$number->rawString}' exceeds canonical safe integer domain [-(2^53-1), 2^53-1]"
            );
        }

        return (string) $number->value;
    }

    /**
     * @param  array<int, mixed>  $elements
     */
    private static function serializeArrayElements(array $elements, int $depth): string
    {
        $parts = [];
        foreach ($elements as $item) {
            $parts[] = self::serializeInternal($item, $depth + 1);
        }

        return '['.implode(',', $parts).']';
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function serializeObjectProperties(array $properties, int $depth): string
    {
        $keys = array_keys($properties);
        $sortedKeys = self::sortKeysUtf16($keys);

        $parts = [];
        foreach ($sortedKeys as $k) {
            $parts[] = self::escapeString($k).':'.self::serializeInternal($properties[$k], $depth + 1);
        }

        return '{'.implode(',', $parts).'}';
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function serializeAssociativeArray(array $properties, int $depth): string
    {
        $stringMap = [];
        foreach ($properties as $k => $v) {
            $stringMap[(string) $k] = $v;
        }

        $keys = array_keys($stringMap);
        $sortedKeys = self::sortKeysUtf16($keys);

        $parts = [];
        foreach ($sortedKeys as $k) {
            $parts[] = self::escapeString($k).':'.self::serializeInternal($stringMap[$k], $depth + 1);
        }

        return '{'.implode(',', $parts).'}';
    }

    /**
     * Sorts keys in ascending order of UTF-16 code units (RFC 8785 section 3.2.3).
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function sortKeysUtf16(array $keys): array
    {
        usort($keys, function (string $a, string $b): int {
            return strcmp(
                mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'),
                mb_convert_encoding($b, 'UTF-16BE', 'UTF-8')
            );
        });

        return $keys;
    }

    /**
     * Escapes string according to RFC 8785 rules.
     */
    public static function escapeString(string $s): string
    {
        if (! mb_check_encoding($s, 'UTF-8')) {
            throw new CanonicalSerializationException('Invalid UTF-8 or unpaired Unicode surrogate detected');
        }

        $len = strlen($s);
        $out = '"';
        for ($i = 0; $i < $len; $i++) {
            $byte = $s[$i];
            $ord = ord($byte);
            if ($ord < 0x80) {
                if ($byte === '"') {
                    $out .= '\\"';
                } elseif ($byte === '\\') {
                    $out .= '\\\\';
                } elseif ($byte === "\x08") {
                    $out .= '\\b';
                } elseif ($byte === "\x0C") {
                    $out .= '\\f';
                } elseif ($byte === "\n") {
                    $out .= '\\n';
                } elseif ($byte === "\r") {
                    $out .= '\\r';
                } elseif ($byte === "\t") {
                    $out .= '\\t';
                } elseif ($ord < 0x20) {
                    $out .= sprintf('\\u%04x', $ord);
                } else {
                    $out .= $byte;
                }
            } else {
                // Check if this multi-byte sequence encodes a surrogate codepoint (U+D800..U+DFFF)
                // In 3-byte UTF-8, U+D800..U+DFFF starts with 0xED followed by 0xA0..0xBF
                if ($ord === 0xED && $i + 1 < $len && ord($s[$i + 1]) >= 0xA0) {
                    throw new CanonicalSerializationException('Unpaired Unicode surrogate detected in UTF-8 sequence');
                }
                $out .= $byte;
            }
        }

        return $out.'"';
    }
}
