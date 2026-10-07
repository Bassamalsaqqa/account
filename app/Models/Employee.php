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
 * @property string $name
 * @property string|null $phone
 * @property string|null $job_title
 * @property Carbon|null $hire_date
 * @property string|null $default_salary
 * @property string|null $salary_currency_code
 * @property bool $active
 * @property string|null $notes
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Employee extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'public_id',
        'company_id',
        'code',
        'name',
        'phone',
        'job_title',
        'hire_date',
        'default_salary',
        'salary_currency_code',
        'active',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'default_salary' => 'string',
            'active' => 'boolean',
            'hire_date' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Employee $employee): void {
            if (empty($employee->public_id)) {
                $employee->public_id = (string) Str::ulid();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<EmployeeAdvance, $this> */
    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class, 'employee_id');
    }

    /** @return HasMany<SalaryEntry, $this> */
    public function salaryEntries(): HasMany
    {
        return $this->hasMany(SalaryEntry::class, 'employee_id');
    }

    /** @return HasMany<SalaryPayment, $this> */
    public function salaryPayments(): HasMany
    {
        return $this->hasMany(SalaryPayment::class, 'employee_id');
    }

    public function displayName(): string
    {
        return $this->name;
    }
}
