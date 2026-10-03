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
        'settings.sequences.manage',
        'settings.taxes.manage',
        'settings.money_accounts.manage',
        'settings.purchases.manage',
        'audit.events.view',

        // Sales & Documents
        'sales.invoice.view',
        'sales.invoice.create',
        'sales.invoice.edit_draft',
        'sales.invoice.post',
        'sales.invoice.void',
        'sales.invoice.change_price',
        'sales.invoice.change_discount',
        'sales.quote.view',
        'sales.quote.create',
        'sales.quote.edit',
        'sales.quote.send',
        'sales.quote.convert',
        'sales.quote.manage',
        'sales.return.view',
        'sales.return.create',
        'sales.return.post',
        'sales.return.void',
        'sales.return.manage',
        'sales.statement.view',
        'sales.document.share',
        'sales.document.pdf',

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

        // Customers & Vendors
        'customers.view',
        'customers.manage',
        'customers.statement.view',
        'vendors.view',
        'vendors.manage',
        'vendors.statement.view',

        // Money & Banking
        'money.cash.view',
        'money.bank.view',
        'money.receipt.view',
        'money.receipt.create',
        'money.receipt.allocate',
        'money.receipt.reverse',
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

    /** Existing-company upgrade preserves customized role grants; Owner retains the static catalog. */
    public function upgradeSalesCatalog(Company $company): void
    {
        $this->ensurePermissionsExist();
        $previousTeam = getPermissionsTeamId();
        try {
            setPermissionsTeamId($company->id);
            $owner = Role::where('company_id', $company->id)->where('name', 'Owner')->where('guard_name', 'web')->firstOrFail();
            $owner->givePermissionTo(self::PERMISSIONS);
        } finally {
            setPermissionsTeamId($previousTeam);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
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

        $catalogPermissions = Permission::where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->get();
        $permissionsByKey = $catalogPermissions->keyBy('name');

        // 1. Owner: Company-scoped role with all catalog permissions
        $ownerRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Owner',
            'guard_name' => 'web',
        ]);
        $ownerRole->syncPermissions($catalogPermissions);

        // 2. Administrator: All catalog permissions
        $adminRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Administrator',
            'guard_name' => 'web',
        ]);
        $adminRole->syncPermissions($catalogPermissions);

        // 3. Manager: Operational + view settings + reports, no role management
        $managerPerms = [
            'settings.company.view',
            'settings.users.view',
            'settings.sequences.manage',
            'settings.taxes.manage',
            'settings.money_accounts.manage',
            'settings.purchases.manage',
            'audit.events.view',
            'sales.invoice.view', 'sales.invoice.create', 'sales.invoice.edit_draft', 'sales.invoice.post',
            'sales.invoice.void', 'sales.invoice.change_price', 'sales.invoice.change_discount',
            'sales.quote.view', 'sales.quote.create', 'sales.quote.edit', 'sales.quote.send', 'sales.quote.convert', 'sales.quote.manage',
            'sales.return.view', 'sales.return.create', 'sales.return.post', 'sales.return.void', 'sales.return.manage',
            'sales.statement.view', 'sales.document.share', 'sales.document.pdf',
            'purchasing.purchase.view', 'purchasing.purchase.create', 'purchasing.purchase.post', 'purchasing.return.manage',
            'inventory.stock.view', 'inventory.stock.adjust', 'inventory.stock.transfer', 'inventory.cost.view', 'inventory.product.manage',
            'customers.view', 'customers.manage', 'customers.statement.view',
            'vendors.view', 'vendors.manage', 'vendors.statement.view',
            'money.cash.view', 'money.bank.view', 'money.receipt.view', 'money.receipt.create', 'money.receipt.allocate', 'money.receipt.reverse',
            'money.vendor_payment.create', 'money.check.manage', 'money.expense.manage',
            'reports.sales.view', 'reports.profit.view', 'reports.cost.view', 'reports.financial.view', 'reports.tax.view',
        ];
        $managerRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Manager',
            'guard_name' => 'web',
        ]);
        $managerRole->syncPermissions(
            $catalogPermissions->filter(fn ($p) => in_array($p->name, $managerPerms, true))
        );

        // 4. Sales: Invoicing, quotes, customers, stock view, receipts, returns, documents
        $salesPerms = [
            'sales.invoice.view', 'sales.invoice.create', 'sales.invoice.edit_draft', 'sales.invoice.post',
            'sales.quote.view', 'sales.quote.create', 'sales.quote.edit', 'sales.quote.send', 'sales.quote.convert', 'sales.quote.manage',
            'sales.return.view', 'sales.return.create', 'sales.return.post', 'sales.return.manage',
            'sales.statement.view', 'sales.document.share', 'sales.document.pdf',
            'customers.view', 'customers.manage', 'customers.statement.view',
            'inventory.stock.view',
            'money.receipt.view', 'money.receipt.create', 'money.receipt.allocate',
            'reports.sales.view',
        ];
        $salesRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Sales',
            'guard_name' => 'web',
        ]);
        $salesRole->syncPermissions(
            $catalogPermissions->filter(fn ($p) => in_array($p->name, $salesPerms, true))
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
            $catalogPermissions->filter(fn ($p) => in_array($p->name, $purchasingPerms, true))
        );

        // 6. Warehouse: Stock adjust, transfer, product manage
        $warehouseRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Warehouse',
            'guard_name' => 'web',
        ]);
        $warehousePerms = [
            'inventory.stock.view', 'inventory.stock.adjust', 'inventory.stock.transfer', 'inventory.product.manage',
        ];
        $warehouseRole->syncPermissions(
            $catalogPermissions->filter(fn ($p) => in_array($p->name, $warehousePerms, true))
        );

        // 7. Cashier: Invoicing create/view, receipts, cash view, statements, pdf
        $cashierPerms = [
            'sales.invoice.view', 'sales.invoice.create',
            'customers.view', 'customers.statement.view',
            'sales.statement.view', 'sales.document.pdf',
            'inventory.stock.view',
            'money.cash.view',
            'money.receipt.view',
            'money.receipt.create', 'money.receipt.allocate',
        ];
        $cashierRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Cashier',
            'guard_name' => 'web',
        ]);
        $cashierRole->syncPermissions(
            $catalogPermissions->filter(fn ($p) => in_array($p->name, $cashierPerms, true))
        );

        // 8. Viewer: Read-only permissions across modules
        $viewerPerms = [
            'settings.company.view', 'settings.users.view', 'settings.roles.view',
            'sales.invoice.view', 'sales.quote.view', 'sales.return.view',
            'sales.statement.view', 'purchasing.purchase.view',
            'inventory.stock.view', 'customers.view', 'customers.statement.view', 'vendors.view',
            'money.cash.view', 'money.bank.view', 'money.receipt.view',
            'reports.sales.view',
        ];
        $viewerRole = Role::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Viewer',
            'guard_name' => 'web',
        ]);
        $viewerRole->syncPermissions(
            $catalogPermissions->filter(fn ($p) => in_array($p->name, $viewerPerms, true))
        );
    }
}
