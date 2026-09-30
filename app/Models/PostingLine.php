<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $posting_batch_id
 * @property int $ledger_account_id
 * @property int $line_number
 * @property string|null $description
 * @property string $debit_base
 * @property string $credit_base
 * @property string|null $transaction_currency_code
 * @property string|null $transaction_amount
 * @property string|null $exchange_rate
 * @property Carbon|null $created_at
 */
class PostingLine extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'posting_batch_id',
        'ledger_account_id',
        'line_number',
        'description',
        'debit_base',
        'credit_base',
        'transaction_currency_code',
        'transaction_amount',
        'exchange_rate',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'debit_base' => 'string',
            'credit_base' => 'string',
            'transaction_amount' => 'string',
            'exchange_rate' => 'string',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            if ($line->created_at === null) {
                $line->created_at = now();
            }
        });

        static::updating(function (): void {
            throw ImmutableRecordException::cannotModify('line');
        });

        static::deleting(function (): void {
            throw ImmutableRecordException::cannotDelete('line');
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
     * @return BelongsTo<PostingBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PostingBatch::class, 'posting_batch_id');
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function debitAmount(): MoneyAmount
    {
        return MoneyAmount::from($this->debit_base);
    }

    public function creditAmount(): MoneyAmount
    {
        return MoneyAmount::from($this->credit_base);
    }
}
