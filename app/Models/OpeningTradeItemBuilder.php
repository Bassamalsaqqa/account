<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Accounting\OpeningTradeEventScope;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use DateTimeInterface;
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

        $tokenInfo = $eventScope->getUpdateTokenInfo($this->boundToken);
        if ($tokenInfo['item_id'] !== (int) $modelId) {
            throw new ImmutableRecordException('Model update token does not match target item.');
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

        // Verify fields and values strictly match the authorized approved values
        $approvedValues = $tokenInfo['approved_values'];
        $modelDirty = $this->boundModel->getDirty();

        foreach ($values as $key => $val) {
            if ($key === 'updated_at' || $key === $this->boundModel->getUpdatedAtColumn()) {
                continue;
            }
            if (! array_key_exists($key, $approvedValues)) {
                throw new ImmutableRecordException("Field [{$key}] is not an approved update for this canonical transition.");
            }
            if (! array_key_exists($key, $modelDirty)) {
                throw new ImmutableRecordException("Field [{$key}] was not marked dirty on the authorized model.");
            }
            $expectedVal = $approvedValues[$key];
            if ((string) $val !== (string) $expectedVal) {
                throw new ImmutableRecordException("Value for field [{$key}] does not match the authorized approved value.");
            }
            if ((string) $this->boundModel->getAttribute($key) !== (string) $expectedVal) {
                throw new ImmutableRecordException("Value for field [{$key}] on model does not match the authorized approved value.");
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

        // Verify inserted values match the bound model attributes
        $bound = $this->boundInsertModel;

        foreach ($bound->getAttributes() as $attr => $expectedVal) {
            if ($attr === 'id' || $attr === 'created_at' || $attr === 'updated_at') {
                continue;
            }
            if (! array_key_exists($attr, $values)) {
                throw new ImmutableRecordException("Inserted values are missing bound attribute [{$attr}].");
            }
        }

        foreach ($values as $col => $val) {
            if ($col === 'created_at' || $col === 'updated_at') {
                continue;
            }
            $expected = $bound->getAttribute($col);
            if ($expected === null && $val === null) {
                continue;
            }
            if (in_array($col, ['amount_currency', 'exchange_rate', 'carrying_value_base'], true)) {
                if ($val === null || ! BigDecimal::of((string) $val)->isEqualTo(BigDecimal::of((string) $expected))) {
                    throw new ImmutableRecordException("Inserted value for [{$col}] does not match the bound model.");
                }
            } elseif (in_array($col, ['party_snapshot', 'company_snapshot', 'control_route'], true)) {
                $decodedVal = is_string($val) ? json_decode($val, true) : $val;
                $decodedExp = is_string($expected) ? json_decode((string) $expected, true) : $expected;
                if ($decodedVal != $decodedExp) {
                    throw new ImmutableRecordException("Inserted value for [{$col}] does not match the bound model.");
                }
            } elseif (in_array($col, ['opening_date', 'due_date'], true) || ($bound->hasCast($col) && str_starts_with((string) ($bound->getCasts()[$col] ?? ''), 'date'))) {
                if ($expected === null || $val === null) {
                    throw new ImmutableRecordException("Inserted value for [{$col}] does not match the bound model.");
                }
                try {
                    $expectedDate = $expected instanceof DateTimeInterface
                        ? $expected->format('Y-m-d')
                        : Carbon::parse((string) $expected)->format('Y-m-d');
                    $valDate = $val instanceof DateTimeInterface
                        ? $val->format('Y-m-d')
                        : Carbon::parse((string) $val)->format('Y-m-d');
                } catch (\Throwable) {
                    throw new ImmutableRecordException("Inserted value for [{$col}] does not match the bound model.");
                }

                if ($valDate !== $expectedDate) {
                    throw new ImmutableRecordException("Inserted value for [{$col}] does not match the bound model.");
                }
            } else {
                if ((string) $val !== (string) $expected) {
                    throw new ImmutableRecordException("Inserted value for [{$col}] does not match the bound model.");
                }
            }
        }

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
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        throw new ImmutableRecordException('Direct increments on opening trade items are prohibited. Use canonical actions.');
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        throw new ImmutableRecordException('Direct decrements on opening trade items are prohibited. Use canonical actions.');
    }

    /**
     * @param  string|null  $column
     */
    public function touch($column = null): int
    {
        throw new ImmutableRecordException('Direct updates on opening trade items are prohibited. Use canonical actions.');
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
