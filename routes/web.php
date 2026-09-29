<?php

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
    });
});

if (app()->environment('local', 'testing')) {
    Route::get('dev/ui', function () {
        return view('dev.ui');
    })->name('dev.ui');
}

require __DIR__.'/auth.php';
