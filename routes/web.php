<?php

use App\Livewire\Pages\Catalog\CategoryIndex;
use App\Livewire\Pages\Catalog\UnitIndex;
use App\Livewire\Pages\Catalog\WarehouseIndex;
use App\Livewire\Pages\Inventory\ExpiryCenter;
use App\Livewire\Pages\Inventory\InventoryOverview;
use App\Livewire\Pages\Inventory\OpeningStockForm;
use App\Livewire\Pages\Inventory\StockAdjustmentForm;
use App\Livewire\Pages\Inventory\StockMovementIndex;
use App\Livewire\Pages\Inventory\StockTransferForm;
use App\Livewire\Pages\Products\ProductDetail;
use App\Livewire\Pages\Products\ProductForm;
use App\Livewire\Pages\Products\ProductIndex;
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
    });
});

if (app()->environment('local', 'testing')) {
    Route::get('dev/ui', function () {
        return view('dev.ui');
    })->name('dev.ui');
}

require __DIR__.'/auth.php';
