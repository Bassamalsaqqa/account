<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Payroll\DeleteEmployeeAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Application\Reporting\Presentation\ReportFilterOptions;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Livewire\Pages\Reporting\ReportView;
use App\Models\Customer;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class Correction02SelectorTest extends Phase8TestCase
{
    /**
     * @param  list<string>  $permissions
     */
    private function createMemberWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

        $role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'Role_'.Str::random(10),
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo(array_unique($permissions));
        $user->assignRole($role);
        $this->activateUser($user);

        return $user;
    }

    /**
     * Seed 125 records across all six selector entity types and return the #125 records.
     *
     * @return array<string, object>
     */
    private function seed125ForAllSix(): array
    {
        // 1. 125 Customers
        $customers = [];
        for ($i = 1; $i <= 125; $i++) {
            $pad = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $customers[] = [
                'public_id' => (string) Str::ulid(),
                'company_id' => $this->company->id,
                'code' => "CUST-SEL-{$pad}",
                'name_ar' => "عميل خاص {$pad}",
                'name_en' => "Customer Sel {$pad}",
                'status' => 'active',
                'created_by' => $this->owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('customers')->insert($customers);

        // 2. 125 Vendors
        $vendors = [];
        for ($i = 1; $i <= 125; $i++) {
            $pad = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $vendors[] = [
                'public_id' => (string) Str::ulid(),
                'company_id' => $this->company->id,
                'code' => "VEND-SEL-{$pad}",
                'name_ar' => "مورد خاص {$pad}",
                'name_en' => "Vendor Sel {$pad}",
                'status' => 'active',
                'created_by' => $this->owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('vendors')->insert($vendors);

        // 3. 125 Products
        $products = [];
        for ($i = 1; $i <= 125; $i++) {
            $pad = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $products[] = [
                'public_id' => (string) Str::ulid(),
                'company_id' => $this->company->id,
                'sku' => "SKU-SEL-{$pad}",
                'name_ar' => "منتج خاص {$pad}",
                'name_en' => "Product Sel {$pad}",
                'product_type' => 'stock',
                'base_unit_id' => $this->unit->unit_id,
                'default_sale_price_base' => '10.000000',
                'created_by' => $this->owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('products')->insert($products);

        // 4. 125 Warehouses
        $warehouses = [];
        for ($i = 1; $i <= 125; $i++) {
            $pad = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $warehouses[] = [
                'public_id' => (string) Str::ulid(),
                'company_id' => $this->company->id,
                'code' => "WH-SEL-{$pad}",
                'name_ar' => "مستودع خاص {$pad}",
                'name_en' => "Warehouse Sel {$pad}",
                'active' => true,
                'created_by' => $this->owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('warehouses')->insert($warehouses);

        // 5. 125 Employees
        $employees = [];
        for ($i = 1; $i <= 125; $i++) {
            $pad = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $employees[] = [
                'public_id' => (string) Str::ulid(),
                'company_id' => $this->company->id,
                'code' => "EMP-SEL-{$pad}",
                'name' => "موظف خاص {$pad}",
                'job_title' => 'عامل',
                'hire_date' => '2026-01-01',
                'default_salary' => '1000.000000',
                'salary_currency_code' => 'ILS',
                'active' => true,
                'created_by' => $this->owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('employees')->insert($employees);

        // 6. 125 Money Accounts (with dedicated unique ledger accounts)
        $uniqueSuffix = Str::random(5);
        $ledgerAccounts = [];
        for ($i = 1; $i <= 125; $i++) {
            $pad = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $ledgerAccounts[] = [
                'public_id' => (string) Str::ulid(),
                'company_id' => $this->company->id,
                'code' => "L-{$uniqueSuffix}-{$pad}",
                'name_ar' => "حساب دفتر أستاذ {$pad}",
                'name_en' => "Ledger Account {$pad}",
                'account_type' => 'current_asset',
                'normal_balance' => 'debit',
                'is_control' => false,
                'is_system' => false,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('ledger_accounts')->insert($ledgerAccounts);
        $seededLedgerIds = DB::table('ledger_accounts')
            ->where('company_id', $this->company->id)
            ->where('code', 'LIKE', "L-{$uniqueSuffix}-%")
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $accounts = [];
        for ($i = 1; $i <= 125; $i++) {
            $pad = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $accounts[] = [
                'public_id' => (string) Str::ulid(),
                'company_id' => $this->company->id,
                'account_type' => 'cash',
                'name_ar' => "حساب نقدي خاص {$pad}",
                'name_en' => "Cash Account Sel {$pad}",
                'currency_code' => 'ILS',
                'ledger_account_id' => $seededLedgerIds[$i - 1],
                'account_number' => "ACC-SEL-{$pad}",
                'is_active' => true,
                'sort_order' => $i,
                'created_by' => $this->owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('money_accounts')->insert($accounts);

        return [
            'customer' => DB::table('customers')->where('company_id', $this->company->id)->where('code', 'CUST-SEL-125')->first(),
            'vendor' => DB::table('vendors')->where('company_id', $this->company->id)->where('code', 'VEND-SEL-125')->first(),
            'product' => DB::table('products')->where('company_id', $this->company->id)->where('sku', 'SKU-SEL-125')->first(),
            'warehouse' => DB::table('warehouses')->where('company_id', $this->company->id)->where('code', 'WH-SEL-125')->first(),
            'employee' => DB::table('employees')->where('company_id', $this->company->id)->where('code', 'EMP-SEL-125')->first(),
            'money_account' => DB::table('money_accounts')->where('company_id', $this->company->id)->where('account_number', 'ACC-SEL-125')->first(),
        ];
    }

    public function test_125_records_bounded_at_25_search_and_selected_bookmark_all_six_selectors(): void
    {
        $this->activateUser($this->owner);
        $service = app(ReportFilterOptions::class);
        $registry = app(ReportRegistry::class);

        $records = $this->seed125ForAllSix();

        // 1. customer_id (sales.by-customer)
        $salesCustDef = $registry->definition('sales.by-customer');
        $unfilteredCust = $service->forReport($this->company, $salesCustDef, []);
        $this->assertArrayHasKey('customer_id', $unfilteredCust);
        $this->assertCount(25, $unfilteredCust['customer_id'], 'Options must be bounded at 25.');
        $searchedCust = $service->forReport($this->company, $salesCustDef, [], ['customer_id' => 'SEL-125']);
        $this->assertContains((string) $records['customer']->id, array_column($searchedCust['customer_id'], 'id'));
        $bookmarkedCust = $service->forReport($this->company, $salesCustDef, ['customer_id' => (string) $records['customer']->id], ['customer_id' => 'SEL-001']);
        $this->assertSame((string) $records['customer']->id, $bookmarkedCust['customer_id'][0]['id']);

        // 2. vendor_id (purchases.by-vendor)
        $purchVendDef = $registry->definition('purchases.by-vendor');
        $unfilteredVend = $service->forReport($this->company, $purchVendDef, []);
        $this->assertArrayHasKey('vendor_id', $unfilteredVend);
        $this->assertCount(25, $unfilteredVend['vendor_id']);
        $searchedVend = $service->forReport($this->company, $purchVendDef, [], ['vendor_id' => 'SEL-125']);
        $this->assertContains((string) $records['vendor']->id, array_column($searchedVend['vendor_id'], 'id'));
        $bookmarkedVend = $service->forReport($this->company, $purchVendDef, ['vendor_id' => (string) $records['vendor']->id], ['vendor_id' => 'SEL-001']);
        $this->assertSame((string) $records['vendor']->id, $bookmarkedVend['vendor_id'][0]['id']);

        // 3. product_id (sales.by-product)
        $prodDef = $registry->definition('sales.by-product');
        $unfilteredProd = $service->forReport($this->company, $prodDef, []);
        $this->assertArrayHasKey('product_id', $unfilteredProd);
        $this->assertCount(25, $unfilteredProd['product_id']);
        $searchedProd = $service->forReport($this->company, $prodDef, [], ['product_id' => 'SEL-125']);
        $this->assertContains((string) $records['product']->id, array_column($searchedProd['product_id'], 'id'));
        $bookmarkedProd = $service->forReport($this->company, $prodDef, ['product_id' => (string) $records['product']->id], ['product_id' => 'SEL-001']);
        $this->assertSame((string) $records['product']->id, $bookmarkedProd['product_id'][0]['id']);

        // 4. warehouse_id (inventory.stock)
        $whDef = $registry->definition('inventory.stock');
        $unfilteredWh = $service->forReport($this->company, $whDef, []);
        $this->assertArrayHasKey('warehouse_id', $unfilteredWh);
        $this->assertCount(25, $unfilteredWh['warehouse_id']);
        $searchedWh = $service->forReport($this->company, $whDef, [], ['warehouse_id' => 'SEL-125']);
        $this->assertContains((string) $records['warehouse']->id, array_column($searchedWh['warehouse_id'], 'id'));
        $bookmarkedWh = $service->forReport($this->company, $whDef, ['warehouse_id' => (string) $records['warehouse']->id], ['warehouse_id' => 'SEL-001']);
        $this->assertSame((string) $records['warehouse']->id, $bookmarkedWh['warehouse_id'][0]['id']);

        // 5. employee_id (payroll.statement)
        $empDef = $registry->definition('payroll.statement');
        $unfilteredEmp = $service->forReport($this->company, $empDef, []);
        $this->assertArrayHasKey('employee_id', $unfilteredEmp);
        $this->assertCount(25, $unfilteredEmp['employee_id']);
        $searchedEmp = $service->forReport($this->company, $empDef, [], ['employee_id' => 'SEL-125']);
        $this->assertContains((string) $records['employee']->id, array_column($searchedEmp['employee_id'], 'id'));
        $bookmarkedEmp = $service->forReport($this->company, $empDef, ['employee_id' => (string) $records['employee']->id], ['employee_id' => 'SEL-001']);
        $this->assertSame((string) $records['employee']->id, $bookmarkedEmp['employee_id'][0]['id']);

        // 6. money_account_id (money.balances)
        $moneyDef = $registry->definition('money.balances');
        $unfilteredMoney = $service->forReport($this->company, $moneyDef, []);
        $this->assertArrayHasKey('money_account_id', $unfilteredMoney);
        $this->assertCount(25, $unfilteredMoney['money_account_id']);
        $searchedMoney = $service->forReport($this->company, $moneyDef, [], ['money_account_id' => 'SEL-125']);
        $this->assertContains((string) $records['money_account']->id, array_column($searchedMoney['money_account_id'], 'id'));
        $bookmarkedMoney = $service->forReport($this->company, $moneyDef, ['money_account_id' => (string) $records['money_account']->id], ['money_account_id' => 'SEL-001']);
        $this->assertSame((string) $records['money_account']->id, $bookmarkedMoney['money_account_id'][0]['id']);
    }

    public function test_livewire_all_six_selectors_search_select_apply_bookmark_and_retention(): void
    {
        $this->activateUser($this->owner);
        $records = $this->seed125ForAllSix();

        // 1. customer_id (sales.by-customer)
        Livewire::test(ReportView::class, ['reportKey' => 'sales.by-customer'])
            ->set('selectorSearch.customer_id', 'SEL-125')
            ->assertSee('عميل خاص 125')
            ->set('filters.customer_id', (string) $records['customer']->id)
            ->call('applyFilters')
            ->assertSet('filters.customer_id', (string) $records['customer']->id)
            ->set('selectorSearch.customer_id', '')
            ->assertSee('عميل خاص 125');

        // 2. vendor_id (purchases.by-vendor)
        Livewire::test(ReportView::class, ['reportKey' => 'purchases.by-vendor'])
            ->set('selectorSearch.vendor_id', 'SEL-125')
            ->assertSee('مورد خاص 125')
            ->set('filters.vendor_id', (string) $records['vendor']->id)
            ->call('applyFilters')
            ->assertSet('filters.vendor_id', (string) $records['vendor']->id)
            ->set('selectorSearch.vendor_id', '')
            ->assertSee('مورد خاص 125');

        // 3. product_id (sales.by-product)
        Livewire::test(ReportView::class, ['reportKey' => 'sales.by-product'])
            ->set('selectorSearch.product_id', 'SEL-125')
            ->assertSee('منتج خاص 125')
            ->set('filters.product_id', (string) $records['product']->id)
            ->call('applyFilters')
            ->assertSet('filters.product_id', (string) $records['product']->id)
            ->set('selectorSearch.product_id', '')
            ->assertSee('منتج خاص 125');

        // 4. warehouse_id (inventory.stock)
        Livewire::test(ReportView::class, ['reportKey' => 'inventory.stock'])
            ->set('selectorSearch.warehouse_id', 'SEL-125')
            ->assertSee('مستودع خاص 125')
            ->set('filters.warehouse_id', (string) $records['warehouse']->id)
            ->call('applyFilters')
            ->assertSet('filters.warehouse_id', (string) $records['warehouse']->id)
            ->set('selectorSearch.warehouse_id', '')
            ->assertSee('مستودع خاص 125');

        // 5. employee_id (payroll.statement)
        Livewire::test(ReportView::class, ['reportKey' => 'payroll.statement'])
            ->set('selectorSearch.employee_id', 'SEL-125')
            ->assertSee('موظف خاص 125')
            ->set('filters.employee_id', (string) $records['employee']->id)
            ->call('applyFilters')
            ->assertSet('filters.employee_id', (string) $records['employee']->id)
            ->set('selectorSearch.employee_id', '')
            ->assertSee('موظف خاص 125');

        // 6. money_account_id (money.balances)
        Livewire::test(ReportView::class, ['reportKey' => 'money.balances'])
            ->set('selectorSearch.money_account_id', 'SEL-125')
            ->assertSee('حساب نقدي خاص 125')
            ->set('filters.money_account_id', (string) $records['money_account']->id)
            ->call('applyFilters')
            ->assertSet('filters.money_account_id', (string) $records['money_account']->id)
            ->set('selectorSearch.money_account_id', '')
            ->assertSee('حساب نقدي خاص 125');
    }

    public function test_privacy_omits_unauthorized_master_selectors(): void
    {
        $service = app(ReportFilterOptions::class);
        $registry = app(ReportRegistry::class);

        // User without customers.view
        $salesUserNoCust = $this->createMemberWithPermissions(['reports.sales.view', 'sales.invoice.view']);
        $this->activateUser($salesUserNoCust);
        $summaryDef = $registry->definition('sales.summary');
        $options = $service->forReport($this->company, $summaryDef, []);
        $this->assertArrayNotHasKey('customer_id', $options, 'customer_id selector must be omitted when actor lacks customers.view.');

        // User without vendors.view
        $purchUserNoVend = $this->createMemberWithPermissions(['reports.purchases.view', 'purchasing.purchase.view', 'purchasing.cost.view']);
        $this->activateUser($purchUserNoVend);
        $purchSummaryDef = $registry->definition('purchases.summary');
        $purchOptions = $service->forReport($this->company, $purchSummaryDef, []);
        $this->assertArrayNotHasKey('vendor_id', $purchOptions, 'vendor_id selector must be omitted when actor lacks vendors.view.');

        // User without stock/product view
        $userNoProduct = $this->createMemberWithPermissions(['reports.sales.view', 'sales.invoice.view']);
        $this->activateUser($userNoProduct);
        $byProdDef = $registry->definition('sales.by-product');
        $prodOptions = $service->forReport($this->company, $byProdDef, []);
        $this->assertArrayNotHasKey('product_id', $prodOptions, 'product_id selector must be omitted when actor lacks stock/product view.');

        // Expense-only user viewing expenses.report: NO vendor master in HTML/Livewire state or options
        $expenseUser = $this->createMemberWithPermissions(['reports.expenses.view', 'money.expense.view']);
        $this->activateUser($expenseUser);
        $expDef = $registry->definition('expenses.detail');
        $expOptions = $service->forReport($this->company, $expDef, []);
        $this->assertArrayNotHasKey('vendor_id', $expOptions, 'Expense user must not receive vendor selector options.');
    }

    public function test_cross_company_isolation_in_master_search(): void
    {
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة أجنبية',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($otherCompany, $otherOwner);
        $foreignCustomer = Customer::create([
            'company_id' => $otherCompany->id,
            'name_ar' => 'عميل شركة أجنبية',
            'code' => 'FOREIGN-CUST',
            'status' => 'active',
            'created_by' => $otherOwner->id,
        ]);

        $this->activateUser($this->owner);
        $service = app(ReportFilterOptions::class);
        $salesCustDef = app(ReportRegistry::class)->definition('sales.by-customer');

        // Search for foreign customer in this company
        $result = $service->forReport($this->company, $salesCustDef, [], ['customer_id' => 'FOREIGN-CUST']);
        $this->assertArrayHasKey('customer_id', $result);
        $this->assertEmpty($result['customer_id'], 'Foreign company master records must never be returned.');

        // Tampering attempt: select foreign ID
        $tampered = $service->forReport($this->company, $salesCustDef, ['customer_id' => (string) $foreignCustomer->id], []);
        $this->assertArrayHasKey('customer_id', $tampered);
        $ids = array_column($tampered['customer_id'], 'id');
        $this->assertNotContains((string) $foreignCustomer->id, $ids, 'Foreign company ID in selected state must not be retained.');
    }

    public function test_malformed_search_input_validation_and_livewire_safety(): void
    {
        $this->activateUser($this->owner);
        $service = app(ReportFilterOptions::class);
        $definition = app(ReportRegistry::class)->definition('sales.by-customer');

        // 1. Array term in service throws InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $service->forReport($this->company, $definition, [], ['customer_id' => ['tampered']]);
    }

    public function test_malformed_search_input_string_length_and_field_validation(): void
    {
        $this->activateUser($this->owner);
        $service = app(ReportFilterOptions::class);
        $definition = app(ReportRegistry::class)->definition('sales.by-customer');

        // 1. Term exceeding 100 chars
        try {
            $service->forReport($this->company, $definition, [], ['customer_id' => str_repeat('a', 101)]);
            $this->fail('Expected InvalidArgumentException for search term > 100 chars.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('maximum length', $e->getMessage());
        }

        // 2. Unsupported search field
        try {
            $service->forReport($this->company, $definition, [], ['non_existent_field' => 'test']);
            $this->fail('Expected InvalidArgumentException for invalid search field.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid search filter field', $e->getMessage());
        }

        // 3. Livewire component catches InvalidArgumentException gracefully without 500
        Livewire::test(ReportView::class, ['reportKey' => 'sales.by-customer'])
            ->set('selectorSearch.customer_id', ['array_value_tampering'])
            ->assertHasErrors('selectorSearch');
    }

    public function test_live_revocation_removes_selector_or_aborts_component(): void
    {
        $user = $this->createMemberWithPermissions([
            'reports.sales.view',
            'sales.invoice.view',
            'customers.view',
        ]);
        $this->activateUser($user);

        // Component renders customer selector
        Livewire::test(ReportView::class, ['reportKey' => 'sales.by-customer'])
            ->assertSee('report-customer_id');

        // Revoke customers.view
        setPermissionsTeamId($this->company->id);
        $user->roles->first()->revokePermissionTo('customers.view');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        // Next render omits customer selector
        Livewire::test(ReportView::class, ['reportKey' => 'sales.by-customer'])
            ->assertDontSee('report-customer_id');

        // Deactivate membership -> next render aborts with 403
        $this->company->memberships()->where('user_id', $user->id)->update(['status' => 'inactive']);
        Livewire::test(ReportView::class, ['reportKey' => 'sales.by-customer'])
            ->assertStatus(403);
    }

    public function test_archived_employee_remains_bookmark_resolvable_in_payroll_report(): void
    {
        $this->activateUser($this->owner);

        app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'recognition_date' => '2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'base_salary' => '100.000000',
            'idempotency_key' => 'archived-selector-salary-permanent',
        ]);

        app(DeleteEmployeeAction::class)->execute($this->employee, $this->owner);
        $this->assertTrue($this->employee->fresh()->trashed());

        $options = app(ReportFilterOptions::class)->forReport(
            $this->company,
            app(ReportRegistry::class)->definition('payroll.statement'),
            ['employee_id' => (string) $this->employee->id]
        );

        $this->assertContains((string) $this->employee->id, array_column($options['employee_id'] ?? [], 'id'));
    }
}
