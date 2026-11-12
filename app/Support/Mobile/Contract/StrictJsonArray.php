<?php

declare(strict_types=1);

namespace App\Support\Mobile\Contract;

use App\Support\Mobile\Contract\Exceptions\CanonicalSerializationException;

final readonly class StrictJsonArray implements StrictJsonElement
{
    /**
     * @param  list<StrictJsonElement>  $elements
     */
    public function __construct(
        public array $elements = []
    ) {}

    public function count(): int
    {
        return count($this->elements);
    }

    public function get(int $index): StrictJsonElement
    {
        return $this->elements[$index]
            ?? throw new CanonicalSerializationException("Array index out of bounds: {$index}");
    }

    /**
     * @return list<mixed>
     */
    public function toNative(): array
    {
        $result = [];
        foreach ($this->elements as $v) {
            $result[] = match (true) {
                $v instanceof StrictJsonObject => $v->toNative(),
                $v instanceof self => $v->toNative(),
                $v instanceof StrictJsonString => $v->value,
                $v instanceof StrictJsonNumber => $v->value,
                $v instanceof StrictJsonBoolean => $v->value,
                $v instanceof StrictJsonNull => null,
                default => null,
            };
        }

        return $result;
    }
}
