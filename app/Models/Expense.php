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
 * @property string $expense_number
 * @property Carbon $expense_date
 * @property int $category_id
 * @property array<string, mixed> $category_snapshot
 * @property int|null $vendor_id
 * @property array<string, mixed>|null $vendor_snapshot
 * @property string|null $payee_name
 * @property string $classification
 * @property string $description
 * @property string $currency_code
 * @property string $amount
 * @property string $exchange_rate
 * @property string $base_amount
 * @property string $payment_method
 * @property int|null $money_account_id
 * @property int|null $check_id
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property string|null $attachment_mime
 * @property int|null $attachment_size
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
class Expense extends Model
{
    use BelongsToCompany;
    use HasCanonicalPhase7History;

    public const string METHOD_CASH = 'cash';

    public const string METHOD_BANK = 'bank';

    public const string METHOD_CHECK = 'check';

    public const string STATUS_POSTED = 'posted';

    public const string STATUS_REVERSED = 'reversed';

    public const string CLASSIFICATION_OPERATING = 'operating';

    public const string CLASSIFICATION_LANDED_COST = 'landed_cost';

    protected $fillable = [
        'public_id',
        'company_id',
        'expense_number',
        'expense_date',
        'category_id',
        'category_snapshot',
        'vendor_id',
        'vendor_snapshot',
        'payee_name',
        'classification',
        'description',
        'currency_code',
        'amount',
        'exchange_rate',
        'base_amount',
        'payment_method',
        'money_account_id',
        'check_id',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'attachment_size',
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
            'expense_date' => 'date:Y-m-d',
            'category_snapshot' => 'array',
            'vendor_snapshot' => 'array',
            'amount' => 'string',
            'exchange_rate' => 'string',
            'base_amount' => 'string',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Expense $expense): void {
            if (empty($expense->public_id)) {
                $expense->public_id = (string) Str::ulid();
            }
        });

        static::deleting(function (Expense $expense): void {
            throw new ImmutableRecordException('Expenses cannot be deleted; use canonical reversal.');
        });
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
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

    /** @return HasMany<LandedCostAllocation, $this> */
    public function landedAllocations(): HasMany
    {
        return $this->hasMany(LandedCostAllocation::class, 'expense_id');
    }

    /** @return HasMany<LandedCostAllocation, $this> */
    public function landedCostAllocations(): HasMany
    {
        return $this->landedAllocations();
    }

    public function isLandedCost(): bool
    {
        return $this->classification === self::CLASSIFICATION_LANDED_COST;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }
}
