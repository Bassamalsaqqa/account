<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\MobileApiBoundary;
use App\Http\Middleware\MobileAuthenticate;
use App\Http\Middleware\MobileV2ApiBoundary;
use App\Http\Middleware\MobileV2Authenticate;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class MobileApiServiceProvider extends ServiceProvider
{
    /**
     * Register Mobile V1 configuration and services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/mobile.php', 'mobile');
    }

    /**
     * Bootstrap Mobile routes, middleware, and exception handlers.
     */
    public function boot(): void
    {
        $this->registerMiddlewareAliases();
        $this->registerExceptionHooks();
        $this->registerRoutes();
    }

    /**
     * Load Mobile route definitions wrapped in boundaries.
     */
    private function registerRoutes(): void
    {
        Route::prefix('api/mobile/v1')
            ->middleware(MobileApiBoundary::class)
            ->group(base_path('routes/mobile-v1.php'));

        Route::prefix('api/mobile/v2')
            ->group(function (): void {
                Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], '{fallbackPlaceholder?}', function () {
                    return MobileV2ApiBoundary::buildErrorResponse(
                        code: 'NOT_FOUND',
                        message: 'The requested resource was not found.',
                        status: 404
                    );
                })->where('fallbackPlaceholder', '.*')->fallback();
            });
    }

    /**
     * Register middleware aliases for routing.
     */
    private function registerMiddlewareAliases(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('mobile.boundary', MobileApiBoundary::class);
        $router->aliasMiddleware('mobile.auth', MobileAuthenticate::class);
        $router->aliasMiddleware('mobile.v2.boundary', MobileV2ApiBoundary::class);
        $router->aliasMiddleware('mobile.v2.auth', MobileV2Authenticate::class);
    }

    /**
     * Register API-scoped exception rendering hooks.
     */
    private function registerExceptionHooks(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->renderable(function (Throwable $exception, Request $request) {
            if ($request->is('api/mobile/v1') || $request->is('api/mobile/v1/*')) {
                return MobileApiBoundary::renderException($exception);
            }

            if ($request->is('api/mobile/v2') || $request->is('api/mobile/v2/*')) {
                return MobileV2ApiBoundary::renderException($exception);
            }

            return null;
        });
    }
}
