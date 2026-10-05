<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Services\Purchasing\PurchaseReturnIssueCapability;
use App\Services\Purchasing\PurchaseReturnPostingCommandBuilder;
use App\Services\Purchasing\PurchaseReturnPostingScope;
use App\Services\Purchasing\PurchaseReturnStockProvenance;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\BelongsToCompany;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string|null $return_number
 * @property int $purchase_id
 * @property int $vendor_id
 * @property int $warehouse_id
 * @property string $currency_code
 * @property string $base_currency_code
 * @property string $exchange_rate
 * @property Carbon|string $return_date
 * @property string $status
 * @property string $subtotal_currency
 * @property string $discount_total_currency
 * @property string $tax_total_currency
 * @property string $grand_total_currency
 * @property string $subtotal_base
 * @property string $discount_total_base
 * @property string $tax_total_base
 * @property string $grand_total_base
 * @property string|null $reason
 * @property string|null $notes
 * @property string $document_locale
 * @property array<string, mixed>|null $vendor_snapshot
 * @property array<string, mixed>|null $company_snapshot
 * @property int|null $posting_batch_id
 * @property Carbon|null $posted_at
 * @property int|null $posted_by
 * @property int $created_by
 * @property int|null $updated_by
 */
class PurchaseReturn extends Model
{
    use BelongsToCompany;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_POSTED = 'posted';

    /** @var list<string> */
    public const RESERVED_FIELDS = ['return_number', 'posting_batch_id', 'posted_at', 'posted_by'];

    /** @var list<string> */
    protected $fillable = [
        'public_id', 'company_id', 'return_number', 'purchase_id', 'vendor_id',
        'warehouse_id', 'currency_code', 'base_currency_code', 'exchange_rate',
        'return_date', 'status', 'subtotal_currency', 'discount_total_currency',
        'tax_total_currency', 'grand_total_currency', 'subtotal_base', 'discount_total_base',
        'tax_total_base', 'grand_total_base', 'reason', 'notes', 'document_locale',
        'vendor_snapshot', 'company_snapshot', 'posting_batch_id', 'posted_at', 'posted_by',
        'created_by', 'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected function casts(): array
    {
        return [
            'return_date' => 'date:Y-m-d',
            'vendor_snapshot' => 'array',
            'company_snapshot' => 'array',
            'posted_at' => 'datetime',
            'exchange_rate' => 'string',
        ];
    }

    private bool $completingPost = false;

    public function completeCanonicalPost(?PostingBatch $batch, string $number, User $actor, ?PurchaseReturnIssueCapability $capability = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new ImmutableRecordException('Purchase return completion requires an existing outer posting transaction.');
        }

        if ($capability === null) {
            throw new ImmutableRecordException('Purchase return completion requires active canonical posting capability.');
        }

        $scope = app(PurchaseReturnPostingScope::class);
        try {
            $scope->assertScope((int) $this->company_id, (int) $this->id, $actor, $capability);
            $scope->assertAllocatedNumber($number);
        } catch (\Throwable $e) {
            throw new ImmutableRecordException($e->getMessage(), previous: $e);
        }

        DB::transaction(function () use ($batch, $number, $actor): void {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.return.manage');
            app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.cost.view');
            $this->assertMutableDraft();
            if ($this->isDirty() || trim($number) === '') {
                throw new ImmutableRecordException('Coherent canonical Purchase return posting is required.');
            }

            // Verify complete canonical lines and allocations provenance
            $lines = $this->lines()->withoutGlobalScopes()->get();
            if ($lines->isEmpty()) {
                throw new ImmutableRecordException('Purchase return must contain at least one line.');
            }

            $totalActual = BigDecimal::zero();
            foreach ($lines as $line) {
                if ($line->stock_movement_id === null
                    || $line->historical_receipt_value_base === null
                    || $line->inventory_value_removed_base === null
                    || $line->valuation_adjustment_base === null) {
                    throw new ImmutableRecordException('Purchase return line is missing canonical posting provenance.');
                }
                $allocations = $line->allocations()->withoutGlobalScopes()->get();
                if ($allocations->isEmpty()) {
                    throw new ImmutableRecordException('Purchase return line is missing allocations.');
                }
                foreach ($allocations as $alloc) {
                    if ($alloc->stock_movement_id === null
                        || $alloc->historical_value_base === null
                        || $alloc->inventory_value_removed_base === null) {
                        throw new ImmutableRecordException('Purchase return allocation is missing canonical posting provenance.');
                    }
                }
                $totalActual = $totalActual->plus($line->inventory_value_removed_base);
            }

            $builder = app(PurchaseReturnPostingCommandBuilder::class);
            $isZero = $builder->isZeroValue($this, $totalActual);

            $company = Company::findOrFail($this->company_id);
            $command = $builder->build($company, $this, $number, $actor, false);

            if ($isZero) {
                if ($batch !== null) {
                    throw new ImmutableRecordException('Zero-value purchase return cannot have an accounting posting batch.');
                }
            } else {
                if ($batch === null) {
                    throw new ImmutableRecordException('Nonzero purchase return requires a canonical posting batch.');
                }
                $persisted = PostingBatch::where('company_id', $this->company_id)->findOrFail($batch->id);
                if ((int) $persisted->posted_by !== (int) $actor->id
                    || $persisted->idempotency_key !== 'purchase_return_'.$this->id.'_posting'
                    || $persisted->status !== 'posted'
                    || $persisted->batch_number !== $number
                    || $command === null
                    || ! $command->matchesBatch($persisted)) {
                    throw new ImmutableRecordException('Coherent canonical Purchase return batch is required.');
                }
            }

            $this->completingPost = true;
            try {
                $this->status = self::STATUS_POSTED;
                $this->return_number = $number;
                $this->posting_batch_id = $batch?->id;
                $this->posted_at = now();
                $this->posted_by = $actor->id;
                $this->updated_by = $actor->id;
                $this->save();
            } finally {
                $this->completingPost = false;
            }

            PurchaseReturnStockProvenance::validatePostedReturnIntegrity($this);

        });
    }

