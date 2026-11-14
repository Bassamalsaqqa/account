<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Mobile\V2\OnlineCashSaleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile V2 API Routes
|--------------------------------------------------------------------------
|
| Guarded by MobileV2ApiBoundary and MobileV2Authenticate.
|
*/

Route::prefix('companies/{companyId}')
    ->group(function (): void {
        Route::post('/sales/online-cash', [OnlineCashSaleController::class, 'store'])
            ->middleware('mobile.v2.auth')
            ->name('mobile.v2.sales.online-cash');
    });
