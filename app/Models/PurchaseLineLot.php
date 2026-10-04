<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Inventory\Services\UnitConversionService;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\BelongsToCompany;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $company_id
 * @property int $purchase_line_id
 * @property string $quantity
 * @property string $quantity_base
 * @property Carbon|null $expiry_date
 */
class PurchaseLineLot extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'public_id', 'company_id', 'purchase_line_id', 'lot_number', 'expiry_date',
        'quantity', 'quantity_base', 'created_inventory_lot_id', 'stock_movement_id',
    ];

    protected function casts(): array
    {
        return ['expiry_date' => 'date:Y-m-d'];
    }

    public function setExpiryDateAttribute(?string $value): void
    {
        if ($value !== null) {
            app(PurchaseDocumentRules::class)->date($value);
        }
        $this->attributes['expiry_date'] = $value;
    }

    private bool $completingReceipt = false;

    public function completeCanonicalReceipt(StockMovement $movement, User $actor): void
    {
        DB::transaction(function () use ($movement, $actor): void {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.purchase.post');
            app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.cost.view');
            $line = $this->mutableLine();
            $record = StockMovement::where('company_id', $this->company_id)->findOrFail($movement->id);
            $lot = InventoryLot::where('company_id', $this->company_id)->findOrFail($record->lot_id);
            if (! $this->exists || $this->isDirty() || $this->stock_movement_id !== null || $this->created_inventory_lot_id !== null
                || $record->movement_type !== StockMovement::TYPE_PURCHASE || $record->source_type !== 'purchase'
                || (int) $record->source_id !== (int) $line->purchase_id || (int) $record->source_line_id !== (int) $line->id
                || (int) $record->product_id !== (int) $line->product_id || (int) $record->warehouse_id !== (int) $line->purchase->warehouse_id
                || ! BigDecimal::of($record->source_quantity)->isEqualTo($this->quantity)
                || ! BigDecimal::of($record->quantity_delta_base)->isEqualTo($this->quantity_base)
                || $lot->source_type !== 'purchase' || (int) $lot->source_id !== (int) $line->purchase_id
                || (int) $lot->source_line_id !== (int) $line->id || $lot->lot_number !== $this->lot_number
                || $lot->expiry_date?->format('Y-m-d') !== $this->expiry_date?->format('Y-m-d')) {
                throw new ImmutableRecordException('Canonical lot receipt provenance is required.');
            }
            $this->completingReceipt = true;
            try {
                $this->created_inventory_lot_id = $lot->id;
                $this->stock_movement_id = $record->id;
                $this->save();
            } finally {
                $this->completingReceipt = false;
            }
        });
    }

    protected static function booted(): void
    {
        static::creating(function (self $lot): void {
            $lot->public_id ??= (string) Str::ulid();
        });
        static::saving(function (self $lot): void {
            $line = $lot->mutableLine();
            if ($lot->completingReceipt) {
                if (array_diff(array_keys($lot->getDirty()), ['created_inventory_lot_id', 'stock_movement_id', 'updated_at']) !== []) {
                    throw new ImmutableRecordException('Only received lot provenance may change during completion.');
                }

                return;
            }
            if ($lot->exists && $lot->isDirty(['purchase_line_id', 'company_id', 'public_id'])) {
                throw new ImmutableRecordException('Receiving intent provenance is immutable.');
            }
            if ($lot->created_inventory_lot_id !== null || $lot->stock_movement_id !== null) {
                throw new ImmutableRecordException('Receiving intent is not received inventory.');
            }
            if (! $line->product->track_expiry) {
                throw new \InvalidArgumentException('Non-expiry products cannot carry lot intent.');
            }
            $rules = app(PurchaseDocumentRules::class);
            $rules->selectedUnit($line->product, $line->product_unit_id, $lot->quantity);
            $quantity = Quantity::of($lot->getAttributes()['quantity']);
            $base = app(UnitConversionService::class)->toBase($quantity, $line->productUnit);
            if (! $base->isEqualTo(Quantity::of($lot->getAttributes()['quantity_base']))) {
                throw new \InvalidArgumentException('Receiving intent base quantity must match its selected unit.');
            }
            $allocated = BigDecimal::of((string) self::where('company_id', $lot->company_id)
                ->where('purchase_line_id', $line->id)->when($lot->exists, fn ($q) => $q->where('id', '!=', $lot->id))->sum('quantity'));
            if ($allocated->plus($quantity->toBigDecimal())->isGreaterThan($line->quantity)) {
                throw new \InvalidArgumentException(__('purchasing.lot_quantity_exceeds_line'));
            }
            if ($lot->expiry_date !== null) {
                $rules->date($lot->getAttributes()['expiry_date']);
            }
        });
        static::deleting(fn (self $lot) => $lot->mutableLine());
    }

    private function mutableLine(): PurchaseLine
    {
        $line = PurchaseLine::where('company_id', $this->company_id)->findOrFail($this->exists ? $this->getRawOriginal('purchase_line_id') : $this->purchase_line_id);
        $line->assertMutableDraft();

        return $line;
    }

    /** @return BelongsTo<PurchaseLine, $this> */
    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class);
    }
}
