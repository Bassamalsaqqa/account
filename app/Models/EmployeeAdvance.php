<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Phase7\HasCanonicalPhase7History;
use App\Support\Tenancy\BelongsToCompany;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string $advance_number
 * @property int $employee_id
 * @property array<string, mixed> $employee_snapshot
 * @property Carbon $advance_date
 * @property string $currency_code
 * @property string $amount
 * @property string $exchange_rate
 * @property string $base_amount
 * @property string $payment_method
 * @property int|null $money_account_id
 * @property int|null $check_id
 * @property string|null $notes
 * @property int|null $posting_batch_id
 * @property string $status
 * @property Carbon|null $posted_at
 * @property int|null $posted_by
 * @property Carbon|null $reversed_at
 * @property int|null $reversed_by
 * @property int|null $reversal_posting_batch_id
 * @property string|null $reversal_reason
 * @property string $request_hash
 * @property string $idempotency_key
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EmployeeAdvance extends Model
{
    use BelongsToCompany;
    use HasCanonicalPhase7History;

    public const string METHOD_CASH = 'cash';

    public const string METHOD_BANK = 'bank';

    public const string METHOD_CHECK = 'check';

    public const string STATUS_POSTED = 'posted';

    public const string STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'public_id',
        'company_id',
        'advance_number',
        'employee_id',
        'employee_snapshot',
        'advance_date',
        'currency_code',
        'amount',
        'exchange_rate',
        'base_amount',
        'payment_method',
        'money_account_id',
        'check_id',
        'notes',
        'posting_batch_id',
        'status',
        'posted_at',
        'posted_by',
        'reversed_at',
        'reversed_by',
        'reversal_posting_batch_id',
        'reversal_reason',
        'request_hash',
        'idempotency_key',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'advance_date' => 'date:Y-m-d',
            'employee_snapshot' => 'array',
            'amount' => 'string',
            'exchange_rate' => 'string',
            'base_amount' => 'string',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EmployeeAdvance $advance): void {
            if (empty($advance->public_id)) {
                $advance->public_id = (string) Str::ulid();
            }
        });

        static::deleting(function (EmployeeAdvance $advance): void {
            throw new ImmutableRecordException('Employee advances cannot be deleted; use canonical reversal.');
        });
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id')->withTrashed();
    }

    /** @return BelongsTo<MoneyAccount, $this> */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'money_account_id')->withTrashed();
    }

    /** @return BelongsTo<Check, $this> */
    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class, 'check_id');
    }

    /** @return BelongsTo<PostingBatch, $this> */
    public function postingBatch(): BelongsTo
    {
        return $this->belongsTo(PostingBatch::class, 'posting_batch_id');
    }

    /** @return BelongsTo<PostingBatch, $this> */
    public function reversalPostingBatch(): BelongsTo
    {
        return $this->belongsTo(PostingBatch::class, 'reversal_posting_batch_id');
    }

    /** @return HasMany<SalaryAdvanceAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(SalaryAdvanceAllocation::class, 'employee_advance_id');
    }

    /** @return HasMany<SalaryAdvanceAllocation, $this> */
    public function salaryAdvanceAllocations(): HasMany
    {
        return $this->allocations();
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    /**
     * Compute unallocated (remaining) principal amount from active allocations.
     */
    public function getRemainingAmount(): BigDecimal
    {
        if ($this->isReversed()) {
            return BigDecimal::zero();
        }

        $allocated = $this->allocations()
            ->where('status', 'active')
            ->sum('allocated_amount');

        return BigDecimal::of($this->amount)->minus(BigDecimal::of((string) $allocated));
    }

    /**
     * Compute unallocated (remaining) carrying base from active allocations.
     */
    public function getRemainingBaseAmount(): BigDecimal
    {
        if ($this->isReversed()) {
            return BigDecimal::zero();
        }

        $consumed = $this->allocations()
            ->where('status', 'active')
            ->sum('advance_base_consumed');

        return BigDecimal::of($this->base_amount)->minus(BigDecimal::of((string) $consumed));
    }
}
