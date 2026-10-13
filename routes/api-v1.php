<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AssistantTurnController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\SalesInvoiceApiController;
use App\Http\Controllers\Api\V1\SessionApiController;
use App\Http\Middleware\Phase11ApiGuard;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Version 1 Command API Routes
|--------------------------------------------------------------------------
|
| Versioned session-authenticated JSON APIs. Global outer boundary middleware
| (Phase11ApiBoundary) runs first to gate disabled features (404), enforce
| TLS/payload size/JSON constraints, and envelope exceptions.
|
| Inner pipeline executes web context (sessions, CSRF, tenancy), session
| authentication (auth, auth.session), and Phase11ApiGuard for database
| session row verification and trusted command authorization proof.
|
*/

Route::prefix('api/v1')
    ->middleware(['web', 'auth', 'auth.session', Phase11ApiGuard::class])
    ->group(function (): void {
        // Safe session inspection and device session management
        Route::get('/session', [SessionApiController::class, 'show'])->name('api.v1.session.show');
        Route::get('/sessions', [SessionApiController::class, 'index'])->name('api.v1.sessions.index');
        Route::delete('/sessions/{opaqueHandle}', [SessionApiController::class, 'destroy'])->name('api.v1.sessions.destroy');

        // Scoped company routes
        Route::prefix('companies/{companyId}')->group(function (): void {
            Route::post('/assistant/turn', AssistantTurnController::class)->name('api.v1.assistant.turn');

            // Sales invoice command endpoints
            Route::prefix('sales/invoices')->group(function (): void {
                Route::post('/draft', [SalesInvoiceApiController::class, 'storeDraft'])->name('api.v1.sales.invoices.draft');
                Route::put('/{publicId}', [SalesInvoiceApiController::class, 'updateDraft'])->name('api.v1.sales.invoices.update');
                Route::get('/{publicId}', [SalesInvoiceApiController::class, 'show'])->name('api.v1.sales.invoices.show');
                Route::post('/{publicId}/preview', [SalesInvoiceApiController::class, 'preview'])->name('api.v1.sales.invoices.preview');
                Route::post('/{publicId}/execute', [SalesInvoiceApiController::class, 'execute'])->name('api.v1.sales.invoices.execute');
            });

            // Read-only lookups for entity resolution (customers, products, warehouses, currencies, taxes)
            Route::prefix('lookups')->group(function (): void {
                Route::get('/customers', [LookupController::class, 'customers'])->name('api.v1.lookups.customers');
                Route::get('/products', [LookupController::class, 'products'])->name('api.v1.lookups.products');
                Route::get('/warehouses', [LookupController::class, 'warehouses'])->name('api.v1.lookups.warehouses');
                Route::get('/currencies', [LookupController::class, 'currencies'])->name('api.v1.lookups.currencies');
                Route::get('/taxes', [LookupController::class, 'taxes'])->name('api.v1.lookups.taxes');
            });
        });
    });
