<?php

declare(strict_types=1);

namespace App\Support\Mobile\Contract;

use App\Support\Mobile\Contract\Exceptions\CanonicalSerializationException;

final class StrictJsonParser
{
    private int $pos = 0;

    private int $len;

    private int $currentDepth = 0;

    private function __construct(
        private readonly string $src
    ) {
        $this->len = strlen($src);
        if ($this->len > ContractConstants::MAX_PAYLOAD_BYTES) {
            throw new CanonicalSerializationException("Payload size {$this->len} exceeds maximum allowed ".ContractConstants::MAX_PAYLOAD_BYTES);
        }
        if (! mb_check_encoding($src, 'UTF-8')) {
            throw new CanonicalSerializationException('Invalid UTF-8 encoding in JSON payload');
        }
    }

    public static function parse(string $jsonStr): StrictJsonElement
    {
        $parser = new self($jsonStr);
        $parser->skipWhitespace();
        if ($parser->pos >= $parser->len) {
            throw new CanonicalSerializationException('Empty JSON input');
        }

        $element = $parser->parseValue();
        $parser->skipWhitespace();
        if ($parser->pos < $parser->len) {
            throw new CanonicalSerializationException("Unexpected trailing character '{$parser->src[$parser->pos]}' at position {$parser->pos}");
        }

        return $element;
    }

    private function parseValue(): StrictJsonElement
    {
        $this->skipWhitespace();
        if ($this->pos >= $this->len) {
            throw new CanonicalSerializationException('Unexpected end of JSON input');
        }

        $c = $this->src[$this->pos];

        return match ($c) {
            '{' => $this->parseObject(),
            '[' => $this->parseArray(),
            '"' => $this->parseString(),
            't', 'f' => $this->parseBoolean(),
            'n' => $this->parseNull(),
            '-', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' => $this->parseNumber(),
            default => throw new CanonicalSerializationException("Unexpected character '{$c}' at position {$this->pos}"),
        };
    }

    private function parseObject(): StrictJsonObject
    {
        $this->currentDepth++;
        if ($this->currentDepth > ContractConstants::MAX_NESTING_DEPTH) {
            throw new CanonicalSerializationException('JSON recursion depth exceeded maximum allowed '.ContractConstants::MAX_NESTING_DEPTH);
        }

        try {
            $this->consume('{');
            $this->skipWhitespace();

            $props = [];
            if ($this->peek() === '}') {
                $this->consume('}');

                return new StrictJsonObject($props);
            }

            while (true) {
                $this->skipWhitespace();
                if ($this->peek() !== '"') {
                    throw new CanonicalSerializationException("Expected string property key at position {$this->pos}, got '{$this->peek()}'");
                }

                $key = $this->parseString()->value;
                if (array_key_exists($key, $props)) {
                    throw new CanonicalSerializationException("Duplicate JSON key detected: '{$key}'");
                }

                $this->skipWhitespace();
                $this->consume(':');
                $this->skipWhitespace();

                $value = $this->parseValue();
                $props[$key] = $value;

                $this->skipWhitespace();
                $next = $this->peek();
                if ($next === ',') {
                    $this->consume(',');
                } elseif ($next === '}') {
                    $this->consume('}');
                    break;
                } else {
                    throw new CanonicalSerializationException("Expected ',' or '}' at position {$this->pos}, got '{$next}'");
                }
            }

            return new StrictJsonObject($props);
        } finally {
            $this->currentDepth--;
        }
    }

    private function parseArray(): StrictJsonArray
    {
        $this->currentDepth++;
        if ($this->currentDepth > ContractConstants::MAX_NESTING_DEPTH) {
            throw new CanonicalSerializationException('JSON recursion depth exceeded maximum allowed '.ContractConstants::MAX_NESTING_DEPTH);
        }

        try {
            $this->consume('[');
            $this->skipWhitespace();

            $items = [];
            if ($this->peek() === ']') {
                $this->consume(']');

                return new StrictJsonArray($items);
            }

            while (true) {
                $this->skipWhitespace();
                $items[] = $this->parseValue();

                $this->skipWhitespace();
                $next = $this->peek();
                if ($next === ',') {
                    $this->consume(',');
                } elseif ($next === ']') {
                    $this->consume(']');
                    break;
                } else {
                    throw new CanonicalSerializationException("Expected ',' or ']' at position {$this->pos}, got '{$next}'");
                }
            }

            return new StrictJsonArray($items);
        } finally {
            $this->currentDepth--;
        }
    }

