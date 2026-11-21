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
    private ?OpeningTradeItem $boundModel = null;

    private ?string $boundToken = null;

    private ?OpeningTradeItem $boundInsertModel = null;

    private ?string $boundInsertToken = null;

    /**
     * Bind this builder to an exact model instance and unforgeable capability token for insert.
     *
     * @param  TModelClass  $model
     * @return $this
     */
    public function bindAuthorizedInsert(OpeningTradeItem $model, string $token): static
    {
        $this->boundInsertModel = $model;
        $this->boundInsertToken = $token;

        return $this;
    }

    /**
     * Bind this builder to an exact model instance and unforgeable capability token.
     * Ordinary callers cannot enable updates without an active canonical scope and valid token.
     *
     * @param  TModelClass  $model
     * @return $this
     */
    public function bindAuthorizedUpdate(OpeningTradeItem $model, string $token): static
    {
        $this->boundModel = $model;
        $this->boundToken = $token;

        return $this;
    }

    /**
     * Bulk updates bypass canonical action authority and are strictly prohibited.
     * Updates must be bound to an authorized model save operation with verified query scope and values.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $eventScope = app(OpeningTradeEventScope::class);
        if ($this->boundModel === null || $this->boundToken === null || ! $eventScope->hasActiveCapability()) {
            throw new ImmutableRecordException('Direct bulk updates on opening trade items are prohibited. Use canonical actions.');
        }

        $eventScope->verifyModelUpdateToken($this->boundModel, $this->boundToken);

        // Verify query builder scope is strictly bound to the exact authorized item
        $modelId = $this->boundModel->getKey();
        if ($modelId === null) {
            throw new ImmutableRecordException('Cannot update an unpersisted opening trade item.');
        }

        $wheres = $this->toBase()->wheres;
        $hasExactIdWhere = false;
        foreach ($wheres as $where) {
            $type = $where['type'] ?? '';
            $boolean = $where['boolean'] ?? 'and';
            if ($boolean === 'or') {
                throw new ImmutableRecordException('Broadened query scope is prohibited for opening trade item updates.');
            }
            if ($type === 'Basic' && ($where['column'] ?? '') === $this->boundModel->getQualifiedKeyName() && ($where['operator'] ?? '') === '=') {
                if ((int) $where['value'] !== (int) $modelId) {
                    throw new ImmutableRecordException('Query target does not match authorized opening trade item ID.');
                }
                $hasExactIdWhere = true;
            } elseif ($type === 'Basic' && ($where['column'] ?? '') === 'id' && ($where['operator'] ?? '') === '=') {
                if ((int) $where['value'] !== (int) $modelId) {
                    throw new ImmutableRecordException('Query target does not match authorized opening trade item ID.');
                }
                $hasExactIdWhere = true;
            }
        }

        if (! $hasExactIdWhere) {
            throw new ImmutableRecordException('Query scope must be strictly constrained to the authorized opening trade item ID.');
        }

        // Verify fields and values strictly match the model's authorized dirty state
        $modelDirty = $this->boundModel->getDirty();
        $allowedFields = ['status', 'reversal_posting_batch_id', 'reversed_at', 'reversed_by', 'correction_reason', 'posting_batch_id', 'updated_at'];

        foreach ($values as $key => $val) {
            if ($key === 'updated_at' || $key === $this->boundModel->getUpdatedAtColumn()) {
                continue;
            }
            if (! in_array($key, $allowedFields, true)) {
                throw new ImmutableRecordException("Field [{$key}] is immutable on posted opening trade items.");
            }
            if (! array_key_exists($key, $modelDirty)) {
                throw new ImmutableRecordException("Field [{$key}] was not marked dirty on the authorized model.");
            }
            $expectedVal = $this->boundModel->getAttribute($key);
            if ((string) $val !== (string) $expectedVal) {
                throw new ImmutableRecordException("Value for field [{$key}] does not match the authorized model attribute.");
            }
        }

        // Consume token immediately
        $eventScope->consumeModelUpdateToken($this->boundToken);
        $this->boundModel = null;
        $this->boundToken = null;

        return parent::update($values);
    }

    /**
     * Direct bulk inserts bypass canonical posting action and are strictly prohibited.
     *
     * @param  array<mixed>  $values
     */
    public function insert(array $values): bool
    {
        throw new ImmutableRecordException('Direct bulk inserts on opening trade items are prohibited. Use canonical actions.');
    }

    /**
     * @param  array<mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        throw new ImmutableRecordException('Direct bulk inserts on opening trade items are prohibited. Use canonical actions.');
    }

    /**
     * @param  array<mixed>  $values
     * @param  string|null  $sequence
     */
    public function insertGetId(array $values, $sequence = null): int
    {
        $eventScope = app(OpeningTradeEventScope::class);
        if ($this->boundInsertModel === null || $this->boundInsertToken === null || ! $eventScope->hasActiveCapability()) {
            throw new ImmutableRecordException('Direct bulk inserts on opening trade items are prohibited. Use canonical actions.');
        }

        $eventScope->verifyModelInsertToken($this->boundInsertModel, $this->boundInsertToken);

        $eventScope->consumeModelInsertToken($this->boundInsertToken);
        $this->boundInsertModel = null;
        $this->boundInsertToken = null;

        return (int) $this->toBase()->insertGetId($values, $sequence);
    }

    /**
     * @param  array<mixed>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): int
    {
        throw new ImmutableRecordException('Direct bulk inserts on opening trade items are prohibited. Use canonical actions.');
    }

    /**
     * @param  array<mixed>  $values
     * @param  array<string>|string  $uniqueBy
     * @param  array<string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw new ImmutableRecordException('Direct bulk upserts on opening trade items are prohibited. Use canonical actions.');
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        throw new ImmutableRecordException('Direct increments on opening trade items are prohibited. Use canonical actions.');
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        throw new ImmutableRecordException('Direct decrements on opening trade items are prohibited. Use canonical actions.');
    }

    /**
     * Direct updates on opening trade items are prohibited outside canonical actions.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateFrom(array $values): int
    {
        throw new ImmutableRecordException('Direct bulk updates on opening trade items are prohibited. Use canonical actions.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  \Closure|array<string, mixed>  $values
     */
    public function updateOrCreate(array $attributes, \Closure|array $values = []): mixed
    {
        throw new ImmutableRecordException('Direct updates on opening trade items are prohibited. Use canonical actions.');
    }

    /**
     * Bulk deletes bypass canonical action authority and are strictly prohibited.
     */
    public function delete(): mixed
    {
        throw new ImmutableRecordException('Opening trade items cannot be deleted.');
    }

    /**
     * Force deletes are strictly prohibited.
     */
    public function forceDelete(): mixed
    {
        throw new ImmutableRecordException('Opening trade items cannot be deleted.');
    }

    /**
     * Truncating opening trade items is strictly prohibited.
     */
    public function truncate(): void
    {
        throw new ImmutableRecordException('Opening trade items cannot be truncated.');
    }
}
