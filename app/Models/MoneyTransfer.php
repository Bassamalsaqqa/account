<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Money\MoneyEventCapability;
use App\Services\Money\MoneyEventScope;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property string $public_id
 * @property string $transfer_number
 * @property Carbon $transfer_date
 * @property int $from_money_account_id
 * @property int $to_money_account_id
 * @property int $from_ledger_account_id
 * @property int $to_ledger_account_id
 * @property string $from_currency_code
 * @property string $to_currency_code
 * @property string $base_currency_code
 * @property string $from_amount
 * @property string $to_amount
 * @property string $from_exchange_rate
 * @property string $to_exchange_rate
 * @property string $base_value_from
 * @property string $base_value_to
 * @property string $fx_gain_loss_base
 * @property int|null $posting_batch_id
 * @property Carbon|null $posted_at
 * @property int|null $posted_by
 * @property int $created_by
 * @property bool $is_reversed
 * @property Carbon|null $reversal_date
 * @property Carbon|null $reversed_at
 * @property int|null $reversed_by
 * @property int|null $reversal_posting_batch_id
 * @property string $idempotency_key
 * @property string $request_hash
 */
class MoneyTransfer extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $guarded = ['*'];

    private ?MoneyEventCapability $writeCapability = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'transfer_date' => 'date:Y-m-d', 'reversal_date' => 'date:Y-m-d',
            'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'is_reversed' => 'boolean',
            'from_account_snapshot' => 'array', 'to_account_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $transfer): void {
            app(MoneyEventScope::class)->assert($transfer->writeCapability, (int) $transfer->company_id);
        });
        static::deleting(fn () => throw new ImmutableRecordException('Transfers cannot be deleted.'));
    }

    /** @param array<string, mixed> $values */
    public static function record(MoneyEventCapability $capability, array $values): self
    {
        app(MoneyEventScope::class)->consumeRecord($capability, self::class.':new', $values);
        $transfer = new self;
        $transfer->writeCapability = $capability;
        try {
            $transfer->forceFill($values)->save();
        } finally {
            $transfer->writeCapability = null;
        }

        return $transfer;
    }

    /** @param array<string, mixed> $values */
    public function complete(MoneyEventCapability $capability, array $values): void
    {
        app(MoneyEventScope::class)->consumeRecord($capability, self::class.':'.$this->id, $values);
        if (array_diff(array_keys($values), ['company_id', 'posting_batch_id', 'posted_at', 'posted_by', 'is_reversed', 'reversal_date', 'reversed_at', 'reversed_by', 'reversal_posting_batch_id', 'reversal_reason']) !== []) {
            throw new ImmutableRecordException('Transfer economic facts are immutable.');
        }
        $this->writeCapability = $capability;
        try {
            $this->forceFill($values)->save();
        } finally {
            $this->writeCapability = null;
        }
    }

    /** @return BelongsTo<MoneyAccount, $this> */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'from_money_account_id')->withTrashed();
    }

    /** @return BelongsTo<MoneyAccount, $this> */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'to_money_account_id')->withTrashed();
    }

    /** @return BelongsTo<PostingBatch, $this> */
    public function postingBatch(): BelongsTo
    {
        return $this->belongsTo(PostingBatch::class);
    }
}
