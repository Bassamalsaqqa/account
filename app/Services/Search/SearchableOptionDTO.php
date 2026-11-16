<?php

declare(strict_types=1);

namespace App\Services\Search;

final class SearchableOptionDTO
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $sublabel = null,
        public readonly array $meta = [],
        public readonly bool $selected = false,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     sublabel: ?string,
     *     meta: array<string, mixed>,
     *     selected: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'sublabel' => $this->sublabel,
            'meta' => $this->meta,
            'selected' => $this->selected,
        ];
    }
}
