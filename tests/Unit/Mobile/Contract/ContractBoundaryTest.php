<?php

declare(strict_types=1);

namespace Tests\Unit\Mobile\Contract;

use App\Support\Mobile\Contract\CanonicalJsonSerializer as S;
use App\Support\Mobile\Contract\DecimalLexeme;
use App\Support\Mobile\Contract\Exceptions\CanonicalSerializationException;
use App\Support\Mobile\Contract\StrictJsonNumber;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\TestCase;

final class ContractBoundaryTest extends TestCase
{
    private function rejects(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected rejection');
        } catch (CanonicalSerializationException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    public function test_objects(): void
    {
        $raw = '{"2":true,"10":false,"1":null,"0":[],"-1":{},"01":"x","😀":1,"￿":2}';
        $expected = '{"-1":{},"0":[],"01":"x","1":null,"10":false,"2":true,"😀":1,"￿":2}';
        self::assertSame($expected, S::serializeRaw($raw));
        self::assertSame($expected, S::serialize(json_decode($raw)));
        foreach (['{}', '[]', '{"0":true}', '{"0":true,"1":false}'] as $raw) {
            self::assertSame($raw, S::serializeRaw($raw));
            self::assertSame($raw, S::serialize(json_decode($raw)));
        }
    }

    public function test_string_limits(): void
    {
        foreach (['a', 'ع', '€', '😀'] as $char) {
            $units = $char === '😀' ? 2 : 1;
            foreach ([65536 - $units, 65537 - $units] as $prefix) {
                $text = str_repeat('a', $prefix).$char;
                foreach (['"'.$text.'"', json_encode($text, JSON_THROW_ON_ERROR)] as $input) {
                    foreach ([$input, '{'.$input.':null}'] as $raw) {
                        if ($prefix + $units === 65536) {
                            self::assertSame(S::serialize(json_decode($raw)), S::serializeRaw($raw));
                        } else {
                            $this->rejects(fn () => S::serializeRaw($raw));
                            $this->rejects(fn () => S::serialize(json_decode($raw)));
                        }
                    }
                }
            }
            $text = str_repeat($char, intdiv(65536, $units));
            self::assertSame('"'.$text.'"', S::serializeRaw(json_encode($text, JSON_THROW_ON_ERROR)));
            $this->rejects(fn () => S::serializeRaw(json_encode($text.$char, JSON_THROW_ON_ERROR)));
        }
    }

    public function test_numbers(): void
    {
        foreach (['NaN', '9007199254740992', '-9007199254740992', '-0', '01', '+1', '1.0', '1e0', "1\n", '2', '999999999999999999999'] as $raw) {
            $this->rejects(fn () => new StrictJsonNumber($raw, 1));
        }
        $this->rejects(fn () => new StrictJsonNumber('1', 1, false));
        foreach ([0, 1, -1, 9007199254740991, -9007199254740991] as $value) {
            self::assertSame((string) $value, S::serialize(new StrictJsonNumber((string) $value, $value)));
            self::assertSame((string) $value, S::serializeRaw((string) $value));
        }
    }

    public function test_decimal(): void
    {
        foreach (["1\n", "1\r\n", ' 1', '1 ', "\t1", "1\t"] as $raw) {
            $this->rejects(fn () => DecimalLexeme::of($raw));
        }
        $decimal = DecimalLexeme::of('1.2350');
        self::assertSame('"1.2350"', S::serialize($decimal));
        self::assertSame('1.24', (string) $decimal->toScale(2, RoundingMode::HALF_UP));
        self::assertSame('"1.2350"', S::serialize($decimal));
        $this->expectException(RoundingNecessaryException::class);
        $decimal->toScale(2);
    }

    public function test_other_bounds(): void
    {
        foreach (["\xFF", "\xC0\xAF", "\xED\xA0\x80"] as $invalid) {
            $this->rejects(fn () => S::serialize($invalid));
            $this->rejects(fn () => S::serializeRaw('"'.$invalid.'"'));
        }
        $raw = str_repeat('[', 32).'0'.str_repeat(']', 32);
        self::assertSame($raw, S::serializeRaw($raw));
        self::assertSame($raw, S::serialize(json_decode($raw)));
        foreach ([str_repeat('[', 33).'0'.str_repeat(']', 33), str_repeat('[', 33).str_repeat(']', 33)] as $raw) {
            $this->rejects(fn () => S::serializeRaw($raw));
            $this->rejects(fn () => S::serialize(json_decode($raw)));
        }
        self::assertSame('0', S::serializeRaw(str_repeat(' ', 1048575).'0'));
        $this->rejects(fn () => S::serializeRaw(str_repeat(' ', 1048576).'0'));
        $items = array_fill(0, 16, str_repeat('a', 65532));
        $items[] = str_repeat('b', 12);
        self::assertSame(1048576, strlen(S::serialize($items)));
        $items[16] .= 'b';
        $this->rejects(fn () => S::serialize($items));
    }
}
