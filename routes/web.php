<?php

use App\Http\Controllers\PdfDocumentController;
use App\Http\Controllers\PublicShareController;
use App\Livewire\Pages\Catalog\CategoryIndex;
use App\Livewire\Pages\Catalog\UnitIndex;
use App\Livewire\Pages\Catalog\WarehouseIndex;
use App\Livewire\Pages\Customers\CustomerDetail;
use App\Livewire\Pages\Customers\CustomerForm;
use App\Livewire\Pages\Customers\CustomerIndex;
use App\Livewire\Pages\Inventory\ExpiryCenter;
use App\Livewire\Pages\Inventory\InventoryOverview;
use App\Livewire\Pages\Inventory\OpeningStockForm;
use App\Livewire\Pages\Inventory\StockAdjustmentForm;
use App\Livewire\Pages\Inventory\StockMovementIndex;
use App\Livewire\Pages\Inventory\StockTransferForm;
use App\Livewire\Pages\Products\ProductDetail;
use App\Livewire\Pages\Products\ProductForm;
use App\Livewire\Pages\Products\ProductIndex;
use App\Livewire\Pages\Purchasing\PurchaseDetail;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Purchasing\PurchaseIndex;
use App\Livewire\Pages\Purchasing\Settings\PurchaseSettingsForm;
use App\Livewire\Pages\Purchasing\VendorDetail;
use App\Livewire\Pages\Purchasing\VendorForm;
use App\Livewire\Pages\Purchasing\VendorIndex;
use App\Livewire\Pages\Sales\CustomerStatementView;
use App\Livewire\Pages\Sales\InvoiceDetail;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\InvoiceIndex;
use App\Livewire\Pages\Sales\PaymentDetail;
use App\Livewire\Pages\Sales\PaymentForm;
use App\Livewire\Pages\Sales\PaymentIndex;
use App\Livewire\Pages\Sales\QuotationDetail;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Livewire\Pages\Sales\QuotationIndex;
use App\Livewire\Pages\Sales\ReturnDetail;
use App\Livewire\Pages\Sales\ReturnForm;
use App\Livewire\Pages\Sales\ReturnIndex;
use App\Livewire\Pages\Sales\Settings\DocumentSequenceSettings;
use App\Livewire\Pages\Sales\Settings\MoneyAccountSettings;
use App\Livewire\Pages\Sales\Settings\TaxRateSettings;
use App\Livewire\Pages\SettingsIndex;
use App\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::get('/', function () {
    return redirect()->route('settings.index');
});

Route::post('locale', function (Request $request, CompanyContext $context) {
    $validated = $request->validate([
        'locale' => ['required', 'string', 'in:ar,en'],
    ]);

    $locale = $validated['locale'];

    if ($context->hasCompany()) {
        $company = $context->company();
        if ($locale === 'en' && ! $company->isLanguageEnabled('en')) {
            throw ValidationException::withMessages([
                'locale' => __('settings.error_language_disabled_for_company'),
            ]);
        }
    }

    session(['locale' => $locale]);

    if (auth()->check()) {
        auth()->user()->update(['locale' => $locale]);
    }

    return redirect()->back();
})->name('locale.switch');

