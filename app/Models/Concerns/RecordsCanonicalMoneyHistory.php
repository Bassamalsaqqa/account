<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Money\MoneyEventCapability;
use App\Services\Money\MoneyEventScope;

/** Only a live canonical action's one-use prepared values can persist new Money history. */
trait RecordsCanonicalMoneyHistory
{
    private ?MoneyEventCapability $writeCapability = null;

    protected static function bootRecordsCanonicalMoneyHistory(): void
    {
        static::saving(function (self $model): void {
            app(MoneyEventScope::class)->assert($model->writeCapability, (int) $model->company_id);
        });
        static::deleting(fn () => throw new ImmutableRecordException('Money history cannot be deleted.'));
    }

    /** @param array<string, mixed> $values */
    public static function record(MoneyEventCapability $capability, array $values): static
    {
        app(MoneyEventScope::class)->consumeRecord($capability, static::class.':new', $values);
        $model = new static;
        $model->writeCapability = $capability;
        try {
            $model->forceFill($values)->save();
        } finally {
            $model->writeCapability = null;
        }

        return $model;
    }

    /** @param array<string, mixed> $values */
    public function complete(MoneyEventCapability $capability, array $values): void
    {
        app(MoneyEventScope::class)->consumeRecord($capability, static::class.':'.$this->id, $values);
        if (array_diff(array_keys($values), $this->completionFields()) !== []) {
            throw new ImmutableRecordException('Posted instrument/event facts are immutable.');
        }
        $this->writeCapability = $capability;
        try {
            $this->forceFill($values)->save();
        } finally {
            $this->writeCapability = null;
        }
    }
}
