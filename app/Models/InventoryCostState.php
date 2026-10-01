<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCostState extends Model
{
    use BelongsToCompany;

    public const CREATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'product_id',
        'quantity_base',
        'average_cost_base',
        'inventory_value_base',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_base' => 'string',
            'average_cost_base' => 'string',
            'inventory_value_base' => 'string',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
