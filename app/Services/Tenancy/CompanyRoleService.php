<?php

namespace App\Services\Tenancy;

use App\Models\Company;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CompanyRoleService
{
    /**
     * Complete blueprint permission catalog.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        // Settings & Tenancy
        'settings.company.view',
        'settings.company.manage',
        'settings.users.view',
        'settings.users.manage',
        'settings.roles.view',
        'settings.roles.manage',
        'settings.accounting.manage',
        'audit.events.view',

        // Sales (catalog only in Phase 1)
        'sales.invoice.view',
        'sales.invoice.create',
        'sales.invoice.edit_draft',
        'sales.invoice.post',
        'sales.invoice.void',
        'sales.invoice.change_price',
        'sales.invoice.change_discount',
        'sales.quote.manage',
        'sales.return.manage',

        // Purchasing (catalog only in Phase 1)
        'purchasing.purchase.view',
        'purchasing.purchase.create',
        'purchasing.purchase.post',
        'purchasing.return.manage',

        // Inventory (catalog only in Phase 1)
        'inventory.stock.view',
        'inventory.stock.adjust',
        'inventory.stock.transfer',
        'inventory.cost.view',
        'inventory.product.manage',

        // Customers & Vendors (catalog only in Phase 1)
        'customers.view',
        'customers.manage',
        'customers.statement.view',
        'vendors.view',
        'vendors.manage',
        'vendors.statement.view',

        // Money & Banking (catalog only in Phase 1)
        'money.cash.view',
        'money.bank.view',
        'money.receipt.create',
        'money.vendor_payment.create',
        'money.check.manage',
        'money.expense.manage',

        // Reports (catalog only in Phase 1)
        'reports.sales.view',
        'reports.profit.view',
        'reports.cost.view',
        'reports.financial.view',
        'reports.tax.view',
    ];

    /**
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        return self::PERMISSIONS;
    }

    /**
     * Ensure all permissions in the catalog exist globally for the web guard.
     */
    public function ensurePermissionsExist(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }

    /**
     * Create or update the 8 idempotent default roles for a given company.
     */
    public function seedCompanyRoles(Company $company): void
    {
        $this->ensurePermissionsExist();

        // Switch Spatie team context
        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

        $allPermissions = Permission::where('guard_name', 'web')->get();
        $permissionsByKey = $allPermissions->keyBy('name');

        // 1. Owner: Company-scoped role with all permissions
        $ownerRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Owner',
            'guard_name' => 'web',
        ]);
        $ownerRole->syncPermissions($allPermissions);

        // 2. Administrator: All permissions
        $adminRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Administrator',
            'guard_name' => 'web',
        ]);
        $adminRole->syncPermissions($allPermissions);

        // 3. Manager: Operational + view settings + reports, no role management
        $managerPerms = [
            'settings.company.view',
            'settings.users.view',
            'audit.events.view',
            'sales.invoice.view', 'sales.invoice.create', 'sales.invoice.edit_draft', 'sales.invoice.post',
            'sales.invoice.void', 'sales.invoice.change_price', 'sales.invoice.change_discount',
            'sales.quote.manage', 'sales.return.manage',
            'purchasing.purchase.view', 'purchasing.purchase.create', 'purchasing.purchase.post', 'purchasing.return.manage',
            'inventory.stock.view', 'inventory.stock.adjust', 'inventory.stock.transfer', 'inventory.cost.view', 'inventory.product.manage',
            'customers.view', 'customers.manage', 'customers.statement.view',
            'vendors.view', 'vendors.manage', 'vendors.statement.view',
            'money.cash.view', 'money.bank.view', 'money.receipt.create', 'money.vendor_payment.create', 'money.check.manage', 'money.expense.manage',
            'reports.sales.view', 'reports.profit.view', 'reports.cost.view', 'reports.financial.view', 'reports.tax.view',
        ];
        $managerRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Manager',
            'guard_name' => 'web',
        ]);
        $managerRole->syncPermissions(
            $allPermissions->filter(fn ($p) => in_array($p->name, $managerPerms, true))
        );

        // 4. Sales: Invoicing, quotes, customers, stock view, receipts
        $salesPerms = [
            'sales.invoice.view', 'sales.invoice.create', 'sales.invoice.edit_draft',
            'sales.quote.manage',
            'customers.view', 'customers.manage', 'customers.statement.view',
            'inventory.stock.view',
            'money.receipt.create',
            'reports.sales.view',
        ];
        $salesRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Sales',
            'guard_name' => 'web',
        ]);
        $salesRole->syncPermissions(
            $allPermissions->filter(fn ($p) => in_array($p->name, $salesPerms, true))
        );

        // 5. Purchasing: Purchases, vendors, stock view, vendor payments
        $purchasingPerms = [
            'purchasing.purchase.view', 'purchasing.purchase.create', 'purchasing.purchase.post',
            'purchasing.return.manage',
            'vendors.view', 'vendors.manage', 'vendors.statement.view',
            'inventory.stock.view',
            'money.vendor_payment.create',
        ];
        $purchasingRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Purchasing',
            'guard_name' => 'web',
        ]);
        $purchasingRole->syncPermissions(
            $allPermissions->filter(fn ($p) => in_array($p->name, $purchasingPerms, true))
        );

        // 6. Warehouse: Stock adjust, transfer, product manage
        $warehousePerms = [
            'inventory.stock.view', 'inventory.stock.adjust', 'inventory.stock.transfer', 'inventory.product.manage',
        ];
        $warehouseRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Warehouse',
            'guard_name' => 'web',
        ]);
        $warehouseRole->syncPermissions(
            $allPermissions->filter(fn ($p) => in_array($p->name, $warehousePerms, true))
        );

        // 7. Cashier: Invoicing create/view, receipts, cash view
        $cashierPerms = [
            'sales.invoice.view', 'sales.invoice.create',
            'customers.view',
            'inventory.stock.view',
            'money.cash.view',
            'money.receipt.create',
        ];
        $cashierRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Cashier',
            'guard_name' => 'web',
        ]);
        $cashierRole->syncPermissions(
            $allPermissions->filter(fn ($p) => in_array($p->name, $cashierPerms, true))
        );

        // 8. Viewer: Read-only permissions across modules
        $viewerPerms = [
            'settings.company.view', 'settings.users.view', 'settings.roles.view',
            'sales.invoice.view', 'purchasing.purchase.view',
            'inventory.stock.view', 'customers.view', 'vendors.view',
            'money.cash.view', 'money.bank.view',
            'reports.sales.view',
        ];
        $viewerRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Viewer',
            'guard_name' => 'web',
        ]);
        $viewerRole->syncPermissions(
            $allPermissions->filter(fn ($p) => in_array($p->name, $viewerPerms, true))
        );
    }
}
