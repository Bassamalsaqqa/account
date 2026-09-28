<?php

use App\Livewire\Pages\SettingsIndex;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('settings.index');
});

Route::get('locale/{locale}', function (string $locale) {
    if (in_array($locale, ['ar', 'en'], true)) {
        session(['locale' => $locale]);
        if (auth()->check()) {
            auth()->user()->update(['locale' => $locale]);
        }
    }

    return redirect()->back();
})->name('locale.switch');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('settings', SettingsIndex::class)
        ->name('settings.index');

    Route::view('profile', 'profile')
        ->name('profile');
});

if (app()->environment('local', 'testing')) {
    Route::get('dev/ui', function () {
        return view('dev.ui');
    })->name('dev.ui');
}

require __DIR__.'/auth.php';