    private function parseString(): StrictJsonString
    {
        $this->consume('"');
        $sb = '';

        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos++];
            if ($c === '"') {
                return new StrictJsonString($sb);
            }

            if (strlen($sb) >= ContractConstants::MAX_STRING_LENGTH) {
                throw new CanonicalSerializationException('JSON string length exceeds maximum allowed '.ContractConstants::MAX_STRING_LENGTH);
            }

            if ($c === '\\') {
                if ($this->pos >= $this->len) {
                    throw new CanonicalSerializationException('Unterminated escape sequence in string');
                }
                $esc = $this->src[$this->pos++];
                switch ($esc) {
                    case '"':
                        $sb .= '"';
                        break;
                    case '\\':
                        $sb .= '\\';
                        break;
                    case '/':
                        $sb .= '/';
                        break;
                    case 'b':
                        $sb .= "\x08";
                        break;
                    case 'f':
                        $sb .= "\x0C";
                        break;
                    case 'n':
                        $sb .= "\n";
                        break;
                    case 'r':
                        $sb .= "\r";
                        break;
                    case 't':
                        $sb .= "\t";
                        break;
                    case 'u':
                        if ($this->pos + 4 > $this->len) {
                            throw new CanonicalSerializationException('Incomplete \\u hex escape in string');
                        }
                        $hex = substr($this->src, $this->pos, 4);
                        if (! preg_match('/^[0-9a-fA-F]{4}$/', $hex)) {
                            throw new CanonicalSerializationException("Invalid hex in \\u escape: {$hex}");
                        }
                        $this->pos += 4;
                        $code = hexdec($hex);

                        if ($code >= 0xD800 && $code <= 0xDBFF) {
                            // High surrogate, look for following low surrogate \uDC00..\uDFFF
                            if ($this->pos + 6 <= $this->len && substr($this->src, $this->pos, 2) === '\\u') {
                                $hex2 = substr($this->src, $this->pos + 2, 4);
                                if (preg_match('/^[0-9a-fA-F]{4}$/', $hex2)) {
                                    $code2 = hexdec($hex2);
                                    if ($code2 >= 0xDC00 && $code2 <= 0xDFFF) {
                                        $this->pos += 6;
                                        $codePoint = 0x10000 + (($code - 0xD800) << 10) + ($code2 - 0xDC00);
                                        $sb .= mb_chr($codePoint, 'UTF-8');
                                        break;
                                    }
                                }
                            }
                            throw new CanonicalSerializationException('Unpaired high Unicode surrogate detected: 0x'.dechex($code));
                        } elseif ($code >= 0xDC00 && $code <= 0xDFFF) {
                            throw new CanonicalSerializationException('Unpaired low Unicode surrogate detected: 0x'.dechex($code));
                        }

                        $sb .= mb_chr($code, 'UTF-8');
                        break;
                    default:
                        throw new CanonicalSerializationException("Invalid escape sequence '\\{$esc}' in string");
                }
            } else {
                $ord = ord($c);
                if ($ord < 0x20) {
                    throw new CanonicalSerializationException("Unescaped control character in string literal at position {$this->pos}");
                }
                $sb .= $c;
            }
        }

        throw new CanonicalSerializationException('Unterminated string literal');
    }

    private function parseNumber(): StrictJsonNumber
    {
        $start = $this->pos;
        $isNegative = false;
        if ($this->peek() === '-') {
            $this->consume('-');
            $isNegative = true;
        }

        $intStart = $this->pos;
        while ($this->pos < $this->len && $this->src[$this->pos] >= '0' && $this->src[$this->pos] <= '9') {
            $this->pos++;
        }
        $intDigits = substr($this->src, $intStart, $this->pos - $intStart);
        if ($intDigits === '') {
            throw new CanonicalSerializationException("Expected digits for number at position {$this->pos}");
        }

        $isFloat = false;
        if ($this->pos < $this->len && $this->src[$this->pos] === '.') {
            $isFloat = true;
            $this->pos++;
            $fracStart = $this->pos;
            while ($this->pos < $this->len && $this->src[$this->pos] >= '0' && $this->src[$this->pos] <= '9') {
                $this->pos++;
            }
            if ($this->pos === $fracStart) {
                throw new CanonicalSerializationException("Expected digits after decimal point at position {$this->pos}");
            }
        }

        if ($this->pos < $this->len && ($this->src[$this->pos] === 'e' || $this->src[$this->pos] === 'E')) {
            $isFloat = true;
            $this->pos++;
            if ($this->pos < $this->len && ($this->src[$this->pos] === '+' || $this->src[$this->pos] === '-')) {
                $this->pos++;
            }
            $expStart = $this->pos;
            while ($this->pos < $this->len && $this->src[$this->pos] >= '0' && $this->src[$this->pos] <= '9') {
                $this->pos++;
            }
            if ($this->pos === $expStart) {
                throw new CanonicalSerializationException("Expected digits for exponent at position {$this->pos}");
            }
        }

        $raw = substr($this->src, $start, $this->pos - $start);

        if ($isFloat) {
            throw new CanonicalSerializationException('IEEE 754 floats are strictly forbidden in Restricted Canonical JSON Profile; all decimals must be string-encoded');
        }

        if ($isNegative && preg_match('/^0+$/', $intDigits)) {
            throw new CanonicalSerializationException("Canonical integer negative zero '-0' is strictly forbidden");
        }

        if (strlen($intDigits) > 1 && $intDigits[0] === '0') {
            throw new CanonicalSerializationException("Leading zeros in integer numbers are forbidden: {$raw}");
        }

        $intVal = (int) $raw;
        if ((string) $intVal !== $raw) {
            throw new CanonicalSerializationException("Integer '{$raw}' exceeds canonical safe integer domain [-(2^53-1), 2^53-1]");
        }

        if ($intVal < ContractConstants::MIN_SAFE_INTEGER || $intVal > ContractConstants::MAX_SAFE_INTEGER) {
            throw new CanonicalSerializationException("Integer '{$raw}' exceeds canonical safe integer domain [-(2^53-1), 2^53-1]");
        }

        return new StrictJsonNumber($raw, $intVal, true);
    }

    private function parseBoolean(): StrictJsonBoolean
    {
        if (str_starts_with(substr($this->src, $this->pos), 'true')) {
            $this->pos += 4;

            return new StrictJsonBoolean(true);
        }
        if (str_starts_with(substr($this->src, $this->pos), 'false')) {
            $this->pos += 5;

            return new StrictJsonBoolean(false);
        }

        throw new CanonicalSerializationException("Invalid boolean token at position {$this->pos}");
    }

    private function parseNull(): StrictJsonNull
    {
        if (str_starts_with(substr($this->src, $this->pos), 'null')) {
            $this->pos += 4;

            return new StrictJsonNull;
        }

        throw new CanonicalSerializationException("Invalid null token at position {$this->pos}");
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->len && in_array($this->src[$this->pos], [' ', "\t", "\n", "\r"], true)) {
            $this->pos++;
        }
    }

    private function peek(): string
    {
        return $this->pos < $this->len
            ? $this->src[$this->pos]
            : throw new CanonicalSerializationException('Unexpected end of JSON');
    }

    private function consume(string $expected): void
    {
        if ($this->pos >= $this->len || $this->src[$this->pos] !== $expected) {
            $actual = $this->pos < $this->len ? "'{$this->src[$this->pos]}'" : 'EOF';
            throw new CanonicalSerializationException("Expected '{$expected}' at position {$this->pos}, got {$actual}");
        }
        $this->pos++;
    }
}
