<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyInventorySettings extends Model
{
    /**
     * @var string
     */
    protected $primaryKey = 'company_id';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'allow_negative_stock',
        'default_cost_method',
        'default_expiry_warning_days',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allow_negative_stock' => 'boolean',
            'default_expiry_warning_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
