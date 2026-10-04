<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon|null $expiry_date
 * @property Carbon $received_date
 */
class InventoryLot extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'product_id',
        'lot_number',
        'expiry_date',
        'received_date',
        'source_type',
        'source_id',
        'source_line_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expiry_date' => 'date:Y-m-d',
            'received_date' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $lot): void {
            if (empty($lot->public_id)) {
                $lot->public_id = (string) Str::ulid();
            }
        });

        static::saving(function (self $lot): void {
            if ($lot->product_id > 0) {
                $product = CompanyScope::executeWithoutScope(fn () => Product::find($lot->product_id));
                if ($product === null || (int) $product->company_id !== (int) $lot->company_id) {
                    throw new \InvalidArgumentException("InventoryLot product [{$lot->product_id}] does not belong to the same company.");
                }
            }
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
     * @return HasMany<InventoryLotBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(InventoryLotBalance::class, 'lot_id');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'lot_id');
    }

    public function isExpired(?CarbonInterface $referenceDate = null): bool
    {
        if ($this->expiry_date === null) {
            return false;
        }

        $ref = $referenceDate ?? Carbon::today();

        return Carbon::parse($this->expiry_date)->startOfDay()->isBefore($ref->startOfDay());
    }

    public function daysUntilExpiry(?CarbonInterface $referenceDate = null): ?int
    {
        if ($this->expiry_date === null) {
            return null;
        }

        $ref = $referenceDate ?? Carbon::today();
        $expiry = Carbon::parse($this->expiry_date)->startOfDay();

        return (int) $ref->startOfDay()->diffInDays($expiry, false);
    }
}
