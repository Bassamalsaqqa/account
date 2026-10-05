<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Support\Tenancy\BelongsToCompany;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** @property Carbon $movement_date */
class StockMovement extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const string TYPE_OPENING_BALANCE = 'opening_balance';

    public const string TYPE_TRANSFER_IN = 'transfer_in';

    public const string TYPE_TRANSFER_OUT = 'transfer_out';

    public const string TYPE_ADJUSTMENT_INCREASE = 'adjustment_increase';

    public const string TYPE_ADJUSTMENT_DECREASE = 'adjustment_decrease';

    public const string TYPE_DAMAGE = 'damage';

    public const string TYPE_LOSS = 'loss';

    public const string TYPE_DAMAGE_OR_LOSS = 'damage_or_loss';

    public const string TYPE_EXPIRY_DISPOSAL = 'expiry_disposal';

    public const string TYPE_SALE = 'sale';

    public const string TYPE_SALE_RETURN = 'sale_return';

    public const string TYPE_PURCHASE = 'purchase';

    public const string TYPE_PURCHASE_RETURN = 'purchase_return';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'product_id',
        'warehouse_id',
        'lot_id',
        'movement_type',
        'movement_date',
        'quantity_delta_base',
        'unit_cost_base',
        'value_delta_base',
        'average_cost_after',
        'quantity_after_product_company',
        'unit_id',
        'source_quantity',
        'conversion_to_base',
        'source_type',
        'source_id',
        'source_line_id',
        'reversal_of_id',
        'inventory_operation_id',
        'idempotency_key',
        'reason',
        'created_by',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_date' => 'date:Y-m-d',
            'quantity_delta_base' => 'string',
            'unit_cost_base' => 'string',
            'value_delta_base' => 'string',
            'average_cost_after' => 'string',
            'quantity_after_product_company' => 'string',
            'source_quantity' => 'string',
            'conversion_to_base' => 'string',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            if (empty($movement->public_id)) {
                $movement->public_id = (string) Str::ulid();
            }
        });

        static::updating(function (): void {
            throw new ImmutableRecordException('Stock movements are immutable and cannot be updated once persisted.');
        });

        static::deleting(function (): void {
            throw new ImmutableRecordException('Stock movements are immutable and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    /**
     * @return BelongsTo<InventoryOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class, 'inventory_operation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isInbound(): bool
    {
        return BigDecimal::of((string) $this->quantity_delta_base)->isPositive();
    }

    public function isOutbound(): bool
    {
        return BigDecimal::of((string) $this->quantity_delta_base)->isNegative();
    }
}
