<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property int $purchase_id
 * @property string $grn_number
 * @property int $warehouse_id
 * @property Carbon $receipt_date
 * @property string $status
 * @property int $inventory_operation_id
 * @property string|null $notes
 * @property string $idempotency_key
 * @property string $request_hash
 * @property array<string, mixed>|null $response_snapshot
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GoodsReceiptNote extends Model
{
    use BelongsToCompany;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_POSTED = 'posted';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'purchase_id',
        'grn_number',
        'warehouse_id',
        'receipt_date',
        'status',
        'inventory_operation_id',
        'notes',
        'idempotency_key',
        'request_hash',
        'response_snapshot',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'response_snapshot' => 'array',
        ];
    }

    /**
     * Immutability enforcement: Goods Receipt Notes are immutable financial/inventory receipts once posted.
     */
    public static function boot(): void
    {
        parent::boot();

        static::saving(function (GoodsReceiptNote $grn): void {
            if ($grn->status === self::STATUS_DRAFT && $grn->response_snapshot !== null) {
                throw new ImmutableRecordException('Draft Goods Receipt Notes cannot carry a response snapshot.');
            }
        });

        static::updating(function (GoodsReceiptNote $grn): void {
            if ($grn->getOriginal('status') === self::STATUS_POSTED) {
                throw new ImmutableRecordException('Goods Receipt Notes are strictly immutable once posted.');
            }

            if ($grn->isDirty([
                'company_id',
                'purchase_id',
                'grn_number',
                'warehouse_id',
                'idempotency_key',
                'request_hash',
            ])) {
                throw new ImmutableRecordException('Goods Receipt Notes core identity cannot be modified.');
            }
        });

        static::deleting(function (): void {
            throw new ImmutableRecordException('Goods Receipt Notes cannot be deleted.');
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
     * @return BelongsTo<Purchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * @return BelongsTo<InventoryOperation, $this>
     */
    public function inventoryOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<GoodsReceiptNoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptNoteLine::class);
    }
}
