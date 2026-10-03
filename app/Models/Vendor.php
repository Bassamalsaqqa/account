<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\CompanyReassignmentException;
use App\Support\Tenancy\BelongsToCompany;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property string|null $code
 * @property string $name_ar
 * @property string|null $name_en
 * @property string $status
 * @property string|null $business_name_ar
 * @property string|null $business_name_en
 * @property string|null $address_ar
 * @property string|null $address_en
 * @property string|null $phone
 * @property string|null $whatsapp
 * @property string|null $email
 * @property string|null $tax_number
 * @property string|null $city_ar
 * @property string|null $city_en
 * @property string|null $postal_code
 * @property string|null $country_code
 * @property string|null $preferred_locale
 * @property string|null $default_currency_code
 * @property string|null $notes
 * @property bool $active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Vendor extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'code',
        'name_ar',
        'name_en',
        'business_name_ar',
        'business_name_en',
        'address_ar',
        'address_en',
        'status',
        'phone',
        'whatsapp',
        'email',
        'tax_number',
        'city_ar',
        'city_en',
        'postal_code',
        'country_code',
        'preferred_locale',
        'default_currency_code',
        'notes',
        'created_by',
        'updated_by',
    ];

    public function getActiveAttribute(): bool
    {
        return ($this->attributes['status'] ?? 'active') === 'active';
    }

    public function setActiveAttribute(bool $value): void
    {
        $this->attributes['status'] = $value ? 'active' : 'inactive';
    }

    protected static function booted(): void
    {
        static::saving(function (self $vendor): void {
            if ($vendor->exists && $vendor->isDirty('company_id')) {
                throw new CompanyReassignmentException('Reassigning vendor ownership is prohibited.');
            }
            $vendor->code = $vendor->code === null || trim($vendor->code) === '' ? null : strtoupper(trim($vendor->code));
            $vendor->country_code = strtoupper($vendor->country_code ?? 'PS');
            if (! $vendor->company_id) {
                $vendor->company_id = app(CompanyContext::class)->companyId();
            }
            $company = Company::findOrFail($vendor->company_id);
            Validator::make($vendor->getAttributes(), [
                'status' => ['sometimes', Rule::in(['active', 'inactive'])],
                'preferred_locale' => ['nullable', Rule::in($company->languages()->where('enabled', true)->pluck('locale')->all())],
                'default_currency_code' => ['nullable', Rule::in($company->currencies()->where('enabled', true)->pluck('currency_code')->all())],
            ])->validate();
        });
        static::creating(function (self $vendor): void {
            if (empty($vendor->public_id)) {
                $vendor->public_id = (string) Str::ulid();
            }

            if (empty($vendor->created_by)) {
                if (auth()->check()) {
                    $vendor->created_by = (int) auth()->id();
                }
            }
        });

        static::updating(function (self $vendor): void {
            if (auth()->check()) {
                $vendor->updated_by = (int) auth()->id();
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
