<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Purchasing\VendorPaymentHistoryCommands;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use App\Services\Purchasing\VendorPaymentPostingCapability;
use App\Services\Purchasing\VendorPaymentPostingScope;
use App\Services\Purchasing\VendorPaymentReversalCapability;
use App\Services\Purchasing\VendorPaymentReversalScope;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\BelongsToCompany;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string $payment_number
 * @property int $vendor_id
 * @property int $money_account_id
 * @property \Illuminate\Support\Carbon|Carbon|string $payment_date
 * @property string $payment_method
 * @property string $currency_code
 * @property string $base_currency_code
 * @property string $amount
 * @property string $exchange_rate
 * @property string $amount_base
 * @property string|null $reference_number
 * @property string|null $notes
 * @property string $document_locale
 * @property array<string, mixed> $vendor_snapshot
 * @property array<string, mixed> $company_snapshot
 * @property int|null $posting_batch_id
 * @property \Illuminate\Support\Carbon|Carbon|null $posted_at
 * @property int|null $posted_by
 * @property bool $is_reversed
 * @property \Illuminate\Support\Carbon|Carbon|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reversal_reason
 * @property int|null $reversal_posting_batch_id
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property int $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $unallocated_amount
 * @property-read string $unallocated_amount_base
 */
class VendorPayment extends Model
{
    use BelongsToCompany;

    public const string METHOD_CASH = 'cash';

    public const string METHOD_BANK = 'bank_transfer';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'payment_number',
        'vendor_id',
        'money_account_id',
        'payment_date',
        'payment_method',
        'currency_code',
        'base_currency_code',
        'amount',
        'exchange_rate',
        'amount_base',
        'reference_number',
        'notes',
        'document_locale',
        'vendor_snapshot',
        'company_snapshot',
        'posting_batch_id',
        'posted_at',
        'posted_by',
        'is_reversed',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'reversal_posting_batch_id',
        'idempotency_key',
        'request_hash',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d',
            'vendor_snapshot' => 'array',
            'company_snapshot' => 'array',
            'amount' => 'string',
            'exchange_rate' => 'string',
            'amount_base' => 'string',
            'is_reversed' => 'boolean',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public ?VendorPaymentPostingCapability $creationCapability = null;

    public function vendorDisplayName(): string
    {
        $locale = app()->getLocale();
        if (! empty($this->vendor_snapshot)) {
            if ($locale === 'ar') {
                return (string) ($this->vendor_snapshot['name_ar'] ?? $this->vendor_snapshot['name_en'] ?? $this->vendor?->displayName() ?? '');
            }

            return (string) ($this->vendor_snapshot['name_en'] ?? $this->vendor_snapshot['name_ar'] ?? $this->vendor?->displayName() ?? '');
        }

        return (string) ($this->vendor?->displayName() ?? '');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function recordProvisionalPayment(
        VendorPaymentPostingCapability $capability,
        array $attributes
    ): self {
        $scope = app(VendorPaymentPostingScope::class);
        $scope->assertPosting(
            $capability,
            (int) ($attributes['company_id'] ?? 0),
            (int) ($attributes['vendor_id'] ?? 0),
            (int) ($attributes['created_by'] ?? 0),
            (string) ($attributes['payment_number'] ?? '')
        );

        $payment = new self($attributes);
        $payment->creationCapability = $capability;
        try {
            $payment->save();
        } finally {
            $payment->creationCapability = null;
        }

        return $payment;
    }

    private bool $completingPost = false;

    public function completeCanonicalPost(PostingBatch $batch, User $actor, ?VendorPaymentPostingCapability $capability = null): void
    {
        if ($capability === null) {
            throw new InvalidArgumentException('Vendor payment completion requires active canonical posting capability.');
        }

        $scope = app(VendorPaymentPostingScope::class);
        $scope->assertPosting(
            $capability,
            (int) $this->company_id,
            (int) $this->vendor_id,
            (int) $actor->id,
            (string) $this->payment_number
        );

        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'money.vendor_payment.create');
        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.cost.view');

        $persisted = PostingBatch::query()->where('company_id', $this->company_id)->findOrFail($batch->id);
        if ($this->getOriginal('posting_batch_id') !== null
            || $persisted->source_type !== 'vendor_payment'
            || (int) $persisted->source_id !== (int) $this->id
            || $persisted->status !== 'posted') {
            throw new InvalidArgumentException('A canonical vendor payment posting is required.');
        }

        app(VendorPaymentPostedIntegrityValidator::class)->validate($this, pendingBatch: $persisted, includeApplications: false);
        $this->completingPost = true;
        try {
            $this->posting_batch_id = $persisted->id;
            $this->posted_at = now();
            $this->posted_by = (int) $actor->id;
            $this->save();
        } finally {
            $this->completingPost = false;
        }
    }

