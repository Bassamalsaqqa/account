<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Purchasing\VendorPaymentApplicationCapability;
use App\Services\Purchasing\VendorPaymentApplicationScope;
use App\Services\Purchasing\VendorPaymentPostingCapability;
use App\Services\Purchasing\VendorPaymentPostingScope;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * @property int $id
 * @property int $company_id
 * @property int $vendor_payment_id
 * @property int $purchase_id
 * @property string $payment_currency_amount
 * @property string $allocated_amount
 * @property string $purchase_exchange_rate
 * @property string $payment_exchange_rate
 * @property string $base_amount_applied_to_payable
 * @property string $settlement_base_value
 * @property string $realized_fx_gain_loss_base
 * @property int|null $application_event_id
 * @property int $prior_posting_batch_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class VendorPaymentAllocation extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'vendor_payment_id',
        'purchase_id',
        'allocated_amount',
        'payment_currency_amount',
        'purchase_exchange_rate',
        'payment_exchange_rate',
        'base_amount_applied_to_payable',
        'settlement_base_value',
        'realized_fx_gain_loss_base',
        'application_event_id',
        'prior_posting_batch_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allocated_amount' => 'string',
            'payment_currency_amount' => 'string',
            'purchase_exchange_rate' => 'string',
            'payment_exchange_rate' => 'string',
            'base_amount_applied_to_payable' => 'string',
            'settlement_base_value' => 'string',
            'realized_fx_gain_loss_base' => 'string',
        ];
    }

    private bool $canonicalInitialAppend = false;

    private bool $canonicalApplicationAppend = false;

    /**
     * @param  array<string, mixed>  $values
     */
    public static function appendInitialAllocation(
        VendorPaymentPostingCapability $capability,
        VendorPayment $payment,
        array $values
    ): self {
        $scope = app(VendorPaymentPostingScope::class);
        $scope->assertPosting(
            $capability,
            (int) $payment->company_id,
            (int) $payment->vendor_id,
            (int) $payment->created_by,
            (string) $payment->payment_number
        );

        if (! $scope->consumePreparedAllocation($values)) {
            throw new InvalidArgumentException('Initial allocation must match the exact server-prepared allocation intent.');
        }

        $allocation = new self($values + [
            'company_id' => $payment->company_id,
            'vendor_payment_id' => $payment->id,
            'application_event_id' => null,
        ]);
        $allocation->canonicalInitialAppend = true;
        try {
            $allocation->save();
        } finally {
            $allocation->canonicalInitialAppend = false;
        }

        return $allocation;
    }

    protected static function booted(): void
    {
        static::creating(function (self $alloc): void {
            if ($alloc->application_event_id === null && ! $alloc->canonicalInitialAppend) {
                throw new InvalidArgumentException('Direct initial allocation creation requires canonical posting authority.');
            }
            if ($alloc->application_event_id !== null && ! $alloc->canonicalApplicationAppend) {
                throw new InvalidArgumentException('Direct application allocation creation requires canonical application authority.');
            }

            $payment = VendorPayment::withoutGlobalScopes()->find($alloc->vendor_payment_id);
            if ($payment === null || $payment->is_reversed
                || ($payment->posting_batch_id !== null && ! $alloc->canonicalApplicationAppend)) {
                throw new InvalidArgumentException('Cannot add allocations to a reversed or posted vendor payment outside canonical application.');
            }
            if (empty($alloc->prior_posting_batch_id)) {
                $alloc->prior_posting_batch_id = (int) DB::table('posting_batches')->where('company_id', $payment->company_id)->max('id');
            }
            if ($alloc->purchase_id) {
                $purchase = Purchase::withoutGlobalScopes()->find($alloc->purchase_id);
                if ($purchase === null || (int) $purchase->company_id !== (int) $payment->company_id
                    || (int) $alloc->company_id !== (int) $payment->company_id
                    || (int) $purchase->vendor_id !== (int) $payment->vendor_id) {
                    throw new InvalidArgumentException('Vendor payment and allocated purchase must belong to the same company, vendor, and currency.');
                }
            }
        });

        static::updating(function (): void {
            throw new ImmutableRecordException('VendorPaymentAllocation records are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new ImmutableRecordException('VendorPaymentAllocation records cannot be deleted.');
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function appendCanonicalApplication(
        VendorPaymentApplicationCapability $capability,
        VendorPaymentApplicationEvent $event,
        array $values,
        User $actor
    ): self {
        $scope = app(VendorPaymentApplicationScope::class);
        $scope->assertApplication(
            $capability,
            (int) $event->company_id,
            (int) $event->vendor_payment_id,
            (int) $actor->id
        );

        app(SalesActorGuard::class)->lockAndAuthorize((int) $event->company_id, $actor, 'money.vendor_payment.allocate');
        app(SalesActorGuard::class)->lockAndAuthorize((int) $event->company_id, $actor, 'purchasing.cost.view');

        if (! $event->consumePreparedAllocation($values) || ! $scope->consumePreparedAllocation($values)) {
            throw new InvalidArgumentException('An allocation requires the exact newly prepared canonical application event.');
        }

        $allocation = new self($values + [
            'application_event_id' => $event->id,
            'company_id' => $event->company_id,
            'vendor_payment_id' => $event->vendor_payment_id,
        ]);
        $allocation->canonicalApplicationAppend = true;
        try {
            $allocation->save();
        } finally {
            $allocation->canonicalApplicationAppend = false;
        }

        return $allocation;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereHas('vendorPayment', fn ($q) => $q->where('is_reversed', false))
            ->where(fn ($q) => $q->whereNull('application_event_id')->orWhereHas('applicationEvent', fn ($e) => $e->whereNotNull('applied_at')->whereNull('reversed_at')));
    }

    /**
     * @return BelongsTo<VendorPaymentApplicationEvent, $this>
     */
    public function applicationEvent(): BelongsTo
    {
        return $this->belongsTo(VendorPaymentApplicationEvent::class, 'application_event_id');
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
     * @return BelongsTo<Purchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }
}
