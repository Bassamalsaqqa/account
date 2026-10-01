<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyInventorySettings extends Model
{
    use BelongsToCompany;

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
        'default_warehouse_id',
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
            'default_warehouse_id' => 'integer',
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

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }
}
