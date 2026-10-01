<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property string $idempotency_key
 * @property string $operation_type
 * @property int $line_count
 * @property string|null $request_hash
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class InventoryOperation extends Model
{
    use BelongsToCompany;

    public const string TYPE_MOVEMENT = 'movement';

    public const string TYPE_TRANSFER = 'transfer';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'idempotency_key',
        'operation_type',
        'line_count',
        'request_hash',
        'created_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'line_count' => 'integer',
        'created_by' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new ImmutableRecordException('Inventory operations are immutable and cannot be updated once persisted.');
        });

        static::deleting(function (): void {
            throw new ImmutableRecordException('Inventory operations are immutable and cannot be deleted.');
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'inventory_operation_id');
    }
}
