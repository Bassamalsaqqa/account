<?php

declare(strict_types=1);

namespace App\Support\Mobile\Contract;

use App\Support\Mobile\Contract\Exceptions\CanonicalSerializationException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Stringable;

final readonly class DecimalLexeme implements Stringable
{
    public function __construct(
        public string $lexeme
    ) {
        if (! preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/', $this->lexeme)) {
            throw new CanonicalSerializationException("Invalid decimal lexeme format: '{$this->lexeme}'");
        }
    }

    public static function of(string $lexeme): self
    {
        return new self($lexeme);
    }

    public function toPlainString(): string
    {
        return $this->lexeme;
    }

    /**
     * Converts to Brick\Math\BigDecimal ONLY after canonical digest verification.
     */
    public function toBigDecimal(): BigDecimal
    {
        return BigDecimal::of($this->lexeme);
    }

    public function toScale(int $scale, RoundingMode $roundingMode = RoundingMode::UNNECESSARY): BigDecimal
    {
        return $this->toBigDecimal()->toScale($scale, $roundingMode);
    }

    public function __toString(): string
    {
        return $this->lexeme;
    }
}
