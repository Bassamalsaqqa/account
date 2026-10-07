<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Accounting\EnsurePhase7FoundationAction;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\MoneyAccount;
use Illuminate\Support\Str;
use Tests\Feature\Phase6\Phase6TestCase;

abstract class Phase7TestCase extends Phase6TestCase
{
    protected ExpenseCategory $operatingCategory;

    protected ExpenseCategory $landedCategory;

    protected Employee $employee;

    protected MoneyAccount $cashAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashAccount = $this->ilsCashAccount;
        app(EnsurePhase7FoundationAction::class)->execute($this->company);
        setPermissionsTeamId($this->company->id);

        $this->operatingCategory = ExpenseCategory::where('company_id', $this->company->id)
            ->where('code', 'electricity')
            ->firstOrFail();

        $this->landedCategory = ExpenseCategory::where('company_id', $this->company->id)
            ->where('code', 'delivery')
            ->firstOrFail();

        $this->employee = Employee::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => (int) $this->company->id,
            'code' => 'EMP-001',
            'name' => 'أحمد العامل',
            'job_title' => 'محاسب',
            'hire_date' => '2026-01-01',
            'default_salary' => '1000.000000',
            'salary_currency_code' => 'USD',
            'active' => true,
            'created_by' => (int) $this->owner->id,
        ]);
    }
}
