<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Mobile\V1\AuthController;
use App\Http\Controllers\Api\Mobile\V1\BootstrapController;
use App\Http\Controllers\Api\Mobile\V1\CustomerController;
use App\Http\Controllers\Api\Mobile\V1\DeviceController;
use App\Http\Controllers\Api\Mobile\V1\ProductController;
use App\Http\Middleware\MobileAuthenticate;
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

Route::prefix('companies/{companyId}')
    ->middleware(MobileAuthenticate::class)
    ->group(function (): void {
        Route::get('/bootstrap', [BootstrapController::class, 'show'])->name('mobile.v1.bootstrap');
        Route::get('/products', [ProductController::class, 'index'])->name('mobile.v1.products.index');
        Route::get('/customers', [CustomerController::class, 'index'])->name('mobile.v1.customers.index');
    });
