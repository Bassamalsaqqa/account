<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property int $goods_receipt_note_id
 * @property int $purchase_line_id
 * @property int $product_id
 * @property int $product_unit_id
 * @property int|null $lot_id
 * @property string $quantity
 * @property string $quantity_base
 * @property int $transfer_out_movement_id
 * @property int $transfer_in_movement_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GoodsReceiptNoteLine extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'goods_receipt_note_id',
        'purchase_line_id',
        'product_id',
        'product_unit_id',
        'lot_id',
        'quantity',
        'quantity_base',
        'transfer_out_movement_id',
        'transfer_in_movement_id',
    ];

    /**
     * Immutability enforcement: GRN lines are immutable once created.
     */
    public static function boot(): void
    {
        parent::boot();

        static::updating(function (): void {
            throw new ImmutableRecordException('Goods Receipt Note lines are strictly immutable once created.');
        });

        static::deleting(function (): void {
            throw new ImmutableRecordException('Goods Receipt Note lines cannot be deleted.');
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
     * @return BelongsTo<GoodsReceiptNote, $this>
     */
    public function goodsReceiptNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class);
    }

    /**
     * @return BelongsTo<PurchaseLine, $this>
     */
    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductUnit, $this>
     */
    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function transferOutMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'transfer_out_movement_id');
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function transferInMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'transfer_in_movement_id');
    }
}
