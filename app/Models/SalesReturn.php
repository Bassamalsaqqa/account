<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string $return_number
 * @property int|null $sales_invoice_id
 * @property int $customer_id
 * @property int|null $warehouse_id
 * @property string $currency_code
 * @property string $exchange_rate
 * @property \Illuminate\Support\Carbon|Carbon|string $issue_date
 * @property string $status
 * @property string $subtotal_base
 * @property string $discount_total_base
 * @property string $tax_total_base
 * @property string $grand_total_base
 * @property string $cogs_total_base
 * @property string $subtotal_currency
 * @property string $discount_total_currency
 * @property string $tax_total_currency
 * @property string $grand_total_currency
 * @property string|null $reason
 * @property string|null $notes
 * @property string $document_locale
 * @property array<string, mixed>|null $customer_snapshot
 * @property array<string, mixed>|null $company_snapshot
 * @property int|null $posting_batch_id
 * @property \Illuminate\Support\Carbon|Carbon|null $posted_at
 * @property int|null $posted_by
 * @property \Illuminate\Support\Carbon|Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property int|null $void_posting_batch_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Company $company
 * @property-read Customer $customer
 * @property-read SalesInvoice|null $salesInvoice
 * @property-read Warehouse|null $warehouse
 * @property-read Collection<int, SalesReturnLine> $lines
 */
class SalesReturn extends Model
{
    use BelongsToCompany;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_POSTED = 'posted';

    public const string STATUS_VOID = 'void';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'return_number',
        'sales_invoice_id',
        'customer_id',
        'warehouse_id',
        'currency_code',
        'exchange_rate',
        'issue_date',
        'status',
        'subtotal_base',
        'discount_total_base',
        'tax_total_base',
        'grand_total_base',
        'cogs_total_base',
        'subtotal_currency',
        'discount_total_currency',
        'tax_total_currency',
        'grand_total_currency',
        'reason',
        'notes',
        'document_locale',
        'customer_snapshot',
        'company_snapshot',
        'posting_batch_id',
        'posted_at',
        'posted_by',
        'voided_at',
        'voided_by',
        'void_reason',
        'void_posting_batch_id',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'exchange_rate' => 'string',
            'subtotal_base' => 'string',
            'discount_total_base' => 'string',
            'tax_total_base' => 'string',
            'grand_total_base' => 'string',
            'cogs_total_base' => 'string',
            'subtotal_currency' => 'string',
            'discount_total_currency' => 'string',
            'tax_total_currency' => 'string',
            'grand_total_currency' => 'string',
            'customer_snapshot' => 'array',
            'company_snapshot' => 'array',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    private bool $completingPost = false;

