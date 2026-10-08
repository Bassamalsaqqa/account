<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Services\Purchasing\PurchasePayablePosition;
use App\Services\Purchasing\PurchasePostingCommandBuilder;
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
 * @property int $company_id
 * @property string $public_id
 * @property int $vendor_id
 * @property int $warehouse_id
 * @property string $status
 * @property string $currency_code
 * @property string $base_currency_code
 * @property string $document_locale
 * @property Carbon $purchase_date
 * @property Carbon|null $due_date
 * @property string|null $vendor_invoice_number
 * @property string|null $notes
 * @property string $grand_total_currency
 * @property string $grand_total_base
 * @property Carbon|null $posted_at
 * @property array<string, mixed>|null $vendor_snapshot
 * @property array<string, mixed>|null $company_snapshot
 * @property string $exchange_rate
 */
class Purchase extends Model
{
    use BelongsToCompany;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_POSTED = 'posted';

    public const string STATUS_VOID = 'void';

    /** @var list<string> */
    public const RESERVED_FIELDS = ['purchase_number', 'posting_batch_id', 'posted_at', 'posted_by', 'voided_at', 'voided_by', 'void_reason', 'void_posting_batch_id'];

    /** @var list<string> */
    protected $fillable = [
        'public_id', 'company_id', 'purchase_number', 'vendor_id', 'vendor_invoice_number',
        'warehouse_id', 'status', 'purchase_date', 'due_date', 'currency_code', 'base_currency_code',
        'exchange_rate', 'document_locale', 'subtotal_currency', 'discount_total_currency',
        'tax_total_currency', 'grand_total_currency', 'subtotal_base', 'discount_total_base',
        'tax_total_base', 'grand_total_base', 'notes', 'vendor_snapshot', 'company_snapshot',
        'posting_batch_id', 'posted_at', 'posted_by', 'voided_at', 'voided_by', 'void_reason',
        'void_posting_batch_id', 'created_by', 'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d',
            'vendor_snapshot' => 'array', 'company_snapshot' => 'array',
            'posted_at' => 'datetime', 'voided_at' => 'datetime',
            'exchange_rate' => 'string',
        ];
    }

    private bool $completingPost = false;

    public function completeCanonicalPost(PostingBatch $batch, string $number, User $actor): void
    {
        if (DB::transactionLevel() === 0) {
            throw new ImmutableRecordException('Purchase completion requires an existing outer posting transaction.');
        }
        DB::transaction(function () use ($batch, $number, $actor): void {
            $company = app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.purchase.post');
            app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.cost.view');
            $this->assertMutableDraft();
            $persisted = PostingBatch::where('company_id', $this->company_id)->findOrFail($batch->id);
            if ($this->isDirty() || trim($number) === '' || (int) $persisted->posted_by !== (int) $actor->id
                || $persisted->idempotency_key !== 'purchase_'.$this->id.'_posting' || $persisted->status !== 'posted'
                || ! app(PurchasePostingCommandBuilder::class)->build($company, $this, $number, $actor)->matchesBatch($persisted)) {
                throw new ImmutableRecordException('Coherent canonical Purchase posting is required.');
            }
            $this->completingPost = true;
            try {
                $this->status = self::STATUS_POSTED;
                $this->purchase_number = $number;
                $this->posting_batch_id = $persisted->id;
                $this->posted_at = now();
                $this->posted_by = $actor->id;
                $this->save();
            } finally {
                $this->completingPost = false;
            }
        });
    }

    public function setPurchaseDateAttribute(string $value): void
    {
        app(PurchaseDocumentRules::class)->date($value);
        $this->attributes['purchase_date'] = $value;
    }

    public function setDueDateAttribute(?string $value): void
    {
        if ($value !== null) {
            app(PurchaseDocumentRules::class)->date($value);
        }
        $this->attributes['due_date'] = $value;
    }

    protected static function booted(): void
    {
        static::saving(function (self $purchase): void {
            if ($purchase->exists) {
                $purchase->assertMutableDraft();
                if ($purchase->isDirty(['company_id', 'public_id', 'created_by'])) {
                    throw new ImmutableRecordException('Purchase ownership and identity are immutable.');
                }
            }
            if (! $purchase->isDraft()) {
                if (! $purchase->completingPost || $purchase->status !== self::STATUS_POSTED
                    || array_diff(array_keys($purchase->getDirty()), ['status', 'purchase_number', 'posting_batch_id', 'posted_at', 'posted_by', 'updated_at']) !== []) {
                    throw new ImmutableRecordException('Only canonical posting may finalize a persisted Purchase Draft.');
                }

                return;
            }
            foreach (self::RESERVED_FIELDS as $field) {
                if ($purchase->getAttribute($field) !== null) {
                    throw new ImmutableRecordException('Purchase drafts cannot carry posting, void or numbering effects.');
                }
            }
            foreach (['subtotal_currency', 'discount_total_currency', 'tax_total_currency', 'grand_total_currency', 'subtotal_base', 'discount_total_base', 'tax_total_base', 'grand_total_base'] as $field) {
                $amount = MoneyAmount::from($purchase->getAttributes()[$field] ?? null);
                if ($amount->getAmount()->isNegative()) {
                    throw new \InvalidArgumentException('Purchase totals cannot be negative.');
                }
                $purchase->setAttribute($field, (string) $amount);
            }
            $company = Company::findOrFail($purchase->company_id);
            app(PurchaseDocumentRules::class)->validateModelHeader($company, $purchase);
        });
        static::creating(function (self $purchase): void {
            $purchase->public_id ??= (string) Str::ulid();
        });
        static::deleting(fn (self $purchase) => $purchase->assertMutableDraft());
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
        // Read persisted lifecycle rather than trusting a stale or caller-modified model.
        if (! $this->exists || self::where('company_id', $this->getRawOriginal('company_id'))
            ->whereKey($this->id)->value('status') !== self::STATUS_DRAFT) {
            throw new ImmutableRecordException('Only persisted Purchase drafts are mutable.');
        }
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

    /** @return HasMany<PurchaseLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class)->orderBy('line_number');
    }

    /** @return HasMany<PurchaseReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class)->orderBy('id');
    }

    /** @return HasMany<VendorPaymentAllocation, $this> */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class);
    }

    /** @return HasMany<LandedCostAllocation, $this> */
    public function landedCostAllocations(): HasMany
    {
        return $this->hasMany(LandedCostAllocation::class)->orderBy('id');
    }

    public function payablePosition(): PurchasePayablePosition
    {
        return PurchasePayablePosition::forPurchase($this);
    }

    public function calculateOutstanding(): BigDecimal
    {
        return $this->payablePosition()->outstanding;
    }

    public function derivedPaymentStatus(): string
    {
        return $this->payablePosition()->status;
    }
}
