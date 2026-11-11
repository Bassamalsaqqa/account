<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\MobileApiBoundary;
use App\Http\Middleware\MobileAuthenticate;
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
     * Bootstrap Mobile V1 routes, middleware, and exception handlers.
     */
    public function boot(): void
    {
        $this->registerMiddlewareAliases();
        $this->registerExceptionHooks();
        $this->registerRoutes();
    }

    /**
     * Load Mobile V1 route definitions wrapped in MobileApiBoundary.
     */
    private function registerRoutes(): void
    {
        Route::prefix('api/mobile/v1')
            ->middleware(MobileApiBoundary::class)
            ->group(base_path('routes/mobile-v1.php'));
    }

    /**
     * Register middleware aliases for routing.
     */
    private function registerMiddlewareAliases(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('mobile.boundary', MobileApiBoundary::class);
        $router->aliasMiddleware('mobile.auth', MobileAuthenticate::class);
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

            return null;
        });
    }
}
