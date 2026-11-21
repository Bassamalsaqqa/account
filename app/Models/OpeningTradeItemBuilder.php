<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Accounting\OpeningTradeEventScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModelClass of OpeningTradeItem
 *
 * @extends Builder<TModelClass>
 */
class OpeningTradeItemBuilder extends Builder
{
    /** @var TModelClass|null */
    private ?OpeningTradeItem $allowedModel = null;

    /**
     * Internal binding from OpeningTradeItem::performUpdate under canonical authority.
     * Ordinary callers cannot enable writes without an active canonical scope.
     *
     * @param  TModelClass  $model
     * @return $this
     */
    public function allowModelSave(OpeningTradeItem $model): static
    {
        $this->allowedModel = $model;

        return $this;
    }

    /**
     * Bulk updates bypass canonical action authority and are strictly prohibited.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $eventScope = app(OpeningTradeEventScope::class);
        if ($this->allowedModel === null || ! $eventScope->hasActiveCapability()) {
            throw new ImmutableRecordException('Direct bulk updates on opening trade items are prohibited. Use canonical actions.');
        }

        return parent::update($values);
    }

    /**
     * Bulk deletes bypass canonical action authority and are strictly prohibited.
     */
    public function delete(): mixed
    {
        throw new ImmutableRecordException('Opening trade items cannot be deleted.');
    }
}
