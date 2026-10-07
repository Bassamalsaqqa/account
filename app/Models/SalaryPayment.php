<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Phase7\HasCanonicalPhase7History;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string $payment_number
 * @property int $employee_id
 * @property array<string, mixed> $employee_snapshot
 * @property Carbon $payment_date
 * @property string $currency_code
 * @property string $amount
 * @property string $exchange_rate
 * @property string $base_amount
 * @property string $salary_book_relief_base
 * @property string $realized_fx_gain_loss_base
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
class SalaryPayment extends Model
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
        'payment_number',
        'employee_id',
        'employee_snapshot',
        'payment_date',
        'currency_code',
        'amount',
        'exchange_rate',
        'base_amount',
        'salary_book_relief_base',
        'realized_fx_gain_loss_base',
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
            'payment_date' => 'date:Y-m-d',
            'employee_snapshot' => 'array',
            'amount' => 'string',
            'exchange_rate' => 'string',
            'base_amount' => 'string',
            'salary_book_relief_base' => 'string',
            'realized_fx_gain_loss_base' => 'string',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SalaryPayment $payment): void {
            if (empty($payment->public_id)) {
                $payment->public_id = (string) Str::ulid();
            }
        });

        static::deleting(function (SalaryPayment $payment): void {
            throw new ImmutableRecordException('Salary payments cannot be deleted; use canonical reversal.');
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

    /** @return HasMany<SalaryPaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(SalaryPaymentAllocation::class, 'salary_payment_id');
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
}
