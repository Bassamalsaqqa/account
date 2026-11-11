<?php

declare(strict_types=1);

namespace App\Support\Mobile\Contract;

use App\Support\Mobile\Contract\Exceptions\CanonicalSerializationException;

final readonly class StrictJsonObject implements StrictJsonElement
{
    /**
     * @param  array<string, StrictJsonElement>  $properties
     */
    public function __construct(
        public array $properties = []
    ) {}

    public function get(string $key): ?StrictJsonElement
    {
        return $this->properties[$key] ?? null;
    }

    public function require(string $key): StrictJsonElement
    {
        return $this->properties[$key]
            ?? throw new CanonicalSerializationException("Missing required property '{$key}'");
    }

    /**
     * @return array<string, mixed>
     */
    public function toNative(): array
    {
        $result = [];
        foreach ($this->properties as $k => $v) {
            $result[$k] = match (true) {
                $v instanceof self => $v->toNative(),
                $v instanceof StrictJsonArray => $v->toNative(),
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
