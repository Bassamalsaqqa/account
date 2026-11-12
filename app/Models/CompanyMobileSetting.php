<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $company_id
 * @property int|null $walk_in_customer_id
 * @property int|null $approved_cash_account_id
 * @property int|null $default_warehouse_id
 * @property int|null $default_tax_rate_id
 * @property bool $offline_policy_enabled
 * @property bool $offline_cash_only
 * @property int $max_offline_hours
 * @property int $max_offline_sales_count
 * @property string $max_offline_total_amount
 * @property string $catalog_snapshot_version
 */
class CompanyMobileSetting extends Model
{
    protected $primaryKey = 'company_id';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'walk_in_customer_id',
        'approved_cash_account_id',
        'default_warehouse_id',
        'default_tax_rate_id',
        'offline_policy_enabled',
        'offline_cash_only',
        'max_offline_hours',
        'max_offline_sales_count',
        'max_offline_total_amount',
        'catalog_snapshot_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'offline_policy_enabled' => 'boolean',
            'offline_cash_only' => 'boolean',
            'max_offline_hours' => 'integer',
            'max_offline_sales_count' => 'integer',
            'max_offline_total_amount' => 'string',
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
     * @return BelongsTo<Customer, $this>
     */
    public function walkInCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'walk_in_customer_id');
    }

    /**
     * @return BelongsTo<MoneyAccount, $this>
     */
    public function approvedCashAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class, 'approved_cash_account_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function defaultTaxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'default_tax_rate_id');
    }
}
