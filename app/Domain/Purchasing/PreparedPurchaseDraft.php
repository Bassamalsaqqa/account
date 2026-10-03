<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

final readonly class PreparedPurchaseDraft
{
    /**
     * @param  array<string, mixed>  $header
     * @param  list<array{attributes: array<string, mixed>, lots: list<array<string, mixed>>}>  $lines
     */
    public function __construct(public array $header, public array $lines) {}
}
