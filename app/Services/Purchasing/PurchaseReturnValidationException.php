<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use InvalidArgumentException;

class PurchaseReturnValidationException extends InvalidArgumentException
{
    /**
     * @param  array<string, mixed>  $translationParams
     */
    public function __construct(
        public readonly string $translationKey,
        string $englishMessage,
        public readonly array $translationParams = []
    ) {
        parent::__construct($englishMessage);
    }
}
