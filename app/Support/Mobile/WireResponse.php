<?php

declare(strict_types=1);

namespace App\Support\Mobile;

use stdClass;

/** Fail closed against the frozen response constraints; never shorten mature data. */
final class WireResponse
{
    public static function accepts(mixed $value, string $schema): bool
    {
        return self::matches($value, json_decode(file_get_contents(base_path('docs/integration/mobile-v1/schemas/'.$schema.'.json')), true, 512, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $schema */
    private static function matches(mixed $value, array $schema): bool
    {
        foreach (['oneOf', 'anyOf'] as $choice) {
            if (isset($schema[$choice])) {
                $matches = count(array_filter($schema[$choice], fn (array $candidate): bool => self::matches($value, $candidate)));
                if ($matches < 1 || ($choice === 'oneOf' && $matches !== 1)) {
                    return false;
                }
            }
        }
        $type = match (true) {
            $value === null => 'null', is_string($value) => 'string', is_bool($value) => 'boolean',
            is_int($value) => 'integer', is_array($value) => 'array', $value instanceof stdClass => 'object', default => 'number',
        };
        if (isset($schema['type']) && ! in_array($type, (array) $schema['type'], true)) {
            return false;
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return false;
        }
        if (is_string($value)) {
            if (mb_strlen($value) < ($schema['minLength'] ?? 0) || mb_strlen($value) > ($schema['maxLength'] ?? PHP_INT_MAX)) {
                return false;
            }
            if (isset($schema['pattern']) && preg_match('~'.$schema['pattern'].'~uD', $value) !== 1) {
                return false;
            }
        }
        if (is_int($value) && ($value < ($schema['minimum'] ?? PHP_INT_MIN) || $value > ($schema['maximum'] ?? PHP_INT_MAX))) {
            return false;
        }
        if (is_array($value)) {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? PHP_INT_MAX)) {
                return false;
            }
            foreach ($value as $item) {
                if (isset($schema['items']) && ! self::matches($item, $schema['items'])) {
                    return false;
                }
            }
        }
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            if (array_diff($schema['required'] ?? [], array_keys($properties)) !== []) {
                return false;
            }
            foreach ($properties as $key => $item) {
                if (! isset($schema['properties'][$key])) {
                    if (($schema['additionalProperties'] ?? true) === false) {
                        return false;
                    }
                } elseif (! self::matches($item, $schema['properties'][$key])) {
                    return false;
                }
            }
        }

        return true;
    }
}
