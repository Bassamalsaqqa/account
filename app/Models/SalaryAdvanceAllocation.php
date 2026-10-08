<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Services\Phase7\HasCanonicalPhase7History;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property int $salary_entry_id
 * @property int $employee_advance_id
 * @property string $allocated_amount
 * @property string $advance_base_consumed
 * @property string $salary_base_relief
 * @property string $realized_fx_gain_loss_base
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $reversed_at
 * @property-read SalaryEntry $salaryEntry
 * @property-read EmployeeAdvance $employeeAdvance
 */
class SalaryAdvanceAllocation extends Model
{
    use BelongsToCompany;
    use HasCanonicalPhase7History;

    public $timestamps = false;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'public_id',
        'company_id',
        'salary_entry_id',
        'employee_advance_id',
        'allocated_amount',
        'advance_base_consumed',
        'salary_base_relief',
        'realized_fx_gain_loss_base',
        'status',
        'created_at',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'string',
            'advance_base_consumed' => 'string',
            'salary_base_relief' => 'string',
            'realized_fx_gain_loss_base' => 'string',
            'created_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SalaryAdvanceAllocation $allocation): void {
            if (empty($allocation->public_id)) {
                $allocation->public_id = (string) Str::ulid();
            }
            if (empty($allocation->created_at)) {
                $allocation->created_at = now();
            }
        });

        static::deleting(function (SalaryAdvanceAllocation $allocation): void {
            throw new ImmutableRecordException('Salary advance allocations cannot be deleted; mark reversed under canonical control.');
        });
    }

    /** @return BelongsTo<SalaryEntry, $this> */
    public function salaryEntry(): BelongsTo
    {
        return $this->belongsTo(SalaryEntry::class, 'salary_entry_id');
    }

    /** @return BelongsTo<EmployeeAdvance, $this> */
    public function employeeAdvance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
