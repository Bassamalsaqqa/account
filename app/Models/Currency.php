<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    /**
     * @var string
     */
    protected $primaryKey = 'code';

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'symbol',
        'minor_units',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minor_units' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CompanyCurrency, $this>
     */
    public function companyCurrencies(): HasMany
    {
        return $this->hasMany(CompanyCurrency::class, 'currency_code', 'code');
    }
}
