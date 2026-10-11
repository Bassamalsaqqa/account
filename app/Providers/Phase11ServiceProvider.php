<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\Phase11ApiBoundary;
use App\Http\Middleware\Phase11ApiGuard;
use App\Services\Security\CommandSessionGuard;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class Phase11ServiceProvider extends ServiceProvider
{
    /**
     * Register Phase 11 Command API configuration and services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/commands.php', 'commands');

        $this->app->scoped(CommandSessionGuard::class, fn () => new CommandSessionGuard);
    }

    /**
     * Bootstrap Phase 11 rate limiters, session login listener, routes, exception hooks, and middleware aliases.
     */
    public function boot(): void
    {
        $this->registerLoginEventListener();
        $this->registerExceptionHooks();
        $this->registerRateLimiting();
        $this->registerRoutes();
        $this->registerMiddlewareAliases();
    }

    /**
     * Listen to fresh credential Login events to store phase11_session_started_at.
     * Legitimate fresh logins with remember checkbox are accepted; recalled remember cookies are refused.
     */
    private function registerLoginEventListener(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if (session()->isStarted() && ! auth()->guard($event->guard)->viaRemember()) {
                session()->put('phase11_session_started_at', now()->timestamp);
            }
        });
    }

    /**
     * Register API-scoped exception reporting and rendering hooks on the Laravel exception handler.
     * Keeps ordinary web application behavior unaffected.
     */
    private function registerExceptionHooks(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $handler->reportable(function (Throwable $exception) {
            $request = request();
            if ($request->is('api/v1') || $request->is('api/v1/*')) {
                Log::error(get_class($exception), ['trace_id' => Phase11ApiBoundary::resolveTraceId($request)]);

                return false;
            }

            return null;
        });
        $handler->renderable(function (Throwable $exception, Request $request) {
            if ($request->is('api/v1') || $request->is('api/v1/*')) {
                return Phase11ApiBoundary::renderProblemException($exception, $request);
            }

            return null;
        });
    }

    /**
     * Register rate limiters for IP overall, guest, and authenticated actor.
     */
    private function registerRateLimiting(): void
    {
        RateLimiter::for('phase11-api-overall', function (Request $request) {
            $maxIp = (int) config('commands.rate_limits.ip_overall_per_minute', 120);

            return Limit::perMinute($maxIp)
                ->by('phase11:ip_overall:'.(string) $request->ip());
        });

        RateLimiter::for('phase11-api-guest', function (Request $request) {
            $maxGuest = (int) config('commands.rate_limits.guest_per_minute', 30);

            return Limit::perMinute($maxGuest)
                ->by('phase11:guest:'.(string) $request->ip());
        });

        RateLimiter::for('phase11-api-auth', function (Request $request) {
            $user = $request->user();
            $companyId = session('active_company_id', 'none');
            $maxAuth = (int) config('commands.rate_limits.authenticated_per_minute', 60);

            if ($user !== null) {
                return Limit::perMinute($maxAuth)
                    ->by("phase11:auth_actor:{$user->id}:comp:{$companyId}");
            }

            return Limit::perMinute($maxAuth)
                ->by('phase11:guest:'.(string) $request->ip());
        });
    }

    /**
     * Load the versioned V1 API route definitions.
     */
    private function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/api-v1.php');
    }

    /**
     * Register middleware aliases for routing flexibility.
     */
    private function registerMiddlewareAliases(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('phase11.boundary', Phase11ApiBoundary::class);
        $router->aliasMiddleware('phase11.guard', Phase11ApiGuard::class);
    }
}
