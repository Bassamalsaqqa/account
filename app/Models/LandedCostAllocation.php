<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Phase7\Phase7EventScope;
use App\Services\Purchasing\PurchasePostingScope;
use App\Services\Purchasing\PurchaseReceiptCapability;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property int $expense_id
 * @property int $purchase_id
 * @property int $purchase_line_id
 * @property string $allocation_method
 * @property string $allocated_base
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $locked_at
 * @property Carbon|null $cancelled_at
 * @property-read Expense $expense
 * @property-read Purchase $purchase
 * @property-read PurchaseLine $purchaseLine
 */
class LandedCostAllocation extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    public const string METHOD_VALUE = 'value';

    public const string METHOD_QUANTITY = 'quantity';

    public const string METHOD_MANUAL = 'manual';

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_LOCKED = 'locked';

    public const string STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'public_id',
        'company_id',
        'expense_id',
        'purchase_id',
        'purchase_line_id',
        'allocation_method',
        'allocated_base',
        'status',
        'created_at',
        'locked_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'allocated_base' => 'string',
            'created_at' => 'datetime',
            'locked_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function delete()
    {
        if ($this->getRawOriginal('status') === self::STATUS_LOCKED) {
            throw new ImmutableRecordException('Capitalized landed allocation cannot be deleted.');
        }

        return parent::delete();
    }

    private bool $completingCanonical = false;

    public function completeCanonicalAllocation(PurchaseReceiptCapability $capability): void
    {
        app(PurchasePostingScope::class)->assertLandedAllocation($this, $capability);
        $this->completingCanonical = true;
        try {
            $this->status = self::STATUS_LOCKED;
            $this->locked_at = now();
            $this->save();
        } finally {
            $this->completingCanonical = false;
        }
    }

    /** @param array<string,mixed> $options */
    public function save(array $options = []): bool
    {
        if ($this->exists && $this->getRawOriginal('status') !== self::STATUS_DRAFT) {
            throw new ImmutableRecordException('Completed landed allocation is immutable.');
        }
        if ($this->status === self::STATUS_CANCELLED) {
            app(Phase7EventScope::class)->consumeRecord($this);
        } elseif ($this->status !== self::STATUS_DRAFT && ! $this->completingCanonical) {
            throw new ImmutableRecordException('Allocation locking requires canonical Purchase completion.');
        }

        return parent::save($options);
    }

    protected static function booted(): void
    {
        static::creating(function (LandedCostAllocation $allocation): void {
            if (empty($allocation->public_id)) {
                $allocation->public_id = (string) Str::ulid();
            }
            if (empty($allocation->created_at)) {
                $allocation->created_at = now();
            }
        });

        static::updating(function (LandedCostAllocation $allocation): void {
            // Once locked, cannot change amounts or re-open
            if ($allocation->getOriginal('status') === self::STATUS_LOCKED) {
                throw new ImmutableRecordException('Cannot modify locked landed cost allocation history.');
            }
        });

        static::deleting(function (LandedCostAllocation $allocation): void {
            if ($allocation->status === self::STATUS_LOCKED) {
                throw new ImmutableRecordException('Locked landed cost allocations cannot be deleted.');
            }
        });
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_id');
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    /** @return BelongsTo<PurchaseLine, $this> */
    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class, 'purchase_line_id');
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }
}
