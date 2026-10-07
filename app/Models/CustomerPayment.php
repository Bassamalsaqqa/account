<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Money\MoneyEventScope;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\BelongsToCompany;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string $payment_number
 * @property int $customer_id
 * @property int|null $money_account_id
 * @property int|null $check_id
 * @property int $allocation_version
 * @property \Illuminate\Support\Carbon|Carbon|string $payment_date
 * @property string $payment_method
 * @property string $currency_code
 * @property string $amount
 * @property string $exchange_rate
 * @property string $amount_base
 * @property string|null $reference_number
 * @property string|null $notes
 * @property int|null $posting_batch_id
 * @property bool $is_reversed
 * @property \Illuminate\Support\Carbon|Carbon|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reversal_reason
 * @property int|null $reversal_posting_batch_id
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class CustomerPayment extends Model
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
        'customer_id',
        'money_account_id',
        'check_id',
        'payment_date',
        'payment_method',
        'currency_code',
        'amount',
        'allocation_version',
        'exchange_rate',
        'amount_base',
        'reference_number',
        'notes',
        'posting_batch_id',
        'is_reversed',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'reversal_posting_batch_id',
        'idempotency_key',
        'request_hash',
        'created_by',
        'company_snapshot', 'customer_snapshot', 'money_account_snapshot', 'document_locale',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d',
            'company_snapshot' => 'array', 'customer_snapshot' => 'array', 'money_account_snapshot' => 'array',
            'amount' => 'string',
            'exchange_rate' => 'string',
            'amount_base' => 'string',
            'is_reversed' => 'boolean',
            'reversed_at' => 'datetime',
        ];
    }

    private bool $completingPost = false;

    public function completeCanonicalPost(PostingBatch $batch, User $actor): void
    {
        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'money.receipt.create');
        $persisted = PostingBatch::query()->findOrFail($batch->id);
        if ($this->getOriginal('posting_batch_id') !== null || $persisted->source_type !== 'customer_payment' || (int) $persisted->source_id !== (int) $this->id) {
            throw new \InvalidArgumentException('A canonical receipt posting is required.');
        }
        $this->completingPost = true;
        try {
            $this->posting_batch_id = $persisted->id;
            $this->save();
        } finally {
            $this->completingPost = false;
        }
    }

    private bool $completingReversal = false;

    public function completeCanonicalReversal(PostingBatch $reversal, User $actor, ?string $reason): void
    {
        app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'money.receipt.reverse');
        if ((int) $reversal->company_id !== (int) $this->company_id || (int) $reversal->reversal_of_id !== (int) $this->posting_batch_id) {
            throw new \InvalidArgumentException('A canonical reversal of this receipt is required.');
        }
        $this->completingReversal = true;
        try {
            $this->is_reversed = true;
            $this->reversed_at = now();
            $this->reversed_by = $actor->id;
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
            if ($payment->payment_method === 'check') {
                app(MoneyEventScope::class)->checkForPayment((int) $payment->check_id, (int) $payment->company_id, User::findOrFail($payment->created_by), 'incoming');
            } elseif ($payment->check_id !== null) {
                throw new \InvalidArgumentException('Cash/Bank payment cannot own a Check.');
            }
            if ($payment->posting_batch_id !== null || $payment->is_reversed) {
                throw new \InvalidArgumentException('A receipt cannot be born with forged posting/reversal metadata.');
            }
            if (empty($payment->public_id)) {
                $payment->public_id = (string) Str::ulid();
            }

            if (empty($payment->created_by) && auth()->check()) {
                $payment->created_by = (int) auth()->id();
            }
        });

        static::updating(function (self $payment): void {
            if ($payment->getOriginal('is_reversed')) {
                throw new \InvalidArgumentException('Reversed customer payments are immutable and cannot be edited.');
            }

            // If updating posting_batch_id on initial post:
            if (is_null($payment->getOriginal('posting_batch_id'))) {
                if (! $payment->completingPost) {
                    throw new \InvalidArgumentException('Only canonical receipt posting can attach financial history.');
                }
                $dirty = array_keys($payment->getDirty());
                $allowed = ['posting_batch_id', 'updated_at'];
                $disallowed = array_diff($dirty, $allowed);
                if (! empty($disallowed)) {
                    throw new \InvalidArgumentException('Customer payments cannot be edited during posting.');
                }

                return;
            }

            // Once posted, only canonical reversal transition is allowed
            if (! $payment->completingReversal) {
                throw new \InvalidArgumentException('Only the canonical receipt reversal can change posted metadata.');
            }

            if (! $payment->isDirty('is_reversed') || ! $payment->is_reversed) {
                throw new \InvalidArgumentException('Posted customer payments are immutable and cannot be edited.');
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
                throw new \InvalidArgumentException('Only reversal lifecycle fields can be altered during payment reversal.');
            }

            // Verify canonical reversal requirements
            if (empty($payment->reversed_at) || empty($payment->reversed_by)) {
                throw new \InvalidArgumentException('Direct reversal metadata update is prohibited without canonical reversal metadata.');
            }

            if ($payment->posting_batch_id !== null) {
                if (empty($payment->reversal_posting_batch_id)) {
                    throw new \InvalidArgumentException('Direct reversal metadata update is prohibited without canonical accounting reversal batch.');
                }
                $revBatch = PostingBatch::withoutGlobalScopes()->find($payment->reversal_posting_batch_id);
                if ($revBatch === null || (int) $revBatch->reversal_of_id !== (int) $payment->posting_batch_id || (int) $revBatch->company_id !== (int) $payment->company_id) {
                    throw new \InvalidArgumentException('Direct reversal metadata update requires a valid canonical accounting reversal batch.');
                }
            }
        });

        static::deleting(function (self $payment): void {
            throw new \InvalidArgumentException('Posted customer payments cannot be deleted.');
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<MoneyAccount, $this>
     */
    /** @return BelongsTo<Check, $this> */
    public function checkInstrument(): BelongsTo
    {
        return $this->belongsTo(Check::class, 'check_id');
    }

    /** @return BelongsTo<MoneyAccount, $this> */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class)->withTrashed();
    }

    /**
     * @return HasMany<CustomerPaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
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

    public function getUnallocatedAmountAttribute(): string
    {
        if ($this->is_reversed) {
            return '0.000000';
        }
        $allocated = BigDecimal::zero();
        foreach ($this->allocations()->active()->get() as $alloc) {
            $allocated = $allocated->plus(BigDecimal::of((string) $alloc->payment_currency_amount));
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
