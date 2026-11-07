<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Exceptions;

use InvalidArgumentException;

final class PurchasingReversalBlockedException extends InvalidArgumentException
{
    /**
     * @param list<array{
     *     code: string,
     *     message: string,
     *     translation_key: string,
     *     translation_params?: array<string, mixed>,
     *     details?: array<string, mixed>
     * }> $blockers
     */
    public function __construct(
        public readonly array $blockers,
        string $message = 'Purchasing reversal is blocked by business dependencies.'
    ) {
        parent::__construct($message);
    }

    /**
     * @return list<array{
     *     code: string,
     *     message: string,
     *     translation_key: string,
     *     translation_params?: array<string, mixed>,
     *     details?: array<string, mixed>
     * }>
     */
    public function getBlockers(): array
    {
        return $this->blockers;
    }
}
