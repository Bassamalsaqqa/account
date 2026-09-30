<?php

declare(strict_types=1);

namespace App\Domain\Money\Exceptions;

use DateTimeInterface;
use DomainException;

final class UnresolvedExchangeRateException extends DomainException
{
    public static function forCurrency(string $currencyCode, string $baseCurrencyCode, ?DateTimeInterface $asOf = null): self
    {
        $timeStr = $asOf ? ' as of '.$asOf->format('Y-m-d H:i:s') : '';

        return new self("No exchange rate recorded for currency [{$currencyCode}] relative to base currency [{$baseCurrencyCode}]{$timeStr}.");
    }
}
