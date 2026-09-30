<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LedgerAccount extends Model
{
    use BelongsToCompany;

    public const string TYPE_ASSET = 'asset';

    public const string TYPE_LIABILITY = 'liability';

    public const string TYPE_EQUITY = 'equity';

    public const string TYPE_REVENUE = 'revenue';

    public const string TYPE_EXPENSE = 'expense';

    public const string BALANCE_DEBIT = 'debit';

    public const string BALANCE_CREDIT = 'credit';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'code',
        'system_key',
        'name_ar',
        'name_en',
        'account_type',
        'normal_balance',
        'parent_id',
        'is_control',
        'is_system',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_control' => 'boolean',
            'is_system' => 'boolean',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            if (empty($account->public_id)) {
                $account->public_id = (string) Str::ulid();
            }
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
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<LedgerAccount, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<PostingLine, $this>
     */
    public function postingLines(): HasMany
    {
        return $this->hasMany(PostingLine::class, 'ledger_account_id');
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === self::BALANCE_DEBIT;
    }

    public function isCreditNormal(): bool
    {
        return $this->normal_balance === self::BALANCE_CREDIT;
    }
}