    public function setReturnDateAttribute(\DateTimeInterface|string $value): void
    {
        $dateStr = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
        app(PurchaseDocumentRules::class)->date($dateStr);
        $this->attributes['return_date'] = $dateStr;
    }

    protected static function booted(): void
    {
        static::saving(function (self $return): void {
            if ($return->exists) {
                $return->assertMutableDraft();
                if ($return->isDirty(['company_id', 'public_id', 'created_by', 'purchase_id', 'vendor_id', 'warehouse_id', 'currency_code', 'base_currency_code', 'exchange_rate'])) {
                    throw new ImmutableRecordException('Purchase return ownership, purchase link, party, and currency provenance are immutable.');
                }
            }
            if (! $return->isDraft()) {
                if (! $return->completingPost || $return->status !== self::STATUS_POSTED
                    || array_diff(array_keys($return->getDirty()), ['status', 'return_number', 'posting_batch_id', 'posted_at', 'posted_by', 'updated_by', 'vendor_snapshot', 'company_snapshot', 'updated_at']) !== []) {
                    throw new ImmutableRecordException('Only canonical posting may finalize a persisted Purchase Return Draft.');
                }

                return;
            }
            foreach (self::RESERVED_FIELDS as $field) {
                if ($return->getAttribute($field) !== null) {
                    throw new ImmutableRecordException('Purchase return drafts cannot carry posting, batch, or numbering effects.');
                }
            }
            foreach (['subtotal_currency', 'discount_total_currency', 'tax_total_currency', 'grand_total_currency', 'subtotal_base', 'discount_total_base', 'tax_total_base', 'grand_total_base'] as $field) {
                $amount = MoneyAmount::from($return->getAttributes()[$field] ?? null);
                if ($amount->getAmount()->isNegative()) {
                    throw new \InvalidArgumentException('Purchase return totals cannot be negative.');
                }
                $return->setAttribute($field, (string) $amount);
            }
            $purchase = Purchase::where('company_id', $return->company_id)->findOrFail($return->purchase_id);
            if (! $purchase->isPosted() || $purchase->posting_batch_id === null) {
                throw new \InvalidArgumentException('Purchase return requires a posted purchase.');
            }
            if ($return->return_date->lt($purchase->purchase_date)) {
                throw new \InvalidArgumentException('Return date cannot be earlier than original purchase date.');
            }
        });
        static::creating(function (self $return): void {
            $return->public_id ??= (string) Str::ulid();
        });
        static::deleting(fn (self $return) => $return->assertMutableDraft());
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function assertMutableDraft(): void
    {
        if (! $this->exists || self::where('company_id', $this->getRawOriginal('company_id'))
            ->whereKey($this->id)->value('status') !== self::STATUS_DRAFT) {
            throw new ImmutableRecordException('Only persisted Purchase return drafts are mutable.');
        }
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    /** @return BelongsTo<PostingBatch, $this> */
    public function postingBatch(): BelongsTo
    {
        return $this->belongsTo(PostingBatch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<PurchaseReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class)->orderBy('line_number');
    }

    /** @return HasMany<PurchaseReturnAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PurchaseReturnAllocation::class)->orderBy('id');
    }
}
