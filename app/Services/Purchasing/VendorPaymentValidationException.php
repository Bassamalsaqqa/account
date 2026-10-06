<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

final class VendorPaymentValidationException extends \InvalidArgumentException
{
    public function __construct(public readonly string $translationKey, ?string $diagnostic = null)
    {
        parent::__construct($diagnostic ?? __($translationKey, [], 'en'));
    }
}
