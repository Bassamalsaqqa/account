<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Sales\ReceiptRequestValues;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon|null $applied_at
 * @property Carbon|null $reversed_at
 */
class CustomerPaymentApplicationEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['applied_at' => 'datetime', 'reversed_at' => 'datetime', 'created_at' => 'datetime', 'application_date' => 'date'];
    }

    private bool $recordingApplication = false;

    private bool $completing = false;

    /** @var array<int, array<string, mixed>> */
    private array $pendingAllocations = [];

    /** @param array<string, mixed> $attributes
     * @param  list<array<string, mixed>>  $allocations
     */
    public static function recordCanonicalApplication(CustomerPayment $payment, User $actor, array $attributes, array $allocations): self
    {
        app(SalesActorGuard::class)->lockAndAuthorize((int) $payment->company_id, $actor, 'money.receipt.allocate');
        $payment = CustomerPayment::where('company_id', $payment->company_id)->lockForUpdate()->findOrFail($payment->id);
        ReceiptRequestValues::key($attributes['idempotency_key']);
        app(SalesDocumentRules::class)->date($attributes['application_date']);
        if ($payment->posting_batch_id === null || $payment->is_reversed || $allocations === []) {
            throw new ImmutableRecordException('Credit application requires a posted active receipt and allocations.');
        }
        $intent = array_map(fn (array $row): array => ['sales_invoice_id' => (int) $row['sales_invoice_id'], 'allocated_amount' => (string) $row['allocated_amount']], $allocations);
        if (count(array_unique(array_column($intent, 'sales_invoice_id'))) !== count($intent)) {
            throw new ImmutableRecordException('Canonical application allocations must be aggregated once per invoice.');
        }
        $expected = app(ApplyCustomerPaymentCreditAction::class)->prepare($payment, $intent);
        if ($expected !== $allocations || ($attributes['request_hash'] ?? null) !== ApplyCustomerPaymentCreditAction::requestHash(
            (int) $payment->company_id, (int) $payment->id, (int) $actor->id, (string) $attributes['application_date'], $intent)) {
            throw new ImmutableRecordException('Credit application allocations must match authoritative exact calculations and request identity.');
        }
        $event = new self(['application_date' => $attributes['application_date'], 'idempotency_key' => $attributes['idempotency_key'], 'request_hash' => $attributes['request_hash'], 'company_id' => $payment->company_id, 'customer_payment_id' => $payment->id,
            'public_id' => (string) Str::ulid(), 'applied_by' => $actor->id, 'created_at' => now()]);
        $event->recordingApplication = true;
        try {
            $event->save();
            foreach ($allocations as $values) {
                $event->pendingAllocations[(int) $values['sales_invoice_id']] = $values;
                CustomerPaymentAllocation::appendCanonicalApplication($event, $values, $actor);
            }
        } finally {
            $event->recordingApplication = false;
            $event->pendingAllocations = [];
        }

        return $event;
    }

    /** One-use capability tied to this newly recorded event and its exact prepared allocation, never recoverable from a DB read.
     * @param  array<string, mixed>  $values
     */
    public function consumePreparedAllocation(array $values): bool
    {
        $id = (int) ($values['sales_invoice_id'] ?? 0);
        if (! $this->recordingApplication || ($this->pendingAllocations[$id] ?? null) !== $values) {
            return false;
        }
        unset($this->pendingAllocations[$id]);

        return true;
    }

    public function completeCanonicalApplication(?PostingBatch $batch, User $actor): void
    {
        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'money.receipt.allocate');
        if ($this->applied_at !== null || ($batch !== null && ((int) $batch->company_id !== (int) $this->company_id
            || $batch->source_type !== 'customer_payment_application' || (int) $batch->source_id !== (int) $this->id))) {
            throw new ImmutableRecordException('Invalid canonical credit application completion.');
        }
        $this->completing = true;
        try {
            $this->posting_batch_id = $batch?->id;
            $this->applied_at = now();
            $this->save();
        } finally {
            $this->completing = false;
        }
    }

    public function completeCanonicalReversal(?PostingBatch $batch, User $actor, ?string $reason): void
    {
        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'money.receipt.reverse');
        if ($this->reversed_at !== null || $this->applied_at === null
            || ($this->posting_batch_id !== null && ($batch === null || (int) $batch->company_id !== (int) $this->company_id || (int) $batch->reversal_of_id !== (int) $this->posting_batch_id))
            || ($this->posting_batch_id === null && $batch !== null)) {
            throw new ImmutableRecordException('Invalid canonical credit application reversal.');
        }
        $this->completing = true;
        try {
            $this->reversed_at = now();
            $this->reversed_by = $actor->id;
            $this->reversal_reason = $reason;
            $this->reversal_posting_batch_id = $batch?->id;
            $this->save();
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
            $fields = $event->getOriginal('applied_at') === null ? ['posting_batch_id', 'applied_at'] : ['reversal_posting_batch_id', 'reversed_at', 'reversed_by', 'reversal_reason'];
            if (! $event->completing || $event->getOriginal('reversed_at') !== null || array_diff(array_keys($event->getDirty()), $fields) !== []) {
                throw new ImmutableRecordException('Credit application history is immutable.');
            }
        });
        static::deleting(fn () => throw new ImmutableRecordException('Credit application events cannot be deleted.'));
    }

    /** @return BelongsTo<CustomerPayment, $this> */
    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class);
    }

    /** @return HasMany<CustomerPaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class, 'application_event_id');
    }
}
