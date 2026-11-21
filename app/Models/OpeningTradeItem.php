<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Accounting\OpeningTradeEventScope;
use App\Support\Tenancy\BelongsToCompany;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string $side
 * @property int|null $customer_id
 * @property int|null $vendor_id
 * @property Carbon $opening_date
 * @property string|null $original_reference
 * @property Carbon|null $due_date
 * @property string $currency_code
 * @property string $amount_currency
 * @property string $exchange_rate
 * @property string $carrying_value_base
 * @property array<string, mixed> $party_snapshot
 * @property array<string, mixed> $company_snapshot
 * @property array<string, mixed> $control_route
 * @property int $created_by
 * @property string $source_identity
 * @property string $idempotency_key
 * @property string $idempotency_payload_hash
 * @property int|null $posting_batch_id
 * @property string $status
 * @property int|null $reversal_posting_batch_id
 * @property Carbon|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $correction_reason
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OpeningTradeItem extends Model
{
    use BelongsToCompany;

    public const string SIDE_CUSTOMER = 'customer';

    public const string SIDE_VENDOR = 'vendor';

    public const string STATUS_POSTED = 'posted';

    public const string STATUS_REVERSED = 'reversed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'public_id',
        'side',
        'customer_id',
        'vendor_id',
        'opening_date',
        'original_reference',
        'due_date',
        'currency_code',
        'amount_currency',
        'exchange_rate',
        'carrying_value_base',
        'party_snapshot',
        'company_snapshot',
        'control_route',
        'created_by',
        'source_identity',
        'idempotency_key',
        'idempotency_payload_hash',
        'posting_batch_id',
        'status',
        'reversal_posting_batch_id',
        'reversed_at',
        'reversed_by',
        'correction_reason',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'amount_currency' => 'string',
            'exchange_rate' => 'string',
            'carrying_value_base' => 'string',
            'party_snapshot' => 'array',
            'company_snapshot' => 'array',
            'control_route' => 'array',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return OpeningTradeItemBuilder<static>
     */
    public function newEloquentBuilder($query): OpeningTradeItemBuilder
    {
        /** @var OpeningTradeItemBuilder<static> */
        return new OpeningTradeItemBuilder($query);
    }

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $item->assertCanonicalPersistenceAuthority();
        });

        static::updating(function (self $item): void {
            $item->assertCanonicalPersistenceAuthority();
        });

        static::deleting(function (): void {
            throw new ImmutableRecordException('Opening trade items cannot be deleted.');
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return bool
     */
    protected function performUpdate(Builder $query)
    {
        $this->assertCanonicalPersistenceAuthority();

        if ($query instanceof OpeningTradeItemBuilder) {
            $query->allowModelSave($this);
        }

        return parent::performUpdate($query);
    }

    public function save(array $options = [])
    {
        $this->assertCanonicalPersistenceAuthority();

        return parent::save($options);
    }

    public function delete()
    {
        throw new ImmutableRecordException('Opening trade items cannot be deleted.');
    }

    public function assertCanonicalPersistenceAuthority(): void
    {
        if (! $this->exists) {
            if ($this->side === self::SIDE_CUSTOMER) {
                if ($this->customer_id === null || $this->vendor_id !== null) {
                    throw new InvalidArgumentException('Customer opening trade item must have customer_id and null vendor_id.');
                }
            } elseif ($this->side === self::SIDE_VENDOR) {
                if ($this->vendor_id === null || $this->customer_id !== null) {
                    throw new InvalidArgumentException('Vendor opening trade item must have vendor_id and null customer_id.');
                }
            } else {
                throw new InvalidArgumentException("Invalid side [{$this->side}]. Must be 'customer' or 'vendor'.");
            }

            if ($this->status !== self::STATUS_POSTED) {
                if ($this->status === self::STATUS_REVERSED) {
                    throw new ImmutableRecordException('Direct creation of reversed opening trade item is prohibited.');
                }
                throw new ImmutableRecordException('Creation of opening trade item requires canonical action capability with explicit posted status.');
            }

            if ($this->posting_batch_id !== null) {
                throw new ImmutableRecordException('Opening trade item posting batch must be attached via canonical posting capability after creation.');
            }

            if ($this->reversal_posting_batch_id !== null || $this->reversed_at !== null || $this->reversed_by !== null || $this->correction_reason !== null) {
                throw new ImmutableRecordException('Reversal attributes cannot be populated on creation.');
            }

            app(OpeningTradeEventScope::class)->assertCanCreateItem($this);

            return;
        }

        if ($this->getOriginal('status') === self::STATUS_REVERSED) {
            throw new ImmutableRecordException('Reversed opening trade items are immutable.');
        }

        if ($this->getOriginal('status') !== self::STATUS_POSTED) {
            throw new ImmutableRecordException('Unsupported opening trade item status transition.');
        }

        $reversalMetadataDirty = $this->isDirty('reversal_posting_batch_id')
            || $this->isDirty('reversed_at')
            || $this->isDirty('reversed_by')
            || $this->isDirty('correction_reason');

        if ($this->isDirty('status')) {
            if ($this->status !== self::STATUS_REVERSED) {
                throw new ImmutableRecordException('Posted opening trade items can only transition to reversed.');
            }

            app(OpeningTradeEventScope::class)->assertCanReverseItem($this);

            if (empty($this->reversed_at) || empty($this->reversed_by) || empty($this->correction_reason) || empty($this->reversal_posting_batch_id)) {
                throw new ImmutableRecordException('Direct status transition to reversed is prohibited without canonical reversal metadata.');
            }

            $revBatch = PostingBatch::withoutGlobalScopes()->find($this->reversal_posting_batch_id);
            if ($revBatch === null || (int) $revBatch->reversal_of_id !== (int) $this->posting_batch_id || (int) $revBatch->company_id !== (int) $this->company_id || $revBatch->status !== 'posted') {
                throw new ImmutableRecordException('Direct status transition to reversed requires a valid canonical accounting reversal batch.');
            }
        } elseif ($reversalMetadataDirty) {
            throw new ImmutableRecordException('Reversal metadata can only be populated during canonical transition to reversed status.');
        }

        if ($this->isDirty('posting_batch_id')) {
            if ($this->getOriginal('posting_batch_id') !== null) {
                throw new ImmutableRecordException('Posting batch cannot be reassigned on a posted opening trade item.');
            }
            app(OpeningTradeEventScope::class)->assertCanLinkPostingBatch($this, (int) $this->posting_batch_id);
        }

        $dirty = array_keys($this->getDirty());
        $allowed = ['status', 'reversal_posting_batch_id', 'reversed_at', 'reversed_by', 'correction_reason', 'updated_at'];
        if ($this->getOriginal('posting_batch_id') === null) {
            $allowed[] = 'posting_batch_id';
        }
        $disallowed = array_diff($dirty, $allowed);
        if (! empty($disallowed)) {
            throw new ImmutableRecordException('Opening trade items are immutable once posted.');
        }
    }

    public function setAttribute($key, $value)
    {
        if ($this->exists && $this->getOriginal('status') === self::STATUS_REVERSED) {
            throw new ImmutableRecordException('Reversed opening trade items are immutable.');
        }

        if ($this->exists && $this->getOriginal('status') !== self::STATUS_POSTED) {
            throw new ImmutableRecordException('Unsupported opening trade item status.');
        }

        if ($this->exists && $this->isPosted()) {
            if ($this->getOriginal('posting_batch_id') !== null && $key === 'posting_batch_id') {
                throw new ImmutableRecordException('Posting batch cannot be reassigned on a posted opening trade item.');
            }
            if (! in_array($key, ['status', 'reversal_posting_batch_id', 'reversed_at', 'reversed_by', 'correction_reason', 'posting_batch_id', 'updated_at'], true)) {
                throw new ImmutableRecordException('Opening trade items are immutable once posted.');
            }
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<PostingBatch, $this>
     */
    public function postingBatch(): BelongsTo
    {
        return $this->belongsTo(PostingBatch::class, 'posting_batch_id');
    }

    /**
     * @return BelongsTo<PostingBatch, $this>
     */
    public function reversalPostingBatch(): BelongsTo
    {
        return $this->belongsTo(PostingBatch::class, 'reversal_posting_batch_id');
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
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * @return HasMany<CustomerPaymentAllocation, $this>
     */
    public function customerPaymentAllocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    /**
     * @return HasMany<VendorPaymentAllocation, $this>
     */
    public function vendorPaymentAllocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class);
    }

    /**
     * @param  Builder<OpeningTradeItem>  $query
     * @return Builder<OpeningTradeItem>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    public function hasCanonicalReversal(): bool
    {
        if (! $this->isReversed() || $this->reversal_posting_batch_id === null || $this->posting_batch_id === null) {
            return false;
        }

        $revBatch = $this->reversalPostingBatch;
        if ($revBatch === null) {
            return false;
        }

        if ($revBatch->status !== 'posted'
            || (int) $revBatch->company_id !== (int) $this->company_id
            || (int) $revBatch->reversal_of_id !== (int) $this->posting_batch_id) {
            return false;
        }

        $origBatch = $this->postingBatch;
        if ($origBatch === null) {
            return false;
        }

        return in_array($origBatch->status, ['posted', 'reversed'], true)
            && $origBatch->source_type === 'opening_trade_item'
            && (int) $origBatch->source_id === (int) $this->id
            && (int) $origBatch->company_id === (int) $this->company_id
            && (int) $origBatch->reversed_by_batch_id === (int) $revBatch->id;
    }

    public function isCustomerSide(): bool
    {
        return $this->side === self::SIDE_CUSTOMER;
    }

    public function isVendorSide(): bool
    {
        return $this->side === self::SIDE_VENDOR;
    }

    public function getAmountAttribute(): string
    {
        return (string) $this->amount_currency;
    }

    public function getBaseCarryingValueAttribute(): string
    {
        return (string) $this->carrying_value_base;
    }

    public function getReferenceNumberAttribute(): ?string
    {
        return $this->original_reference;
    }

    public function getPartyTypeAttribute(): string
    {
        return $this->side;
    }

    public function getPartyIdAttribute(): ?int
    {
        return $this->side === self::SIDE_CUSTOMER ? $this->customer_id : $this->vendor_id;
    }

    public function getReversalReasonAttribute(): ?string
    {
        return $this->correction_reason;
    }

    public function getPartyNameSnapshotAttribute(): ?string
    {
        return $this->party_snapshot['name_ar'] ?? $this->party_snapshot['name_en'] ?? $this->party_snapshot['name'] ?? null;
    }

    public function getCompanyNameSnapshotAttribute(): ?string
    {
        return $this->company_snapshot['name_ar'] ?? $this->company_snapshot['name_en'] ?? $this->company_snapshot['name'] ?? null;
    }

    /**
     * Calculate outstanding balance in document currency.
     */
    public function calculateOutstanding(?string $asOfDate = null): BigDecimal
    {
        if ($asOfDate !== null && $this->opening_date->toDateString() > $asOfDate) {
            return BigDecimal::zero();
        }
        if ($this->hasCanonicalReversal()) {
            $revBatch = $this->reversalPostingBatch;
            $revDate = $revBatch?->posting_date?->toDateString()
                ?? ($this->reversed_at ? Carbon::parse($this->reversed_at)->toDateString() : Carbon::parse($this->opening_date)->toDateString());
            if ($asOfDate === null || $revDate <= $asOfDate) {
                return BigDecimal::zero();
            }
        }

        $prefix = $this->isCustomerSide() ? 'customer' : 'vendor';
        $rows = DB::table($prefix.'_payment_allocations as a')
            ->join($prefix.'_payments as p', 'p.id', '=', 'a.'.$prefix.'_payment_id')
            ->join('posting_batches as pb', 'pb.id', '=', 'p.posting_batch_id')
            ->leftJoin('posting_batches as pr', 'pr.id', '=', 'p.reversal_posting_batch_id')
            ->leftJoin($prefix.'_payment_application_events as e', 'e.id', '=', 'a.application_event_id')
            ->leftJoin('posting_batches as eb', 'eb.id', '=', 'e.posting_batch_id')
            ->leftJoin('posting_batches as er', 'er.id', '=', 'e.reversal_posting_batch_id')
            ->where('a.company_id', $this->company_id)
            ->where('p.company_id', $this->company_id)
            ->where('a.opening_trade_item_id', $this->id);
        if ($asOfDate === null) {
            $rows->where('p.is_reversed', false)
                ->where(fn ($q) => $q->whereNull('a.application_event_id')
                    ->orWhere(fn ($e) => $e->whereNotNull('e.applied_at')->whereNull('e.reversed_at')->whereNull('e.reversal_posting_batch_id')));
        } else {
            $rows->where('pb.posting_date', '<=', $asOfDate)
                ->where(fn ($q) => $q->whereNull('pr.id')->orWhere('pr.posting_date', '>', $asOfDate))
                ->where(fn ($q) => $q->whereNull('a.application_event_id')->orWhere(fn ($e) => $e
                    ->whereNotNull('e.applied_at')
                    ->whereRaw('COALESCE(eb.posting_date, e.application_date) <= ?', [$asOfDate])
                    ->where(fn ($r) => $r
                        ->where(fn ($notRev) => $notRev->whereNull('er.id')->whereNull('e.reversed_at'))
                        ->orWhereRaw('COALESCE(er.posting_date, pr.posting_date) > ?', [$asOfDate]))));
        }
        $allocated = BigDecimal::zero();
        foreach ($rows->get(['a.allocated_amount']) as $row) {
            $allocated = $allocated->plus($row->allocated_amount);
        }
        $remaining = BigDecimal::of($this->amount_currency)->minus($allocated);

        return $remaining->isPositive() ? $remaining : BigDecimal::zero();
    }
}
