<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceLotAllocation extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'sales_invoice_id',
        'sales_invoice_line_id',
        'inventory_lot_id',
        'lot_number',
        'expiry_date',
        'quantity_allocated_base',
        'unit_cost_base',
        'total_cost_base',
        'stock_movement_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expiry_date' => 'date:Y-m-d',
            'quantity_allocated_base' => 'string',
            'unit_cost_base' => 'string',
            'total_cost_base' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $allocation): void {
            $parent = SalesInvoice::query()->find($allocation->sales_invoice_id);
            $line = SalesInvoiceLine::query()->find($allocation->sales_invoice_line_id);
            $movement = StockMovement::query()->find($allocation->stock_movement_id);
            if ($parent === null || ! $parent->isDraft() || $line === null || (int) $line->sales_invoice_id !== (int) $parent->id || $movement === null || (int) $movement->company_id !== (int) $allocation->company_id || (int) $parent->company_id !== (int) $allocation->company_id) {
                throw new \InvalidArgumentException('Allocation insertion requires a matching draft and immutable movement.');
            }
        });

        static::updating(function () {
            throw new \InvalidArgumentException('SalesInvoiceLotAllocation records are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new \InvalidArgumentException('SalesInvoiceLotAllocation records cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    /**
     * @return BelongsTo<SalesInvoiceLine, $this>
     */
    public function salesInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceLine::class);
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
