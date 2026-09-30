<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string|null $batch_number
 * @property Carbon $posting_date
 * @property string $status
 * @property string $source_type
 * @property int $source_id
 * @property string $transaction_currency_code
 * @property string $base_currency_code
 * @property string $exchange_rate
 * @property string|null $description
 * @property string $idempotency_key
 * @property int|null $posted_by
 * @property Carbon|null $posted_at
 * @property int|null $reversal_of_id
 * @property int|null $reversed_by_batch_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PostingBatch extends Model
{
    use BelongsToCompany;

    public const string STATUS_POSTED = 'posted';

    public const string STATUS_REVERSED = 'reversed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'batch_number',
        'posting_date',
        'status',
        'source_type',
        'source_id',
        'transaction_currency_code',
        'base_currency_code',
        'exchange_rate',
        'description',
        'idempotency_key',
        'posted_by',
        'posted_at',
        'reversal_of_id',
        'reversed_by_batch_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'posting_date' => 'date',
            'posted_at' => 'datetime',
            'exchange_rate' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            if (empty($batch->public_id)) {
                $batch->public_id = (string) Str::ulid();
            }
        });

        static::updating(function (): void {
            throw ImmutableRecordException::cannotModify('batch');
        });

        static::deleting(function (): void {
            throw ImmutableRecordException::cannotDelete('batch');
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<PostingLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PostingLine::class, 'posting_batch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_batch_id');
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }
}
