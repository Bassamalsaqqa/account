<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\ValueObjects\ExchangeRate as ExchangeRateValueObject;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'base_currency_code',
        'currency_code',
        'rate',
        'effective_at',
        'source',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'rate' => 'string',
        ];
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
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function toValueObject(): ExchangeRateValueObject
    {
        return ExchangeRateValueObject::from($this->rate);
    }
}
