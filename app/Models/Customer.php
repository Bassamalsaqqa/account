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
 * @property string|null $code
 * @property string $name_ar
 * @property string|null $name_en
 * @property string $status
 * @property string|null $business_name_ar
 * @property string|null $business_name_en
 * @property string|null $address_ar
 * @property string|null $address_en
 * @property string|null $business_name
 * @property string|null $phone
 * @property string|null $whatsapp
 * @property string|null $email
 * @property string|null $tax_number
 * @property string|null $address_line_1_ar
 * @property string|null $address_line_1_en
 * @property string|null $city_ar
 * @property string|null $city_en
 * @property string|null $postal_code
 * @property string|null $country_code
 * @property string|null $preferred_locale
 * @property string|null $default_currency_code
 * @property string|null $credit_limit
 * @property string|null $notes
 * @property bool $active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Customer extends Model
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
        'business_name',
        'phone',
        'whatsapp',
        'email',
        'tax_number',
        'address_line_1_ar',
        'address_line_1_en',
        'city_ar',
        'city_en',
        'postal_code',
        'country_code',
        'preferred_locale',
        'default_currency_code',
        'credit_limit',
        'notes',
        'active',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit_limit' => 'string',
        ];
    }

    public function getIsActiveAttribute(): bool
    {
        return (bool) $this->active;
    }

    public function setIsActiveAttribute(bool $value): void
    {
        $this->setActiveAttribute($value);
    }

    /** Form compatibility aliases; status and bilingual columns remain the only stored truth. */
    public function getActiveAttribute(): bool
    {
        return ($this->attributes['status'] ?? 'active') === 'active';
    }

    public function setActiveAttribute(bool $value): void
    {
        $this->attributes['status'] = $value ? 'active' : 'inactive';
    }

    public function getBusinessNameAttribute(): ?string
    {
        return $this->attributes['business_name_ar'] ?? null;
    }

    public function setBusinessNameAttribute(?string $value): void
    {
        $this->attributes['business_name_ar'] = $value;
    }

    public function getAddressLine1ArAttribute(): ?string
    {
        return $this->attributes['address_ar'] ?? null;
    }

    public function setAddressLine1ArAttribute(?string $value): void
    {
        $this->attributes['address_ar'] = $value;
    }

    public function getAddressLine1EnAttribute(): ?string
    {
        return $this->attributes['address_en'] ?? null;
    }

    public function setAddressLine1EnAttribute(?string $value): void
    {
        $this->attributes['address_en'] = $value;
    }

    protected static function booted(): void
    {
        static::deleting(function (self $customer): void {
            foreach ([SalesInvoice::class, Quotation::class, SalesReturn::class, CustomerPayment::class] as $model) {
                if ($model::where('company_id', $customer->company_id)->where('customer_id', $customer->id)->exists()) {
                    throw new \InvalidArgumentException('Customers with sales history must be deactivated rather than deleted.');
                }
            }
        });
        static::creating(function (self $customer): void {
            if (empty($customer->public_id)) {
                $customer->public_id = (string) Str::ulid();
            }

            if (empty($customer->created_by)) {
                if (auth()->check()) {
                    $customer->created_by = (int) auth()->id();
                }
            }
        });

        static::updating(function (self $customer): void {
            if (auth()->check()) {
                $customer->updated_by = (int) auth()->id();
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

    /**
     * @return HasMany<SalesInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class);
    }

    /**
     * @return HasMany<Quotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    /**
     * @return HasMany<CustomerPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    /**
     * @return HasMany<SalesReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }
}
