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
 * @property string $salary_number
 * @property int $employee_id
 * @property array<string, mixed> $employee_snapshot
 * @property Carbon $recognition_date
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $currency_code
 * @property string $exchange_rate
 * @property string $base_salary
 * @property string $bonus
 * @property string $deduction
 * @property string $earned_salary
 * @property string $advance_applied
 * @property string $net_payable
 * @property string $base_earned_salary
 * @property string $base_advance_relief
 * @property string $base_payable
 * @property string $realized_fx_gain_loss_base
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
class SalaryEntry extends Model
{
    use BelongsToCompany;
    use HasCanonicalPhase7History;

    public const string STATUS_POSTED = 'posted';

    public const string STATUS_REVERSED = 'reversed';

    public const string PAYMENT_STATUS_UNPAID = 'unpaid';

    public const string PAYMENT_STATUS_PARTIAL = 'partially_paid';

    public const string PAYMENT_STATUS_PAID = 'paid';

    protected $fillable = [
        'public_id',
        'company_id',
        'salary_number',
        'employee_id',
        'employee_snapshot',
        'recognition_date',
        'period_start',
        'period_end',
        'currency_code',
        'exchange_rate',
        'base_salary',
        'bonus',
        'deduction',
        'earned_salary',
        'advance_applied',
        'net_payable',
        'base_earned_salary',
        'base_advance_relief',
        'base_payable',
        'realized_fx_gain_loss_base',
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
            'recognition_date' => 'date:Y-m-d',
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'employee_snapshot' => 'array',
            'exchange_rate' => 'string',
            'base_salary' => 'string',
            'bonus' => 'string',
            'deduction' => 'string',
            'earned_salary' => 'string',
            'advance_applied' => 'string',
            'net_payable' => 'string',
            'base_earned_salary' => 'string',
            'base_advance_relief' => 'string',
            'base_payable' => 'string',
            'realized_fx_gain_loss_base' => 'string',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SalaryEntry $entry): void {
            if (empty($entry->public_id)) {
                $entry->public_id = (string) Str::ulid();
            }
        });

        static::deleting(function (SalaryEntry $entry): void {
            throw new ImmutableRecordException('Salary entries cannot be deleted; use canonical reversal.');
        });
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id')->withTrashed();
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
    public function advanceAllocations(): HasMany
    {
        return $this->hasMany(SalaryAdvanceAllocation::class, 'salary_entry_id');
    }

    /** @return HasMany<SalaryPaymentAllocation, $this> */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(SalaryPaymentAllocation::class, 'salary_entry_id');
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
     * Outstanding unpaid net salary amount in transaction currency.
     */
    public function getRemainingPayableAmount(): BigDecimal
    {
        if ($this->isReversed()) {
            return BigDecimal::zero();
        }

        $paid = $this->paymentAllocations()
            ->where('status', 'active')
            ->sum('allocated_amount');

        return BigDecimal::of($this->net_payable)->minus(BigDecimal::of((string) $paid));
    }

    /**
     * Outstanding book payable in base currency.
     */
    public function getRemainingPayableBaseAmount(): BigDecimal
    {
        if ($this->isReversed()) {
            return BigDecimal::zero();
        }

        $relieved = $this->paymentAllocations()
            ->where('status', 'active')
            ->sum('salary_book_relief_base');

        return BigDecimal::of($this->base_payable)->minus(BigDecimal::of((string) $relieved));
    }

    /**
     * Derived payment status: unpaid, partially_paid, paid.
     */
    public function getPaymentStatus(): string
    {
        if ($this->isReversed()) {
            return self::STATUS_REVERSED;
        }

        $remaining = $this->getRemainingPayableAmount();
        $net = BigDecimal::of($this->net_payable);

        if ($net->isZero() || $remaining->isZero()) {
            return self::PAYMENT_STATUS_PAID;
        }

        if ($remaining->isEqualTo($net)) {
            return self::PAYMENT_STATUS_UNPAID;
        }

        return self::PAYMENT_STATUS_PARTIAL;
    }
}