    public function completeCanonicalPost(PostingBatch $batch, User $actor): void
    {
        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'sales.return.post');
        $persisted = PostingBatch::query()->findOrFail($batch->id);
        if ($this->getOriginal('status') !== self::STATUS_DRAFT || $persisted->source_type !== 'sales_return' || (int) $persisted->source_id !== (int) $this->id) {
            throw new ImmutableRecordException('Canonical document posting is required.');
        }
        $this->completingPost = true;
        try {
            $this->status = self::STATUS_POSTED;
            $this->posting_batch_id = $persisted->id;
            $this->posted_at = now();
            $this->posted_by = $actor->id;
            $this->save();
        } finally {
            $this->completingPost = false;
        }
    }

    private bool $completingVoid = false;

    public function completeCanonicalVoid(PostingBatch $reversal, User $actor, ?string $reason): void
    {
        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'sales.return.void');
        if ((int) $reversal->company_id !== (int) $this->company_id || (int) $reversal->reversal_of_id !== (int) $this->posting_batch_id) {
            throw new \InvalidArgumentException('A canonical reversal of this document is required.');
        }
        $this->completingVoid = true;
        try {
            $this->status = self::STATUS_VOID;
            $this->voided_at = now();
            $this->voided_by = $actor->id;
            $this->void_reason = $reason;
            $this->void_posting_batch_id = $reversal->id;
            $this->save();
        } finally {
            $this->completingVoid = false;
        }
    }

    protected static function booted(): void
    {
        static::creating(function (self $return): void {
            if ($return->status !== self::STATUS_DRAFT || $return->posting_batch_id !== null) {
                throw new ImmutableRecordException('Documents must be created as drafts.');
            }
            if (empty($return->public_id)) {
                $return->public_id = (string) Str::ulid();
            }

            if (empty($return->created_by) && auth()->check()) {
                $return->created_by = (int) auth()->id();
            }
        });

        static::updating(function (self $return): void {
            $originalStatus = $return->getOriginal('status');
            if ($originalStatus === self::STATUS_DRAFT && ($return->status !== self::STATUS_DRAFT || $return->isDirty('posting_batch_id')) && ! $return->completingPost) {
                throw new ImmutableRecordException('Only canonical posting can finalize a draft.');
            }

            if ($originalStatus === self::STATUS_VOID) {
                throw new ImmutableRecordException('Voided sales returns are immutable and cannot be updated.');
            }

            if ($originalStatus === self::STATUS_POSTED) {
                if (! $return->completingVoid) {
                    throw new \InvalidArgumentException('Only the canonical void workflow can change posted lifecycle metadata.');
                }

                if (! $return->isDirty('status') || $return->status !== self::STATUS_VOID) {
                    throw new ImmutableRecordException('Posted sales returns are immutable. Economic fields, customer, and lines cannot be altered.');
                }

                $dirty = array_keys($return->getDirty());
                $allowedVoidChanges = ['status', 'voided_at', 'voided_by', 'void_reason', 'void_posting_batch_id', 'updated_by', 'updated_at'];
                $disallowed = array_diff($dirty, $allowedVoidChanges);

                if (! empty($disallowed)) {
                    throw new ImmutableRecordException('Only void lifecycle fields can be altered during return void transition.');
                }

                if (empty($return->voided_at) || empty($return->voided_by)) {
                    throw new ImmutableRecordException('Direct status transition to void is prohibited without canonical void metadata.');
                }

                if ($return->posting_batch_id !== null) {
                    if (empty($return->void_posting_batch_id)) {
                        throw new ImmutableRecordException('Direct status transition to void is prohibited without canonical accounting reversal batch.');
                    }
                    $reversalBatch = PostingBatch::withoutGlobalScopes()->find($return->void_posting_batch_id);
                    $reversalOfId = (int) ($reversalBatch->reversal_of_id ?? 0);
                    if ($reversalBatch === null || $reversalOfId !== (int) $return->posting_batch_id || (int) $reversalBatch->company_id !== (int) $return->company_id) {
                        throw new ImmutableRecordException('Direct status transition to void requires a valid canonical accounting reversal batch.');
                    }
                }

                $hasStockItems = SalesReturnLine::withoutGlobalScopes()
                    ->where('sales_return_id', $return->id)
                    ->whereHas('product', fn ($q) => $q->where('track_stock', true))
                    ->exists();

                if ($hasStockItems) {
                    $hasCompensatingMovement = StockMovement::withoutGlobalScopes()
                        ->where('company_id', $return->company_id)
                        ->where('source_type', 'sales_return_void')
                        ->where('source_id', $return->id)
                        ->where('movement_type', StockMovement::TYPE_SALE)
                        ->exists();

                    if (! $hasCompensatingMovement) {
                        throw new ImmutableRecordException('Direct status transition to void requires canonical compensating stock movements.');
                    }
                }
            }

            if (auth()->check()) {
                $return->updated_by = (int) auth()->id();
            }
        });

        static::deleting(function (self $return): void {
            $originalStatus = $return->getOriginal('status');
            if ($originalStatus === self::STATUS_POSTED || $originalStatus === self::STATUS_VOID) {
                throw new ImmutableRecordException('Posted or voided sales returns cannot be deleted.');
            }
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
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<SalesReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class)->orderBy('line_number');
    }

    /**
     * @return BelongsTo<PostingBatch, $this>
     */
    public function postingBatch(): BelongsTo
    {
        return $this->belongsTo(PostingBatch::class);
    }

    /**
     * @return BelongsTo<PostingBatch, $this>
     */
    public function voidPostingBatch(): BelongsTo
    {
        return $this->belongsTo(PostingBatch::class, 'void_posting_batch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    public function getSubtotalAttribute(): string
    {
        return $this->subtotal_currency;
    }

    public function getGrandTotalAttribute(): string
    {
        return $this->grand_total_currency;
    }

    public function getTaxTotalAttribute(): string
    {
        return $this->tax_total_currency;
    }

    public function getDiscountTotalAttribute(): string
    {
        return $this->discount_total_currency;
    }
}
