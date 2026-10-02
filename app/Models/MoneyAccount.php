<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string $account_type
 * @property string $name_ar
 * @property string|null $name_en
 * @property string $currency_code
 * @property int $ledger_account_id
 * @property string|null $bank_name
 * @property string|null $account_number
 * @property string|null $iban
 * @property bool $is_active
 * @property int $sort_order
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class MoneyAccount extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    public const string TYPE_CASH = 'cash';

    public const string TYPE_BANK = 'bank';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'account_type',
        'name_ar',
        'name_en',
        'currency_code',
        'ledger_account_id',
        'bank_name',
        'account_number',
        'iban',
        'is_active',
        'sort_order',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            if (empty($account->public_id)) {
                $account->public_id = (string) Str::ulid();
            }

            if (empty($account->created_by) && auth()->check()) {
                $account->created_by = (int) auth()->id();
            }
        });
    }

    public function displayName(): string
    {
        $locale = app()->getLocale();
        if ($locale === 'en' && ! empty($this->name_en)) {
            return $this->name_en;
        }

        return $this->name_ar;
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
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    /**
     * @return HasMany<CustomerPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }
}