Route::middleware(['auth'])->group(function () {
    // Tenancy setup and selection routes (accessible without company context)
    Route::view('companies/setup', 'tenancy.setup')
        ->name('companies.setup');

    Route::view('companies/select', 'tenancy.select')
        ->name('companies.select');

    // Explicit CSRF-protected company switch route
    Route::post('company/switch', function (Request $request, CompanyContext $context) {
        $validated = $request->validate([
            'public_id' => ['required', 'string', 'max:32'],
        ]);

        /** @var Company $company */
        $company = Company::where('public_id', $validated['public_id'])->firstOrFail();

        $context->switchCompany($request->user(), $company);

        return redirect()->route('settings.index');
    })->name('company.switch');

    Route::view('profile', 'profile')
        ->name('profile');

    // Company-protected routes
    Route::middleware(['company.ensure'])->group(function () {
        Route::get('dashboard', function () {
            return view('dashboard');
        })->name('dashboard');

        Route::get('settings', SettingsIndex::class)
            ->name('settings.index');

        // Phase 3 Catalog & Products
        Route::get('products', ProductIndex::class)->name('products.index');
        Route::get('products/create', ProductForm::class)->name('products.create');
        Route::get('products/{publicId}/edit', ProductForm::class)->name('products.edit');
        Route::get('products/{publicId}', ProductDetail::class)->name('products.show');

        Route::get('categories', CategoryIndex::class)->name('categories.index');
        Route::get('units', UnitIndex::class)->name('units.index');
        Route::get('warehouses', WarehouseIndex::class)->name('warehouses.index');

        // Phase 3 Inventory & Movements
        Route::get('inventory', InventoryOverview::class)->name('inventory.overview');
        Route::get('inventory/movements', StockMovementIndex::class)->name('inventory.movements');
        Route::get('inventory/opening-stock/create', OpeningStockForm::class)->name('inventory.opening-stock.create');
        Route::get('inventory/adjustments/create', StockAdjustmentForm::class)->name('inventory.adjustments.create');
        Route::get('inventory/transfers/create', StockTransferForm::class)->name('inventory.transfers.create');
        Route::get('inventory/expiry', ExpiryCenter::class)->name('inventory.expiry');

        // Phase 4 Customers
        Route::get('customers', CustomerIndex::class)->name('customers.index');
        Route::get('customers/create', CustomerForm::class)->name('customers.create');
        Route::get('customers/{publicId}/edit', CustomerForm::class)->name('customers.edit');
        Route::get('customers/{publicId}/statement', CustomerStatementView::class)->name('customers.statement');
        Route::get('customers/{publicId}', CustomerDetail::class)->name('customers.show');

        // Phase 4 Quotations
        Route::get('quotations', QuotationIndex::class)->name('quotations.index');
        Route::get('quotations/create', QuotationForm::class)->name('quotations.create');
        Route::get('quotations/{publicId}/edit', QuotationForm::class)->name('quotations.edit');
        Route::get('quotations/{publicId}', QuotationDetail::class)->name('quotations.show');

        // Phase 4 Sales Invoices
        Route::get('invoices', InvoiceIndex::class)->name('invoices.index');
        Route::get('invoices/create', InvoiceForm::class)->name('invoices.create');
        Route::get('invoices/{publicId}/edit', InvoiceForm::class)->name('invoices.edit');
        Route::get('invoices/{publicId}', InvoiceDetail::class)->name('invoices.show');

        // Phase 4 Sales Returns
        Route::get('returns', ReturnIndex::class)->name('returns.index');
        Route::get('returns/create', ReturnForm::class)->name('returns.create');
        Route::get('returns/{publicId}', ReturnDetail::class)->name('returns.show');

        // Phase 4 Customer Payments (Receipts)
        Route::get('payments', PaymentIndex::class)->name('payments.index');
        Route::get('payments/create', PaymentForm::class)->name('payments.create');
        Route::get('payments/{publicId}', PaymentDetail::class)->name('payments.show');

        // Phase 4 Sales Settings
        Route::get('settings/sequences', DocumentSequenceSettings::class)->name('settings.sequences');
        Route::get('settings/taxes', TaxRateSettings::class)->name('settings.taxes');
        Route::get('settings/money-accounts', MoneyAccountSettings::class)->name('settings.money-accounts');

        // Phase 5A Purchasing Settings
        Route::get('settings/purchases', PurchaseSettingsForm::class)->name('settings.purchases');

        // Phase 5A Vendors
        Route::get('purchases', PurchaseIndex::class)->name('purchases.index');
        Route::get('purchases/create', PurchaseForm::class)->name('purchases.create');
        Route::get('purchases/{publicId}/edit', PurchaseForm::class)->name('purchases.edit');
        Route::get('purchases/{publicId}', PurchaseDetail::class)->name('purchases.show');

        Route::get('vendors', VendorIndex::class)->name('vendors.index');
        Route::get('vendors/create', VendorForm::class)->name('vendors.create');
        Route::get('vendors/{publicId}/edit', VendorForm::class)->name('vendors.edit');
        Route::get('vendors/{publicId}', VendorDetail::class)->name('vendors.show');

        // Phase 4 PDFs
        Route::get('pdf/quotation/{publicId}', [PdfDocumentController::class, 'quotation'])->name('pdf.quotation');
        Route::get('pdf/invoice/{publicId}', [PdfDocumentController::class, 'invoice'])->name('pdf.invoice');
        Route::get('pdf/return/{publicId}', [PdfDocumentController::class, 'return'])->name('pdf.return');
        Route::get('pdf/payment/{publicId}', [PdfDocumentController::class, 'payment'])->name('pdf.payment');
        Route::get('pdf/statement/{publicId}', [PdfDocumentController::class, 'statement'])->name('pdf.statement');
    });
});

// Phase 4 Public Share Routes (No Auth Required)
Route::get('share/{token}', [PublicShareController::class, 'show'])->name('public.share');
Route::post('share/{token}', [PublicShareController::class, 'show'])->name('public.share.verify');

if (app()->environment('local', 'testing')) {
    Route::get('dev/ui', function () {
        return view('dev.ui');
    })->name('dev.ui');
}

require __DIR__.'/auth.php';