    private bool $completingReversal = false;

    public function completeCanonicalReversal(PostingBatch $reversal, User $actor, ?string $reason, ?VendorPaymentReversalCapability $capability = null): void
    {
        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'money.vendor_payment.reverse');
        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.cost.view');

        if ((int) $reversal->company_id !== (int) $this->company_id || (int) $reversal->reversal_of_id !== (int) $this->posting_batch_id) {
            throw new InvalidArgumentException('A canonical reversal of this vendor payment is required.');
        }

        $unreversedEvents = $this->applicationEvents()->whereNotNull('applied_at')->whereNull('reversed_at')->count();
        if ($unreversedEvents > 0) {
            throw new InvalidArgumentException('All dependent advance applications must be reversed before reversing the vendor payment.');
        }

        app(VendorPaymentReversalScope::class)->assertReversal($capability, (int) $this->company_id, (int) $this->id, (int) $actor->id);
        $commands = app(VendorPaymentHistoryCommands::class);
        $original = $this->postingBatch()->firstOrFail();
        $commands->assertBatch($commands->payment($this), $original);
        $commands->assertReversal($original, (int) $reversal->id, (int) $actor->id);
        $this->completingReversal = true;
        try {
            $this->is_reversed = true;
            $this->reversed_at = now();
            $this->reversed_by = (int) $actor->id;
            $this->reversal_reason = $reason;
            $this->reversal_posting_batch_id = $reversal->id;
            $this->save();
        } finally {
            $this->completingReversal = false;
        }
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment): void {
            if ($payment->posting_batch_id !== null || $payment->is_reversed || $payment->posted_at !== null) {
                throw new InvalidArgumentException('A vendor payment cannot be born with forged posting/reversal metadata.');
            }

            if ($payment->creationCapability === null) {
                throw new InvalidArgumentException('Direct vendor payment creation requires canonical runtime authority.');
            }

            $scope = app(VendorPaymentPostingScope::class);
            $scope->assertPosting(
                $payment->creationCapability,
                (int) $payment->company_id,
                (int) $payment->vendor_id,
                (int) $payment->created_by,
                (string) $payment->payment_number
            );
            if (empty($payment->public_id)) {
                $payment->public_id = (string) Str::ulid();
            }

            if (empty($payment->created_by) && auth()->check()) {
                $payment->created_by = (int) auth()->id();
            }
        });

        static::updating(function (self $payment): void {
            if ($payment->getOriginal('is_reversed')) {
                throw new ImmutableRecordException('Reversed vendor payments are immutable and cannot be edited.');
            }

            // If updating posting_batch_id on initial post:
            if (is_null($payment->getOriginal('posting_batch_id'))) {
                if (! $payment->completingPost) {
                    throw new InvalidArgumentException('Only canonical vendor payment posting can attach financial history.');
                }
                $dirty = array_keys($payment->getDirty());
                $allowed = ['posting_batch_id', 'posted_at', 'posted_by', 'updated_at'];
                $disallowed = array_diff($dirty, $allowed);
                if (! empty($disallowed)) {
                    throw new InvalidArgumentException('Vendor payments cannot be edited during posting.');
                }

                return;
            }

            // Once posted, only canonical reversal transition is allowed
            if (! $payment->completingReversal) {
                throw new ImmutableRecordException('Only canonical vendor payment reversal can change posted metadata.');
            }

            if (! $payment->isDirty('is_reversed') || ! $payment->is_reversed) {
                throw new ImmutableRecordException('Posted vendor payments are immutable and cannot be edited.');
            }

            $dirty = array_keys($payment->getDirty());
            $allowed = [
                'is_reversed',
                'reversed_at',
                'reversed_by',
                'reversal_reason',
                'reversal_posting_batch_id',
                'updated_at',
            ];
            $forbidden = array_diff($dirty, $allowed);
            if (! empty($forbidden)) {
                throw new InvalidArgumentException('Only reversal lifecycle fields can be altered during payment reversal.');
            }

            // Verify canonical reversal requirements
            if (empty($payment->reversed_at) || empty($payment->reversed_by)) {
                throw new InvalidArgumentException('Direct reversal metadata update is prohibited without canonical reversal metadata.');
            }

            if ($payment->posting_batch_id !== null) {
                if (empty($payment->reversal_posting_batch_id)) {
                    throw new InvalidArgumentException('Direct reversal metadata update is prohibited without canonical accounting reversal batch.');
                }
                $revBatch = PostingBatch::withoutGlobalScopes()->find($payment->reversal_posting_batch_id);
                if ($revBatch === null || (int) $revBatch->reversal_of_id !== (int) $payment->posting_batch_id || (int) $revBatch->company_id !== (int) $payment->company_id) {
                    throw new InvalidArgumentException('Direct reversal metadata update requires a valid canonical accounting reversal batch.');
                }
            }
        });

        static::deleting(function (self $payment): void {
            throw new ImmutableRecordException('Posted vendor payments cannot be deleted.');
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
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    /**
     * @return BelongsTo<MoneyAccount, $this>
     */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class)->withTrashed();
    }

    /**
     * @return HasMany<VendorPaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class);
    }

    /**
     * @return HasMany<VendorPaymentApplicationEvent, $this>
     */
    public function applicationEvents(): HasMany
    {
        return $this->hasMany(VendorPaymentApplicationEvent::class);
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
    public function reversalPostingBatch(): BelongsTo
    {
        return $this->belongsTo(PostingBatch::class, 'reversal_posting_batch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPosted(): bool
    {
        return $this->posting_batch_id !== null;
    }

    public function isReversed(): bool
    {
        return (bool) $this->is_reversed;
    }

    public function getAllocatedAmountAttribute(): string
    {
        $allocated = BigDecimal::zero();
        foreach ($this->allocations()->active()->get() as $alloc) {
            $allocated = $allocated->plus(BigDecimal::of((string) $alloc->allocated_amount));
        }

        return (string) $allocated->toScale(6);
    }

    public function getAllocatedAmountBaseAttribute(): string
    {
        $allocatedBase = BigDecimal::zero();
        foreach ($this->allocations()->active()->get() as $alloc) {
            $allocatedBase = $allocatedBase->plus(BigDecimal::of((string) $alloc->settlement_base_value));
        }

        return (string) $allocatedBase->toScale(6);
    }

    public function getUnallocatedAmountAttribute(): string
    {
        if ($this->is_reversed) {
            return '0.000000';
        }
        $allocated = BigDecimal::zero();
        foreach ($this->allocations()->active()->get() as $alloc) {
            $allocated = $allocated->plus(BigDecimal::of((string) $alloc->allocated_amount));
        }

        return (string) BigDecimal::of((string) $this->amount)->minus($allocated)->toScale(6);
    }

    public function getUnallocatedAmountBaseAttribute(): string
    {
        if ($this->is_reversed) {
            return '0.000000';
        }
        $allocatedBase = BigDecimal::zero();
        foreach ($this->allocations()->active()->get() as $alloc) {
            $allocatedBase = $allocatedBase->plus(BigDecimal::of((string) $alloc->settlement_base_value));
        }

        return (string) BigDecimal::of((string) $this->amount_base)->minus($allocatedBase)->toScale(6);
    }
}
