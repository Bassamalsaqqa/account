<?php

namespace App\Models;

use App\Support\Tenancy\CompanyScope;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name_ar
 * @property string|null $name_en
 * @property string|null $legal_name_ar
 * @property string|null $legal_name_en
 * @property string $base_currency_code
 * @property string $base_currency
 * @property string $default_locale
 * @property string $timezone
 * @property string|null $phone
 * @property string|null $whatsapp
 * @property string|null $email
 * @property string|null $website
 * @property string|null $address_ar
 * @property string|null $address_en
 * @property string|null $registration_number
 * @property string|null $tax_number
 * @property string|null $logo_path
 * @property string|null $stamp_path
 * @property string $status
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    public function getBaseCurrencyAttribute(): string
    {
        return $this->base_currency_code;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'name_ar',
        'name_en',
        'legal_name_ar',
        'legal_name_en',
        'base_currency_code',
        'default_locale',
        'timezone',
        'phone',
        'whatsapp',
        'email',
        'website',
        'address_ar',
        'address_en',
        'registration_number',
        'tax_number',
        'logo_path',
        'stamp_path',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (Company $company) {
            if (empty($company->public_id)) {
                $company->public_id = (string) Str::ulid();
            }
        });
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->withPivot(['status', 'is_owner', 'joined_at', 'last_accessed_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<CompanyUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    /**
     * @return HasMany<CompanyLanguage, $this>
     */
    public function languages(): HasMany
    {
        return $this->hasMany(CompanyLanguage::class);
    }

    /**
     * @return HasMany<CompanyCurrency, $this>
     */
    public function companyCurrencies(): HasMany
    {
        return $this->hasMany(CompanyCurrency::class);
    }

    /**
     * @return HasMany<CompanyCurrency, $this>
     */
    public function currencies(): HasMany
    {
        return $this->companyCurrencies();
    }

    /**
     * @return HasOne<CompanyInventorySettings, $this>
     */
    public function inventorySettings(): HasOne
    {
        return $this->hasOne(CompanyInventorySettings::class);
    }

    /**
     * @return HasOne<CompanyDocumentSettings, $this>
     */
    public function documentSettings(): HasOne
    {
        return $this->hasOne(CompanyDocumentSettings::class);
    }

    /**
     * @return HasOne<CompanySecuritySettings, $this>
     */
    public function securitySettings(): HasOne
    {
        return $this->hasOne(CompanySecuritySettings::class);
    }

    /**
     * @return HasMany<AuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<Warehouse, $this>
     */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    /**
     * @return HasMany<ProductCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
    }

    /**
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /**
     * @return HasMany<Brand, $this>
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    public function displayName(?string $locale = null): string
    {
        $loc = $locale ?: app()->getLocale();

        if ($loc === 'ar') {
            return $this->name_ar ?: ($this->name_en ?? '');
        }

        return $this->name_en ?: ($this->name_ar ?? '');
    }

    public function isLanguageEnabled(string $locale): bool
    {
        if ($locale === 'ar') {
            return true;
        }

        if ($this->relationLoaded('languages')) {
            /** @var CompanyLanguage|null $lang */
            $lang = $this->languages->firstWhere('locale', $locale);

            return $lang ? (bool) $lang->enabled : false;
        }

        return CompanyScope::executeWithoutScope(function () use ($locale) {
            return $this->languages()->where('locale', $locale)->where('enabled', true)->exists();
        });
    }
}
