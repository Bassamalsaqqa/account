<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RecordsCanonicalMoneyHistory;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property string $public_id
 * @property string $direction
 * @property string $check_number
 * @property int|null $customer_id
 * @property int|null $vendor_id
 * @property int|null $drawn_money_account_id
 * @property string $currency_code
 * @property string $base_currency_code
 * @property string $amount
 * @property string $exchange_rate
 * @property string $amount_base
 * @property Carbon $received_issued_date
 * @property Carbon $due_date
 * @property string $status
 * @property string $request_hash
 * @property int $created_by
 */
final class Check extends Model
{
    use BelongsToCompany, RecordsCanonicalMoneyHistory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['received_issued_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'party_snapshot' => 'array', 'bank_snapshot' => 'array', 'request_payload' => 'array'];
    }

    /** @return list<string> */
    protected function completionFields(): array
    {
        return ['company_id', 'status'];
    }

    /** @return HasMany<CheckEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CheckEvent::class)->orderBy('id');
    }

    /** @return HasOne<CustomerPayment, $this> */
    public function customerPayment(): HasOne
    {
        return $this->hasOne(CustomerPayment::class);
    }

    /** @return HasOne<VendorPayment, $this> */
    public function vendorPayment(): HasOne
    {
        return $this->hasOne(VendorPayment::class);
    }

    /** @return HasOne<Expense, $this> */
    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class);
    }

    /** @return HasOne<EmployeeAdvance, $this> */
    public function employeeAdvance(): HasOne
    {
        return $this->hasOne(EmployeeAdvance::class);
    }

    /** @return HasOne<SalaryPayment, $this> */
    public function salaryPayment(): HasOne
    {
        return $this->hasOne(SalaryPayment::class);
    }
}
