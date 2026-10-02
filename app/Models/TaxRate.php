<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string $code
 * @property string $name_ar
 * @property string|null $name_en
 * @property string $rate
 * @property string $calculation
 * @property bool $active
 * @property int|null $sales_tax_account_id
 * @property int|null $purchase_tax_account_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TaxRate extends Model
{
    use BelongsToCompany;

    public const string CALC_EXCLUSIVE = 'exclusive';

    public const string CALC_INCLUSIVE = 'inclusive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'code',
        'name_ar',
        'name_en',
        'rate',
        'calculation',
        'active',
        'sales_tax_account_id',
        'purchase_tax_account_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'string',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $taxRate): void {
            if (empty($taxRate->public_id)) {
                $taxRate->public_id = (string) Str::ulid();
            }

            if (empty($taxRate->created_by) && auth()->check()) {
                $taxRate->created_by = (int) auth()->id();
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
    public function salesTaxAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'sales_tax_account_id');
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function purchaseTaxAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'purchase_tax_account_id');
    }
}
