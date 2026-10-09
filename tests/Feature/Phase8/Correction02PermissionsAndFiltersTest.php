<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Inventory\PostOpeningStockAction;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Presentation\DashboardReports;
use App\Application\Reporting\Presentation\ReportFilterOptions;
use App\Application\Reporting\Presentation\ReportPresenter;
use App\Application\Reporting\Presentation\ReportRegistry;
use App\Application\Reporting\Presentation\ReportSourceNavigation;
use App\Application\Reporting\Queries\MoneyMovementReportQuery;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class Correction02PermissionsAndFiltersTest extends TradeTestCase
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

    public function test_all_69_reports_allow_minimal_permissions_and_deny_when_one_revoked(): void
    {
        $registry = app(ReportRegistry::class);
        $all = $registry->all();
        $this->assertCount(69, $all, 'Registry must define exactly 69 report variants.');

        $identities = [
            'customer_id' => $this->defaultCustomer->id,
            'vendor_id' => $this->vendor->id,
            'product_id' => $this->product->id,
            'employee_id' => $this->employee->id,
            'money_account_id' => $this->ilsCashAccount->id,
        ];

        foreach ($all as $key => $def) {
            $perms = $def['permissions'] ?? [];
            $this->assertNotEmpty($perms, "Report {$key} must declare required permissions.");

            $minimalPerms = $perms;
            // Any-of / special allowances
            if (in_array($key, ['money.balances', 'money.movements'], true)) {
                $minimalPerms[] = 'money.cash.view';
            }
            if ($def['query'] === 'App\\Application\\Reporting\\Queries\\MoneyVendorPaymentReportQuery') {
                $minimalPerms[] = 'purchasing.cost.view';
                $minimalPerms[] = 'vendors.statement.view';
            }
            if (in_array($key, ['payroll.advances', 'payroll.outstanding-advances'], true)) {
                $minimalPerms[] = 'payroll.advance.manage';
            }

            // Create user with this minimal set
            $authorizedUser = $this->createMemberWithPermissions($minimalPerms);
            $this->activateUser($authorizedUser);

            $this->assertTrue(
                $registry->allows($this->company, $key),
                "Report {$key} should be allowed for user with minimal permissions: ".implode(', ', $minimalPerms)
            );

            // Execute real query under minimal authorized role
            $input = $def['current'] ? [] : ['from' => '2026-10-01', 'to' => '2026-10-31'];
            foreach ($def['required'] as $field) {
                $input[$field] = $identities[$field];
            }

            $result = $registry->execute($this->company, $key, $input);
            $this->assertInstanceOf(
                ReportResult::class,
                $result,
                "Report {$key} execution must return ReportResult for authorized minimal role."
            );

            // Test revoking the first permission
            $firstPerm = $minimalPerms[0];
            $restrictedPerms = array_values(array_filter($minimalPerms, fn ($p) => $p !== $firstPerm));
            $unauthorizedUser = $this->createMemberWithPermissions($restrictedPerms);
            $this->activateUser($unauthorizedUser);

            $this->assertFalse(
                $registry->allows($this->company, $key),
                "Report {$key} should be denied when {$firstPerm} is revoked."
            );

            $caught = false;
            try {
                $registry->execute($this->company, $key, $input);
            } catch (AuthorizationException) {
                $caught = true;
            }
            $this->assertTrue($caught, "Report {$key} execute must throw AuthorizationException when {$firstPerm} is missing.");
        }
    }

    public function test_master_view_prerequisites_enforced(): void
    {
        $registry = app(ReportRegistry::class);

        // 1. sales.by-customer requires customers.view
        $userWithoutCust = $this->createMemberWithPermissions(['reports.sales.view', 'sales.invoice.view']);
        $this->activateUser($userWithoutCust);
        $this->assertFalse($registry->allows($this->company, 'sales.by-customer'));
        $userWithCust = $this->createMemberWithPermissions(['reports.sales.view', 'sales.invoice.view', 'customers.view']);
        $this->activateUser($userWithCust);
        $this->assertTrue($registry->allows($this->company, 'sales.by-customer'));

        // 2. sales.returns requires sales.return.view
        $userWithoutRet = $this->createMemberWithPermissions(['reports.sales.view']);
        $this->activateUser($userWithoutRet);
        $this->assertFalse($registry->allows($this->company, 'sales.returns'));
        $userWithRet = $this->createMemberWithPermissions(['reports.sales.view', 'sales.return.view']);
        $this->activateUser($userWithRet);
        $this->assertTrue($registry->allows($this->company, 'sales.returns'));

        // 3. customers.overdue requires customers.view
        $this->activateUser($userWithoutCust);
        $this->assertFalse($registry->allows($this->company, 'customers.overdue'));
        $userWithCustOverdue = $this->createMemberWithPermissions(['reports.sales.view', 'sales.invoice.view', 'customers.view']);
        $this->activateUser($userWithCustOverdue);
        $this->assertTrue($registry->allows($this->company, 'customers.overdue'));

        // 4. customers.product-history requires customers.view
        $this->activateUser($userWithoutCust);
        $this->assertFalse($registry->allows($this->company, 'customers.product-history'));
        $this->activateUser($userWithCust);
        $this->assertTrue($registry->allows($this->company, 'customers.product-history'));

        // 5. purchases.by-vendor requires vendors.view
        $userWithoutVend = $this->createMemberWithPermissions(['reports.purchases.view', 'purchasing.purchase.view', 'purchasing.cost.view']);
        $this->activateUser($userWithoutVend);
        $this->assertFalse($registry->allows($this->company, 'purchases.by-vendor'));
        $userWithVend = $this->createMemberWithPermissions(['reports.purchases.view', 'purchasing.purchase.view', 'purchasing.cost.view', 'vendors.view']);
        $this->activateUser($userWithVend);
        $this->assertTrue($registry->allows($this->company, 'purchases.by-vendor'));

        // 6. vendors.product-history requires vendors.view
        $this->activateUser($userWithoutVend);
        $this->assertFalse($registry->allows($this->company, 'vendors.product-history'));
        $this->activateUser($userWithVend);
        $this->assertTrue($registry->allows($this->company, 'vendors.product-history'));

        // 7. inventory.vendor-products requires purchasing.purchase.view
        $userWithoutPurch = $this->createMemberWithPermissions(['reports.inventory.view', 'inventory.stock.view']);
        $this->activateUser($userWithoutPurch);
        $this->assertFalse($registry->allows($this->company, 'inventory.vendor-products'));
        $userWithPurch = $this->createMemberWithPermissions(['reports.inventory.view', 'inventory.stock.view', 'purchasing.purchase.view']);
        $this->activateUser($userWithPurch);
        $this->assertTrue($registry->allows($this->company, 'inventory.vendor-products'));
    }

    public function test_money_vendor_payment_any_of_authorization(): void
    {
        $registry = app(ReportRegistry::class);

        // Path A: vendors.statement.view alone (plus reports.money.view and purchasing.cost.view)
        $userA = $this->createMemberWithPermissions(['reports.money.view', 'purchasing.cost.view', 'vendors.statement.view']);
        $this->activateUser($userA);
        $this->assertTrue($registry->allows($this->company, 'money.vendor-payments'));

        // Path B: money.vendor_payment.create (plus reports.money.view and purchasing.cost.view)
        $userB = $this->createMemberWithPermissions(['reports.money.view', 'purchasing.cost.view', 'money.vendor_payment.create']);
        $this->activateUser($userB);
        $this->assertTrue($registry->allows($this->company, 'money.vendor-payments'));

        // Insufficient: only reports.money.view and money.vendor_payment.create (missing purchasing.cost.view)
        $userC = $this->createMemberWithPermissions(['reports.money.view', 'money.vendor_payment.create']);
        $this->activateUser($userC);
        $this->assertFalse($registry->allows($this->company, 'money.vendor-payments'));
    }

    public function test_payroll_advances_any_of_authorization(): void
    {
        $registry = app(ReportRegistry::class);

        // Path A: payroll.salary.view (plus reports.payroll.view and employees.view)
        $userA = $this->createMemberWithPermissions(['reports.payroll.view', 'employees.view', 'payroll.salary.view']);
        $this->activateUser($userA);
        $this->assertTrue($registry->allows($this->company, 'payroll.advances'));
        $this->assertTrue($registry->allows($this->company, 'payroll.outstanding-advances'));

        // Path B: payroll.advance.manage (plus reports.payroll.view and employees.view)
        $userB = $this->createMemberWithPermissions(['reports.payroll.view', 'employees.view', 'payroll.advance.manage']);
        $this->activateUser($userB);
        $this->assertTrue($registry->allows($this->company, 'payroll.advances'));
        $this->assertTrue($registry->allows($this->company, 'payroll.outstanding-advances'));

        // Insufficient: reports.payroll.view and employees.view (missing both salary.view and advance.manage)
        $userC = $this->createMemberWithPermissions(['reports.payroll.view', 'employees.view']);
        $this->activateUser($userC);
        $this->assertFalse($registry->allows($this->company, 'payroll.advances'));
        $this->assertFalse($registry->allows($this->company, 'payroll.outstanding-advances'));
    }

    public function test_cash_vs_bank_account_type_authority(): void
    {
        $cashUser = $this->createMemberWithPermissions(['reports.money.view', 'money.cash.view']);
        $bankUser = $this->createMemberWithPermissions(['reports.money.view', 'money.bank.view']);

        $optionsService = app(ReportFilterOptions::class);
        $registry = app(ReportRegistry::class);
        $definition = $registry->definition('money.movements');

        // Cash user sees only cash account in filter options
        $this->activateUser($cashUser);
        $cashOptions = $optionsService->forReport($this->company, $definition, []);
        $this->assertArrayHasKey('money_account_id', $cashOptions);
        $cashIds = array_column($cashOptions['money_account_id'], 'id');
        $this->assertContains((string) $this->ilsCashAccount->id, $cashIds);
        $this->assertNotContains((string) $this->usdBankAccount->id, $cashIds);

        // Bank user sees only bank account in filter options
        $this->activateUser($bankUser);
        $bankOptions = $optionsService->forReport($this->company, $definition, []);
        $this->assertArrayHasKey('money_account_id', $bankOptions);
        $bankIds = array_column($bankOptions['money_account_id'], 'id');
        $this->assertContains((string) $this->usdBankAccount->id, $bankIds);
        $this->assertNotContains((string) $this->ilsCashAccount->id, $bankIds);

        // Cash user running money.movements directly against bank account fails closed
        $this->activateUser($cashUser);
        $query = app(MoneyMovementReportQuery::class);
        $this->expectException(AuthorizationException::class);
        $query->execute($this->company, ['money_account_id' => $this->usdBankAccount->id]);
    }

    public function test_removed_unsupported_filter_options(): void
    {
        $registry = app(ReportRegistry::class);

        // 1. money.movements must not declare currency_code in filters
        $moneyMovements = $registry->definition('money.movements');
        $this->assertNotContains('currency_code', $moneyMovements['filters'], 'money.movements must not declare currency_code filter.');

        // 2. inventory.stock must not declare status
        $stock = $registry->definition('inventory.stock');
        $this->assertNotContains('status', $stock['filters'], 'inventory.stock must not declare status filter.');

        // 3. inventory.by-warehouse must not declare status
        $byWh = $registry->definition('inventory.by-warehouse');
        $this->assertNotContains('status', $byWh['filters'], 'inventory.by-warehouse must not declare status filter.');

        // 4. inventory.valuation must not declare status
        $valuation = $registry->definition('inventory.valuation');
        $this->assertNotContains('status', $valuation['filters'], 'inventory.valuation must not declare status filter.');
    }

    public function test_same_user_permission_and_membership_revocation_on_next_request(): void
    {
        $registry = app(ReportRegistry::class);
        $user = $this->createMemberWithPermissions(['reports.sales.view', 'sales.invoice.view']);
        $this->activateUser($user);

        // 1. Initial request succeeds
        $res = $registry->execute($this->company, 'sales.summary', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertInstanceOf(ReportResult::class, $res);
        $this->get(route('reports.show', ['reportKey' => 'sales.summary']))->assertOk();
        $this->get(route('reports.export', ['reportKey' => 'sales.summary']))->assertOk();

        // 2. Revoke permission from role on next request
        /** @var Role $role */
        $role = $user->roles->first();
        $role->revokePermissionTo('reports.sales.view');
        $user->unsetRelation('roles')->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($registry->allows($this->company, 'sales.summary'));
        $this->get(route('reports.show', ['reportKey' => 'sales.summary']))->assertStatus(403);
        $this->get(route('reports.export', ['reportKey' => 'sales.summary']))->assertStatus(403);

        $caught = false;
        try {
            $registry->execute($this->company, 'sales.summary', []);
        } catch (AuthorizationException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'Direct query execution must fail closed after permission revocation.');

        // 3. Restore permission, revoke membership status
        $role->givePermissionTo('reports.sales.view');
        $user->unsetRelation('roles')->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($registry->allows($this->company, 'sales.summary'));

        CompanyUser::where('company_id', $this->company->id)->where('user_id', $user->id)->update(['status' => 'inactive']);

        $this->assertFalse($registry->allows($this->company, 'sales.summary'));
        $showResponse = $this->get(route('reports.show', ['reportKey' => 'sales.summary']));
        $this->assertTrue($showResponse->isRedirect() || $showResponse->status() === 403, 'Inactive membership must redirect or fail 403 on view.');
        $exportResponse = $this->get(route('reports.export', ['reportKey' => 'sales.summary']));
        $this->assertTrue($exportResponse->isRedirect() || $exportResponse->status() === 403, 'Inactive membership must redirect or fail 403 on export.');

        $caught = false;
        try {
            $registry->execute($this->company, 'sales.summary', []);
        } catch (AuthorizationException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'Direct query execution must fail closed after membership inactivation.');

        // 4. Restore membership, suspend company
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $user->id)->update(['status' => 'active']);
        $this->company->update(['status' => 'suspended']);

        $this->assertFalse($registry->allows($this->company, 'sales.summary'));
        $showResponseSusp = $this->get(route('reports.show', ['reportKey' => 'sales.summary']));
        $this->assertTrue($showResponseSusp->isRedirect() || $showResponseSusp->status() === 403, 'Suspended company must redirect or fail 403 on view.');
        $exportResponseSusp = $this->get(route('reports.export', ['reportKey' => 'sales.summary']));
        $this->assertTrue($exportResponseSusp->isRedirect() || $exportResponseSusp->status() === 403, 'Suspended company must redirect or fail 403 on export.');

        // Restore company
        $this->company->update(['status' => 'active']);
    }

    public function test_cost_and_profit_redaction_across_rows_totals_columns_and_csv(): void
    {
        $this->activateUser($this->owner);
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of('100.000000'),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'cost-redact-stock-'.Str::random(6),
            movementDate: '2026-10-01',
        );

        $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'بند لتجربة التعتيم',
                    'quantity' => '2.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);

        $registry = app(ReportRegistry::class);
        $presenter = app(ReportPresenter::class);
        $def = $registry->definition('sales.by-product');

        // User A: Restricted (sales view only, no cost authority)
        $restrictedUser = $this->createMemberWithPermissions(['reports.sales.view', 'sales.invoice.view']);
        $this->activateUser($restrictedUser);

        $resultRestricted = $registry->execute($this->company, 'sales.by-product', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertNotEmpty($resultRestricted->rows);

        // Row checks: no cogs_base, gross_profit_base, gross_margin
        foreach ($resultRestricted->rows as $row) {
            $this->assertArrayNotHasKey('cogs_base', $row, 'Restricted row must not contain cogs_base.');
            $this->assertArrayNotHasKey('gross_profit_base', $row, 'Restricted row must not contain gross_profit_base.');
            $this->assertArrayNotHasKey('gross_margin', $row, 'Restricted row must not contain gross_margin.');
        }

        // Totals checks: no cogs_base, gross_profit_base, gross_margin
        $this->assertArrayNotHasKey('cogs_base', $resultRestricted->totals, 'Restricted totals must not contain cogs_base.');
        $this->assertArrayNotHasKey('gross_profit_base', $resultRestricted->totals, 'Restricted totals must not contain gross_profit_base.');
        $this->assertArrayNotHasKey('gross_margin', $resultRestricted->totals, 'Restricted totals must not contain gross_margin.');

        // Presenter columns checks: no cost/profit columns
        $columnsRestricted = $presenter->columns($def['columns'], $resultRestricted);
        $restrictedColKeys = array_column($columnsRestricted, 'key');
        $this->assertNotContains('cogs_base', $restrictedColKeys, 'Restricted columns must not project cogs_base.');
        $this->assertNotContains('gross_profit_base', $restrictedColKeys, 'Restricted columns must not project gross_profit_base.');
        $this->assertNotContains('gross_margin', $restrictedColKeys, 'Restricted columns must not project gross_margin.');

        // CSV export check: headers and cells do not contain cost/profit
        $csvRestricted = $this->get(route('reports.export', ['reportKey' => 'sales.by-product']))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('cogs_base', $csvRestricted);
        $this->assertStringNotContainsString('تكلفة المبيعات', $csvRestricted);
        $this->assertStringNotContainsString('مجمل الربح', $csvRestricted);

        // Filter sort by profit_desc fails with InvalidReportFilterException
        try {
            $registry->execute($this->company, 'sales.by-product', ['sort' => 'profit_desc']);
            $this->fail('Profit sorting must require cost view authority.');
        } catch (InvalidReportFilterException $e) {
            $this->assertStringContainsString('sort', $e->getMessage());
        }

        // User B: Full Cost Authority
        $costUser = $this->createMemberWithPermissions([
            'reports.sales.view',
            'sales.invoice.view',
            'reports.cost.view',
            'reports.profit.view',
            'inventory.cost.view',
        ]);
        $this->activateUser($costUser);

        $resultCost = $registry->execute($this->company, 'sales.by-product', ['from' => '2026-10-01', 'to' => '2026-10-31', 'sort' => 'profit_desc']);
        $this->assertNotEmpty($resultCost->rows);

        foreach ($resultCost->rows as $row) {
            $this->assertArrayHasKey('cogs_base', $row, 'Cost user row must contain cogs_base.');
            $this->assertArrayHasKey('gross_profit_base', $row, 'Cost user row must contain gross_profit_base.');
        }

        $this->assertArrayHasKey('cogs_base', $resultCost->totals, 'Cost user totals must contain cogs_base.');
        $this->assertArrayHasKey('gross_profit_base', $resultCost->totals, 'Cost user totals must contain gross_profit_base.');

        $columnsCost = $presenter->columns($def['columns'], $resultCost);
        $costColKeys = array_column($columnsCost, 'key');
        $this->assertContains('cogs_base', $costColKeys, 'Cost user columns must contain cogs_base.');
        $this->assertContains('gross_profit_base', $costColKeys, 'Cost user columns must contain gross_profit_base.');
    }

    public function test_hub_and_dashboard_availability_agrees_with_query_and_csv(): void
    {
        $salesUser = $this->createMemberWithPermissions(['reports.sales.view', 'sales.invoice.view']);
        $this->activateUser($salesUser);

        $registry = app(ReportRegistry::class);
        $visibleKeys = array_keys($registry->visible($this->company));

        // Sales user sees sales reports, but not purchasing or payroll
        $this->assertContains('sales.summary', $visibleKeys);
        $this->assertNotContains('purchases.summary', $visibleKeys);
        $this->assertNotContains('payroll.summary', $visibleKeys);
        $this->assertNotContains('inventory.valuation', $visibleKeys);

        // Dashboard availability
        $dashboard = app(DashboardReports::class)->read($this->company, 'this_month');
        $this->assertArrayHasKey('sales.summary', $dashboard['activity']);
        $this->assertArrayNotHasKey('purchases.summary', $dashboard['activity']);
        $this->assertArrayNotHasKey('profit', $dashboard['activity']);
        $this->assertArrayNotHasKey('inventory.valuation', $dashboard['positions']);
        $this->assertArrayNotHasKey('payroll.summary', $dashboard['positions']);

        // Denied report fails closed across query and CSV
        $caught = false;
        try {
            $registry->execute($this->company, 'purchases.summary', []);
        } catch (AuthorizationException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'Denied purchases.summary must throw AuthorizationException.');
        $this->get(route('reports.export', ['reportKey' => 'purchases.summary']))->assertStatus(403);
        $this->get(route('reports.show', ['reportKey' => 'purchases.summary']))->assertStatus(403);

        // Allowed report succeeds across query and CSV
        $res = $registry->execute($this->company, 'sales.summary', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertInstanceOf(ReportResult::class, $res);
        $this->get(route('reports.export', ['reportKey' => 'sales.summary']))->assertOk();
        $this->get(route('reports.show', ['reportKey' => 'sales.summary']))->assertOk();
    }

    public function test_all_69_declared_filters_and_defaults_match_query_semantics(): void
    {
        $registry = app(ReportRegistry::class);
        foreach ($registry->all() as $key => $def) {
            $queryClass = $def['query'];
            $ref = new ReflectionClass($queryClass);
            $this->assertTrue($ref->hasMethod('execute'), "Query {$queryClass} must have execute method.");

            // If query declares SUPPORTED_FILTERS, all declared filters must match
            if ($ref->hasConstant('SUPPORTED_FILTERS')) {
                $supported = $ref->getConstant('SUPPORTED_FILTERS');
                $declared = $def['filters'] ?? [];
                $diff = array_diff($declared, $supported);
                $this->assertSame(
                    [],
                    $diff,
                    "Report {$key} declared filters (".implode(', ', $diff).') must be in query SUPPORTED_FILTERS.'
                );
            }

            // Defaults must only contain declared filter keys or pagination keys
            $defaults = $def['defaults'] ?? [];
            foreach (array_keys($defaults) as $defaultKey) {
                $this->assertTrue(
                    in_array($defaultKey, $def['filters'], true) || in_array($defaultKey, ['page', 'per_page', 'period'], true),
                    "Report {$key} default {$defaultKey} must be in declared filters or standard parameters."
                );
            }

            // Required filters must be declared
            $required = $def['required'] ?? [];
            foreach ($required as $reqField) {
                $this->assertContains(
                    $reqField,
                    $def['filters'],
                    "Report {$key} required field {$reqField} must be in declared filters."
                );
            }

            // Current-only reports must not require historical dates
            if ($def['current'] ?? false) {
                $this->assertNotContains('from', $required, "Current report {$key} must not require from date.");
                $this->assertNotContains('to', $required, "Current report {$key} must not require to date.");
            }
        }
    }

    public function test_every_advertised_option_value_executes_its_registered_query(): void
    {
        $this->activateUser($this->owner);
        $registry = app(ReportRegistry::class);
        $identities = [
            'customer_id' => $this->defaultCustomer->id,
            'vendor_id' => $this->vendor->id,
            'product_id' => $this->product->id,
            'employee_id' => $this->employee->id,
            'money_account_id' => $this->ilsCashAccount->id,
        ];
        $failures = [];
        foreach ($registry->all() as $key => $definition) {
            $baseInput = $definition['current'] ? [] : ['from' => '2026-10-01', 'to' => '2026-10-31'];
            foreach ($definition['required'] as $field) {
                $baseInput[$field] = $identities[$field];
            }
            foreach ($definition['options'] as $field => $values) {
                $this->assertContains($field, $definition['filters'], "{$key}: undeclared option field");
                foreach ($values as $value) {
                    try {
                        $result = $registry->execute($this->company, $key, array_replace($baseInput, [$field => $value]));
                        $this->assertInstanceOf(ReportResult::class, $result, "{$key}: {$field}={$value}");
                    } catch (\Throwable $error) {
                        $failures[] = "{$key}: {$field}={$value}: ".$error->getMessage();
                    }
                }
            }
        }
        $this->assertSame([], $failures);
    }

    public function test_sensitive_source_navigation_is_independently_authorized(): void
    {
        $this->activateUser($this->owner);
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of('100.000000'),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'nav-test-stock-'.Str::random(6),
            movementDate: '2026-10-01',
        );

        $inv = $this->createAndPostSalesInvoice([
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'بند تجربة التنقل للمصدر',
                    'quantity' => '1.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);

        $navService = app(ReportSourceNavigation::class);

        // User A: reports.sales.view only (missing sales.invoice.view)
        $userWithoutDoc = $this->createMemberWithPermissions(['reports.sales.view']);
        $this->activateUser($userWithoutDoc);

        // Simulated query result containing invoice_id
        $result = new ReportResult('sales.summary', [], [], [
            ['invoice_id' => $inv->id, 'document_number' => $inv->document_number],
        ], ['base_currency_code' => 'ILS']);

        $linksWithoutDoc = $navService->forRows($this->company, 'sales.summary', $result);
        $this->assertEmpty($linksWithoutDoc, 'User without sales.invoice.view must not receive source navigation links.');

        // User B: reports.sales.view AND sales.invoice.view
        $userWithDoc = $this->createMemberWithPermissions(['reports.sales.view', 'sales.invoice.view']);
        $this->activateUser($userWithDoc);

        $linksWithDoc = $navService->forRows($this->company, 'sales.summary', $result);
        $this->assertNotEmpty($linksWithDoc, 'User with sales.invoice.view must receive source navigation links.');
        $this->assertSame(route('invoices.show', ['publicId' => (string) $inv->public_id]), $linksWithDoc[0][0]['url']);
    }
}
