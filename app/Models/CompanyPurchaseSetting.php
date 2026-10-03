<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $company_id
 * @property int|null $default_payment_terms_days
 * @property int|null $default_receiving_warehouse_id
 * @property bool $warn_duplicate_vendor_invoice
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CompanyPurchaseSetting extends Model
{
    use BelongsToCompany;

    protected $primaryKey = 'company_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'company_id',
        'default_payment_terms_days',
        'default_receiving_warehouse_id',
        'warn_duplicate_vendor_invoice',
    ];

    protected function casts(): array
    {
        return [
            'warn_duplicate_vendor_invoice' => 'boolean',
            'default_payment_terms_days' => 'integer',
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
    public function defaultReceivingWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_receiving_warehouse_id');
    }
}
