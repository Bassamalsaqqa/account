<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Mobile\V1\AuthController;
use App\Http\Controllers\Api\Mobile\V1\BootstrapController;
use App\Http\Controllers\Api\Mobile\V1\BusinessSettingsController;
use App\Http\Controllers\Api\Mobile\V1\CustomerController;
use App\Http\Controllers\Api\Mobile\V1\DeviceController;
use App\Http\Controllers\Api\Mobile\V1\ExpenseController;
use App\Http\Controllers\Api\Mobile\V1\HomeController;
use App\Http\Controllers\Api\Mobile\V1\InventoryController;
use App\Http\Controllers\Api\Mobile\V1\ProductController;
use App\Http\Controllers\Api\Mobile\V1\PurchaseController;
use App\Http\Controllers\Api\Mobile\V1\ReceiptStatusController;
use App\Http\Controllers\Api\Mobile\V1\ReportController;
use App\Http\Controllers\Api\Mobile\V1\SalesInvoiceController;
use App\Http\Controllers\Api\Mobile\V1\SalesReturnController;
use App\Http\Controllers\Api\Mobile\V1\SessionController;
use App\Http\Controllers\Api\Mobile\V1\SupplierController;
use App\Http\Middleware\MobileAuthenticate;
use App\Http\Middleware\MobileMerchantBoundary;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile V1 API Routes
|--------------------------------------------------------------------------
|
| Distinct, reviewed boundary for native mobile client endpoints.
| Guarded by MobileApiBoundary and MobileAuthenticate.
|
*/

Route::prefix('auth')->group(function (): void {
    Route::post('/challenge', [AuthController::class, 'challenge'])->name('mobile.v1.auth.challenge');
    Route::post('/login', [AuthController::class, 'login'])->name('mobile.v1.auth.login');
    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware(MobileAuthenticate::class)
        ->name('mobile.v1.auth.logout');
});

Route::post('/devices/{deviceId}/revoke', [DeviceController::class, 'revoke'])
    ->middleware(MobileAuthenticate::class)
    ->name('mobile.v1.devices.revoke');

Route::middleware([MobileMerchantBoundary::class, MobileAuthenticate::class])->group(function (): void {
    Route::get('/session', [SessionController::class, 'current'])->name('mobile.v1.session.current');
    Route::get('/sessions', [SessionController::class, 'index'])->name('mobile.v1.sessions.index');
    Route::delete('/sessions/{deviceId}', [SessionController::class, 'destroy'])->name('mobile.v1.sessions.destroy');
});

Route::prefix('companies/{companyId}')->group(function (): void {
    Route::get('/settings/business', [BusinessSettingsController::class, 'show'])->middleware('mobile.auth')->name('mobile.v1.settings.business');
    Route::get('/bootstrap', [BootstrapController::class, 'show'])->middleware('mobile.auth:sales.invoice.create')->name('mobile.v1.bootstrap');
    Route::get('/products', [ProductController::class, 'index'])->middleware('mobile.auth:product.viewAny')->name('mobile.v1.products.index');
    Route::get('/customers', [CustomerController::class, 'index'])->middleware('mobile.auth:customers.view')->name('mobile.v1.customers.index');
});

