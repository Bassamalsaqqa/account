<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Purchasing\VendorPaymentApplicationCapability;
use App\Services\Purchasing\VendorPaymentApplicationIntegrityValidator;
use App\Services\Purchasing\VendorPaymentApplicationScope;
use App\Services\Purchasing\VendorPaymentHistoryCommands;
use App\Services\Purchasing\VendorPaymentReversalCapability;
use App\Services\Purchasing\VendorPaymentReversalScope;
use App\Services\Sales\ReceiptRequestValues;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property int $vendor_payment_id
 * @property Carbon|Carbon|string $application_date
 * @property string $idempotency_key
 * @property string|null $request_hash
 * @property int|null $posting_batch_id
 * @property int|null $reversal_posting_batch_id
 * @property int $applied_by
 * @property Carbon|null $applied_at
 * @property int|null $reversed_by
 * @property Carbon|null $reversed_at
 * @property string|null $reversal_reason
 * @property Carbon $created_at
 */
class VendorPaymentApplicationEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'application_date' => 'date:Y-m-d',
            'applied_at' => 'datetime',
            'reversed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    private bool $recordingApplication = false;

    private bool $completing = false;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $pendingAllocations = [];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $allocations
     */
    public static function recordCanonicalApplication(
        VendorPayment $payment,
        User $actor,
        array $attributes,
        array $allocations,
        ?VendorPaymentApplicationCapability $capability = null
    ): self {
        if ($capability === null) {
            throw new InvalidArgumentException('Credit application requires active canonical application capability.');
        }

        $scope = app(VendorPaymentApplicationScope::class);
        $scope->assertApplication(
            $capability,
            (int) $payment->company_id,
            (int) $payment->id,
            (int) $actor->id
        );

        app(SalesActorGuard::class)->lockAndAuthorize((int) $payment->company_id, $actor, 'money.vendor_payment.allocate');
        app(SalesActorGuard::class)->lockAndAuthorize((int) $payment->company_id, $actor, 'purchasing.cost.view');

        $payment = VendorPayment::where('company_id', $payment->company_id)->lockForUpdate()->findOrFail($payment->id);
        ReceiptRequestValues::key($attributes['idempotency_key']);
        app(SalesDocumentRules::class)->date($attributes['application_date']);

        if ($payment->posting_batch_id === null || $payment->is_reversed || $allocations === []) {
            throw new ImmutableRecordException('Credit application requires a posted active vendor payment and allocations.');
        }

        $intent = array_map(fn (array $row): array => [
            'purchase_id' => (int) $row['purchase_id'],
            'allocated_amount' => (string) $row['allocated_amount'],
        ], $allocations);

        if (count(array_unique(array_column($intent, 'purchase_id'))) !== count($intent)) {
            throw new ImmutableRecordException('Canonical application allocations must be aggregated once per purchase.');
        }

        $expected = app(ApplyVendorPaymentCreditAction::class)->prepare($payment, $intent, $attributes['application_date']);
        if ($expected !== $allocations || ($attributes['request_hash'] ?? null) !== ApplyVendorPaymentCreditAction::requestHash(
            (int) $payment->company_id,
            (int) $payment->id,
            (int) $actor->id,
            (string) $attributes['application_date'],
            $intent
        )) {
            throw new ImmutableRecordException('Credit application allocations must match authoritative exact calculations and request identity.');
        }

        $event = new self([
            'public_id' => (string) Str::ulid(),
            'company_id' => $payment->company_id,
            'vendor_payment_id' => $payment->id,
            'application_date' => $attributes['application_date'],
            'idempotency_key' => $attributes['idempotency_key'],
            'request_hash' => $attributes['request_hash'],
            'applied_by' => $actor->id,
            'created_at' => now(),
        ]);

        $event->recordingApplication = true;
        try {
            $event->save();
            foreach ($allocations as $values) {
                $event->pendingAllocations[(int) $values['purchase_id']] = $values;
                VendorPaymentAllocation::appendCanonicalApplication($capability, $event, $values, $actor);
            }
        } finally {
            $event->recordingApplication = false;
            $event->pendingAllocations = [];
        }

        return $event;
    }

    /**
     * One-use capability tied to this newly recorded event and its exact prepared allocation.
     *
     * @param  array<string, mixed>  $values
     */
    public function consumePreparedAllocation(array $values): bool
    {
        $id = (int) ($values['purchase_id'] ?? 0);
        if (! $this->recordingApplication || ($this->pendingAllocations[$id] ?? null) !== $values) {
            return false;
        }
        unset($this->pendingAllocations[$id]);

        return true;
    }

    public function completeCanonicalApplication(
        ?PostingBatch $batch,
        User $actor,
        ?VendorPaymentApplicationCapability $capability = null
    ): void {
        if ($capability === null) {
            throw new InvalidArgumentException('Credit application completion requires active canonical application capability.');
        }

        $scope = app(VendorPaymentApplicationScope::class);
        $scope->assertApplication(
            $capability,
            (int) $this->company_id,
            (int) $this->vendor_payment_id,
            (int) $actor->id
        );

        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'money.vendor_payment.allocate');
        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.cost.view');

        if ($scope->hasNonzeroFinancialEffect()) {
            if ($batch === null) {
                throw new InvalidArgumentException('Nonzero FX application requires a canonical posting batch.');
            }
        } else {
            if ($batch !== null) {
                throw new InvalidArgumentException('Zero FX application must have a null posting batch.');
            }
        }

        if ($this->applied_at !== null || ($batch !== null && ((int) $batch->company_id !== (int) $this->company_id
            || $batch->source_type !== 'vendor_payment_application'
            || (int) $batch->source_id !== (int) $this->id
            || $batch->status !== 'posted'))) {
            throw new ImmutableRecordException('Invalid canonical credit application completion.');
        }

        app(VendorPaymentApplicationIntegrityValidator::class)->validate($this, pendingBatch: $batch, completing: true);
        $this->completing = true;
        try {
            $this->posting_batch_id = $batch?->id;
            $this->applied_at = now();
            $this->save();
        } finally {
            $this->completing = false;
        }
    }

    public function completeCanonicalReversal(?PostingBatch $batch, User $actor, ?string $reason, ?VendorPaymentReversalCapability $capability = null): void
    {
        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'money.vendor_payment.reverse');
        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.cost.view');

        if ($this->reversed_at !== null || $this->applied_at === null
            || ($this->posting_batch_id !== null && ($batch === null || (int) $batch->company_id !== (int) $this->company_id || (int) $batch->reversal_of_id !== (int) $this->posting_batch_id))
            || ($this->posting_batch_id === null && $batch !== null)) {
            throw new ImmutableRecordException('Invalid canonical credit application reversal.');
        }

        $scope = app(VendorPaymentReversalScope::class);
        $scope->assertReversal($capability, (int) $this->company_id, (int) $this->vendor_payment_id, (int) $actor->id, (int) $this->id);
        if ($batch !== null) {
            app(VendorPaymentHistoryCommands::class)->assertReversal($this->postingBatch()->firstOrFail(), (int) $batch->id, (int) $actor->id);
        }
        $this->completing = true;
        try {
            $this->reversed_at = now();
            $this->reversed_by = (int) $actor->id;
            $this->reversal_reason = $reason;
            $this->reversal_posting_batch_id = $batch?->id;
            $this->save();
            $scope->completeEvent($capability, (int) $this->id);
        } finally {
            $this->completing = false;
        }
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if (! $event->recordingApplication || $event->posting_batch_id !== null || $event->applied_at !== null || $event->reversed_at !== null) {
                throw new ImmutableRecordException('Application events must originate in canonical credit application.');
            }
        });

        static::updating(function (self $event): void {
            $fields = $event->getOriginal('applied_at') === null
                ? ['posting_batch_id', 'applied_at']
                : ['reversal_posting_batch_id', 'reversed_at', 'reversed_by', 'reversal_reason'];

            if (! $event->completing || $event->getOriginal('reversed_at') !== null || array_diff(array_keys($event->getDirty()), $fields) !== []) {
                throw new ImmutableRecordException('Credit application history is immutable.');
            }
        });

        static::deleting(fn () => throw new ImmutableRecordException('Credit application events cannot be deleted.'));
    }

    /**
     * @return BelongsTo<VendorPayment, $this>
     */
    public function vendorPayment(): BelongsTo
    {
        return $this->belongsTo(VendorPayment::class);
    }

    /**
     * @return BelongsTo<VendorPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->vendorPayment();
    }

    /**
     * @return HasMany<VendorPaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class, 'application_event_id');
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
    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function getIsReversedAttribute(): bool
    {
        return $this->reversed_at !== null;
    }
}
