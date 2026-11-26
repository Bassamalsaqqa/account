<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Pure static mapping from registered route names to source files for sanitized triage briefs.
 * Never reads live code/DB or trusts client route names for tenant identity.
 */
final class RouteSourceMap
{
    /** @var array<string, list<string>> */
    private const array ROUTE_FILES = [
        'dashboard' => ['app/Livewire/Pages/Reporting/Dashboard.php', 'resources/views/dashboard.blade.php'],
        'invoices.index' => ['app/Livewire/Pages/Sales/InvoiceIndex.php'],
        'invoices.create' => ['app/Livewire/Pages/Sales/InvoiceForm.php', 'app/Actions/Sales/CreateSalesInvoiceDraftAction.php'],
        'invoices.edit' => ['app/Livewire/Pages/Sales/InvoiceForm.php', 'app/Actions/Sales/UpdateSalesInvoiceDraftAction.php'],
        'invoices.show' => ['app/Livewire/Pages/Sales/InvoiceDetail.php', 'app/Actions/Sales/PostSalesInvoiceAction.php'],
        'quotations.index' => ['app/Livewire/Pages/Sales/QuotationIndex.php'],
        'quotations.create' => ['app/Livewire/Pages/Sales/QuotationForm.php', 'app/Actions/Sales/CreateQuotationAction.php'],
        'quotations.edit' => ['app/Livewire/Pages/Sales/QuotationForm.php', 'app/Actions/Sales/UpdateQuotationAction.php'],
        'quotations.show' => ['app/Livewire/Pages/Sales/QuotationDetail.php'],
        'returns.index' => ['app/Livewire/Pages/Sales/ReturnIndex.php'],
        'returns.create' => ['app/Livewire/Pages/Sales/ReturnForm.php'],
        'returns.edit' => ['app/Livewire/Pages/Sales/ReturnForm.php'],
        'returns.show' => ['app/Livewire/Pages/Sales/ReturnDetail.php'],
        'payments.index' => ['app/Livewire/Pages/Sales/PaymentIndex.php'],
        'payments.create' => ['app/Livewire/Pages/Sales/PaymentForm.php', 'app/Actions/Sales/PostCustomerPaymentAction.php'],
        'payments.show' => ['app/Livewire/Pages/Sales/PaymentDetail.php'],
        'customers.index' => ['app/Livewire/Pages/Customers/CustomerIndex.php'],
        'customers.create' => ['app/Livewire/Pages/Customers/CustomerForm.php'],
        'customers.edit' => ['app/Livewire/Pages/Customers/CustomerForm.php'],
        'customers.show' => ['app/Livewire/Pages/Customers/CustomerDetail.php'],
        'customers.statement' => ['app/Livewire/Pages/Sales/CustomerStatementView.php'],
        'purchases.index' => ['app/Livewire/Pages/Purchasing/PurchaseIndex.php'],
        'purchases.create' => ['app/Livewire/Pages/Purchasing/PurchaseForm.php', 'app/Actions/Purchasing/CreatePurchaseDraftAction.php'],
        'purchases.edit' => ['app/Livewire/Pages/Purchasing/PurchaseForm.php', 'app/Actions/Purchasing/UpdatePurchaseDraftAction.php'],
        'purchases.show' => ['app/Livewire/Pages/Purchasing/PurchaseDetail.php', 'app/Actions/Purchasing/PostPurchaseAction.php'],
        'purchase-returns.index' => ['app/Livewire/Pages/Purchasing/PurchaseReturnIndex.php'],
        'purchase-returns.create' => ['app/Livewire/Pages/Purchasing/PurchaseReturnForm.php'],
        'purchase-returns.edit' => ['app/Livewire/Pages/Purchasing/PurchaseReturnForm.php'],
        'purchase-returns.show' => ['app/Livewire/Pages/Purchasing/PurchaseReturnDetail.php'],
        'vendor-payments.index' => ['app/Livewire/Pages/Purchasing/PaymentIndex.php'],
        'vendor-payments.create' => ['app/Livewire/Pages/Purchasing/PaymentForm.php', 'app/Actions/Purchasing/PostVendorPaymentAction.php'],
        'vendor-payments.show' => ['app/Livewire/Pages/Purchasing/PaymentDetail.php'],
        'vendors.index' => ['app/Livewire/Pages/Purchasing/VendorIndex.php'],
        'vendors.create' => ['app/Livewire/Pages/Purchasing/VendorForm.php'],
        'vendors.edit' => ['app/Livewire/Pages/Purchasing/VendorForm.php'],
        'vendors.show' => ['app/Livewire/Pages/Purchasing/VendorDetail.php'],
        'products.index' => ['app/Livewire/Pages/Products/ProductIndex.php'],
        'products.create' => ['app/Livewire/Pages/Products/ProductForm.php'],
        'products.edit' => ['app/Livewire/Pages/Products/ProductForm.php'],
        'products.show' => ['app/Livewire/Pages/Products/ProductDetail.php'],
        'catalogs.index' => ['app/Livewire/Pages/Catalogs/CatalogComposer.php'],
        'catalogs.create' => ['app/Livewire/Pages/Catalogs/CatalogComposer.php'],
        'catalogs.edit' => ['app/Livewire/Pages/Catalogs/CatalogComposer.php'],
        'inventory.overview' => ['app/Livewire/Pages/Inventory/InventoryOverview.php'],
        'inventory.movements' => ['app/Livewire/Pages/Inventory/StockMovementIndex.php'],
        'inventory.expiry' => ['app/Livewire/Pages/Inventory/ExpiryCenter.php'],
        'money.overview' => ['app/Livewire/Pages/Money/Overview.php'],
        'money.cash' => ['app/Livewire/Pages/Money/Overview.php'],
        'money.bank' => ['app/Livewire/Pages/Money/Overview.php'],
        'money.checks.index' => ['app/Livewire/Pages/Money/CheckIndex.php'],
        'money.transfers.index' => ['app/Livewire/Pages/Money/TransferIndex.php'],
        'expenses.index' => ['app/Livewire/Pages/Expenses/ExpenseIndex.php'],
        'expenses.create' => ['app/Livewire/Pages/Expenses/ExpenseForm.php'],
        'employees.index' => ['app/Livewire/Pages/Payroll/EmployeeIndex.php'],
        'reports.index' => ['app/Livewire/Pages/Reporting/ReportHub.php'],
        'reports.show' => ['app/Livewire/Pages/Reporting/ReportView.php'],
        'settings.index' => ['app/Livewire/Pages/SettingsIndex.php'],
        'profile' => ['resources/views/profile.blade.php'],
        'companies.setup' => ['resources/views/tenancy/setup.blade.php'],
        'companies.select' => ['resources/views/tenancy/select.blade.php'],
        'platform.index' => ['app/Http/Controllers/PlatformController.php', 'resources/views/platform/index.blade.php'],
        'platform.companies.show' => ['app/Http/Controllers/PlatformController.php', 'resources/views/platform/show.blade.php'],
        'platform.companies.create' => ['app/Http/Controllers/PlatformController.php', 'app/Application/Platform/ProvisionCompany.php'],
        'platform.companies.subscription.show' => ['app/Http/Controllers/PlatformSubscriptionController.php', 'app/Application/Platform/ManageSubscription.php'],
        'platform.audit' => ['app/Http/Controllers/PlatformController.php', 'resources/views/platform/audit.blade.php'],
        'platform.bug-reports.index' => ['app/Http/Controllers/PlatformBugReportController.php', 'app/Application/Diagnostics/PlatformBugReportService.php'],
        'platform.bug-reports.show' => ['app/Http/Controllers/PlatformBugReportController.php', 'app/Application/Diagnostics/PlatformBugReportService.php'],
    ];

    public static function resolveRegisteredRoute(?string $name): ?Route
    {
        if (! is_string($name) || $name === '' || strlen($name) > 120) {
            return null;
        }

        if (preg_match('/^[a-zA-Z0-9._-]+$/D', $name) !== 1) {
            return null;
        }

        $route = RouteFacade::getRoutes()->getByName($name);

        return $route instanceof Route ? $route : null;
    }

    public static function sanitizeRouteTemplate(?Route $route): ?string
    {
        if ($route === null) {
            return null;
        }

        $uri = preg_replace('/[\x00-\x1F\x7F]/', '', $route->uri());
        if (! is_string($uri) || $uri === '') {
            return null;
        }

        return substr('/'.ltrim($uri, '/'), 0, 160);
    }

    /** @return list<string> */
    public static function suspectFiles(?string $routeName): array
    {
        if ($routeName === null) {
            return [];
        }

        return self::ROUTE_FILES[$routeName] ?? [];
    }
}
