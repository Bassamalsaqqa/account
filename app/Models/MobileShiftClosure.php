<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property int $cash_account_id
 * @property int $actor_id
 * @property string|null $device_id
 * @property Carbon $shift_date
 * @property string $currency_code
 * @property int $currency_minor_units
 * @property string|null $opening_cash
 * @property string $expected_cash
 * @property string $counted_cash
 * @property string $variance
 * @property string $variance_status
 * @property string $idempotency_key
 * @property string $request_hash
 * @property string|null $notes
 * @property array<string, mixed> $actor_snapshot
 * @property Carbon $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MobileShiftClosure extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'cash_account_id',
        'actor_id',
        'device_id',
        'shift_date',
        'currency_code',
        'currency_minor_units',
        'opening_cash',
        'expected_cash',
        'counted_cash',
        'variance',
        'variance_status',
        'idempotency_key',
        'request_hash',
        'notes',
        'actor_snapshot',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shift_date' => 'date:Y-m-d',
            'actor_snapshot' => 'array',
            'closed_at' => 'datetime',
            'opening_cash' => 'string',
            'expected_cash' => 'string',
            'counted_cash' => 'string',
            'variance' => 'string',
            'currency_minor_units' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });

        self::updating(function (): void {
            throw new ImmutableRecordException('Mobile shift closure records are immutable and cannot be updated once persisted.');
        });

        self::deleting(function (): void {
            throw new ImmutableRecordException('Mobile shift closure records are immutable and cannot be deleted.');
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
     * @return BelongsTo<MoneyAccount, $this>
     */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'cash_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