Route::prefix('companies/{companyId}')
    ->middleware(MobileMerchantBoundary::class)
    ->group(function (): void {
        // Bootstrap & Home
        Route::get('/home', [HomeController::class, 'show'])->middleware('mobile.auth')->name('mobile.v1.home');

        // Products
        Route::get('/products/{productId}', [ProductController::class, 'show'])->middleware('mobile.auth:product.viewAny')->name('mobile.v1.products.show');
        Route::post('/products', [ProductController::class, 'store'])->middleware('mobile.auth:inventory.product.manage')->name('mobile.v1.products.store');
        Route::put('/products/{productId}', [ProductController::class, 'update'])->middleware('mobile.auth:inventory.product.manage')->name('mobile.v1.products.update');

        // Inventory
        Route::get('/products/{productId}/movements', [InventoryController::class, 'movements'])->middleware('mobile.auth:inventory.stock.view')->name('mobile.v1.inventory.movements');
        Route::post('/inventory/adjustments', [InventoryController::class, 'adjust'])->middleware('mobile.auth:inventory.stock.adjust')->name('mobile.v1.inventory.adjust');
        Route::post('/inventory/transfers/preview', [InventoryController::class, 'previewTransfer'])->middleware('mobile.auth:inventory.stock.transfer')->name('mobile.v1.inventory.transfers.preview');

        // Customers
        Route::get('/customers/{customerId}', [CustomerController::class, 'show'])->middleware('mobile.auth:customers.view')->name('mobile.v1.customers.show');
        Route::post('/customers', [CustomerController::class, 'store'])->middleware('mobile.auth:customers.manage')->name('mobile.v1.customers.store');
        Route::put('/customers/{customerId}', [CustomerController::class, 'update'])->middleware('mobile.auth:customers.manage')->name('mobile.v1.customers.update');
        Route::get('/customers/{customerId}/statement', [CustomerController::class, 'statement'])->middleware('mobile.auth:customers.view')->name('mobile.v1.customers.statement');
        Route::post('/customers/{customerId}/payments', [CustomerController::class, 'recordPayment'])->middleware('mobile.auth:money.receipt.create')->name('mobile.v1.customers.payments');

        // Suppliers
        Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('mobile.auth:vendors.view')->name('mobile.v1.suppliers.index');
        Route::get('/suppliers/{supplierId}', [SupplierController::class, 'show'])->middleware('mobile.auth:vendors.view')->name('mobile.v1.suppliers.show');
        Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('mobile.auth:vendors.manage')->name('mobile.v1.suppliers.store');
        Route::put('/suppliers/{supplierId}', [SupplierController::class, 'update'])->middleware('mobile.auth:vendors.manage')->name('mobile.v1.suppliers.update');
        Route::post('/suppliers/{supplierId}/payments', [SupplierController::class, 'recordPayment'])->middleware('mobile.auth:money.vendor_payment.create')->name('mobile.v1.suppliers.payments');

        // Sales Invoices & Execution
        Route::get('/sales/invoices', [SalesInvoiceController::class, 'index'])->middleware('mobile.auth:sales.invoice.view')->name('mobile.v1.sales.invoices.index');
        Route::get('/sales/invoices/{publicId}', [SalesInvoiceController::class, 'show'])->middleware('mobile.auth:sales.invoice.view')->name('mobile.v1.sales.invoices.show');
        Route::post('/sales/invoices/draft', [SalesInvoiceController::class, 'storeDraft'])->middleware('mobile.auth:sales.invoice.create')->name('mobile.v1.sales.invoices.draft.store');
        Route::put('/sales/invoices/{publicId}', [SalesInvoiceController::class, 'updateDraft'])->middleware('mobile.auth:sales.invoice.create')->name('mobile.v1.sales.invoices.draft.update');
        Route::post('/sales/invoices/preview', [SalesInvoiceController::class, 'preview'])->middleware('mobile.auth:sales.invoice.create')->name('mobile.v1.sales.invoices.preview');
        Route::post('/sales/invoices/{publicId}/execute', [SalesInvoiceController::class, 'execute'])->middleware('mobile.auth:sales.invoice.post')->name('mobile.v1.sales.invoices.execute');

        // Sales Returns / Credit Notes
        Route::post('/sales/returns/preview', [SalesReturnController::class, 'preview'])->middleware('mobile.auth:sales.invoice.view')->name('mobile.v1.sales.returns.preview');
        Route::post('/sales/returns', [SalesReturnController::class, 'store'])->middleware('mobile.auth:sales.return.post')->name('mobile.v1.sales.returns.store');

        // Purchases & GRN
        Route::get('/purchases', [PurchaseController::class, 'index'])->middleware('mobile.auth:purchasing.purchase.view')->name('mobile.v1.purchases.index');
        Route::get('/purchases/{purchaseId}', [PurchaseController::class, 'show'])->middleware('mobile.auth:purchasing.purchase.view')->name('mobile.v1.purchases.show');
        Route::post('/purchases', [PurchaseController::class, 'store'])->middleware('mobile.auth:purchasing.purchase.post')->name('mobile.v1.purchases.store');
        Route::post('/purchases/{purchaseId}/grn', [PurchaseController::class, 'receiveGoods'])->middleware('mobile.auth:inventory.stock.transfer')->name('mobile.v1.purchases.grn');
        Route::post('/purchases/returns/preview', [PurchaseController::class, 'previewReturn'])->middleware('mobile.auth:purchasing.purchase.view')->name('mobile.v1.purchases.returns.preview');
        Route::post('/purchases/returns', [PurchaseController::class, 'storeReturn'])->middleware('mobile.auth:purchasing.return.post')->name('mobile.v1.purchases.returns.store');

        // Expenses
        Route::get('/expenses', [ExpenseController::class, 'index'])->middleware('mobile.auth:money.expense.view')->name('mobile.v1.expenses.index');
        Route::get('/expenses/categories', [ExpenseController::class, 'categories'])->middleware('mobile.auth:money.expense.view')->name('mobile.v1.expenses.categories');
        Route::post('/expenses', [ExpenseController::class, 'store'])->middleware('mobile.auth:money.expense.manage')->name('mobile.v1.expenses.store');

        // Reports
        Route::get('/reports/shift-summary', [ReportController::class, 'shiftSummary'])->middleware('mobile.auth:reports.financial.view')->name('mobile.v1.reports.shift_summary');
        Route::post('/reports/shift-close', [ReportController::class, 'shiftClose'])->middleware('mobile.auth:reports.financial.view')->name('mobile.v1.reports.shift_close');
        Route::get('/reports/treasury', [ReportController::class, 'treasury'])->middleware('mobile.auth:reports.financial.view')->name('mobile.v1.reports.treasury');

        // Receipts & Command Status
        Route::get('/receipts/{idempotencyKey}/status', [ReceiptStatusController::class, 'show'])->middleware('mobile.auth')->name('mobile.v1.receipts.status');
    });
