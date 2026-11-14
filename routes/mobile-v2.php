<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Mobile\V2\OnlineCashSaleController;
use App\Http\Middleware\MobileV2ApiBoundary;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile V2 API Routes
|--------------------------------------------------------------------------
|
| Guarded by MobileV2ApiBoundary and MobileV2Authenticate.
|
*/

if ((bool) config('mobile.v2_cash_slice_enabled', false)) {
    Route::prefix('companies/{companyId}')
        ->group(function (): void {
            Route::post('/sales/online-cash', [OnlineCashSaleController::class, 'store'])
                ->middleware('mobile.v2.auth')
                ->name('mobile.v2.sales.online-cash');

            Route::get('/offline-events/{clientEventId}/status', [OnlineCashSaleController::class, 'status'])
                ->middleware('mobile.v2.auth')
                ->name('mobile.v2.offline-events.status');
        });
}

Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], '{fallbackPlaceholder?}', function () {
    return MobileV2ApiBoundary::buildErrorResponse(
        code: 'NOT_FOUND',
        message: 'The requested resource was not found.',
        status: 404
    );
})->where('fallbackPlaceholder', '.*')->fallback();
