<?php

declare(strict_types=1);

namespace Tests\Feature\Phase3;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\EnsureDefaultUnitsAction;
use App\Actions\Inventory\EnsureDefaultWarehouseAction;
use App\Models\Company;
use App\Models\CompanyInventorySettings;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyInventoryProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->actingAs($this->user);
    }

    public function test_creating_company_provisions_default_units_warehouse_and_inventory_settings(): void
    {
        $creator = app(CreateCompanyAction::class);
        $company = $creator->execute($this->user, [
            'name_ar' => 'شركة تجهيز المخزون الأولى',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($company, $this->user);

        // 1. Verify 9 standard units seeded
        $units = Unit::where('company_id', $company->id)->get();
        $this->assertCount(9, $units);

        $expectedCodes = ['bottle', 'box', 'carton', 'g', 'kg', 'liter', 'meter', 'pack', 'piece'];
        $actualCodes = $units->pluck('code')->all();
        sort($expectedCodes);
        sort($actualCodes);
        $this->assertSame($expectedCodes, $actualCodes);

        // Verify fractional constraints
        $piece = $units->firstWhere('code', 'piece');
        $this->assertNotNull($piece);
        $this->assertFalse($piece->allows_fraction);

        $kg = $units->firstWhere('code', 'kg');
        $this->assertNotNull($kg);
        $this->assertTrue($kg->allows_fraction);

        // 2. Verify default warehouse
        $warehouses = Warehouse::where('company_id', $company->id)->get();
        $this->assertCount(1, $warehouses);
        $defaultWh = $warehouses->first();
        $this->assertTrue($defaultWh->is_default);
        $this->assertSame('WH-MAIN', $defaultWh->code);
        $this->assertSame($this->user->id, $defaultWh->created_by);

        // 3. Verify company inventory settings
        $settings = CompanyInventorySettings::where('company_id', $company->id)->first();
        $this->assertNotNull($settings);
        $this->assertSame($defaultWh->id, $settings->default_warehouse_id);
    }

    public function test_provisioning_actions_are_idempotent(): void
    {
        $creator = app(CreateCompanyAction::class);
        $company = $creator->execute($this->user, [
            'name_ar' => 'شركة فحص التكرار',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($company, $this->user);

        // Re-execute default units action
        app(EnsureDefaultUnitsAction::class)->execute($company);
        $this->assertSame(9, Unit::where('company_id', $company->id)->count());

        // Re-execute default warehouse action
        $wh = app(EnsureDefaultWarehouseAction::class)->execute($company, $this->user->id);
        $this->assertSame(1, Warehouse::where('company_id', $company->id)->count());
        $this->assertTrue($wh->is_default);
    }

    public function test_failed_company_creation_rolls_back_all_provisioned_records(): void
    {
        $initialCompanies = Company::count();
        $initialUnits = Unit::count();
        $initialWarehouses = Warehouse::count();

        $creator = app(CreateCompanyAction::class);

        try {
            // Pass an invalid currency code that violates database or validation constraints
            $creator->execute($this->user, [
                'name_ar' => 'شركة فاشلة',
                'base_currency_code' => 'INVALID_CURRENCY_CODE_OVERFLOW',
            ]);
            $this->fail('Expected exception was not thrown.');
        } catch (\Throwable) {
            // Expected
        }

        $this->assertSame($initialCompanies, Company::count());
        $this->assertSame($initialUnits, Unit::count());
        $this->assertSame($initialWarehouses, Warehouse::count());
    }
}
