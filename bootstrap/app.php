<?php

use App\Http\Middleware\ApplicationSecurityHeaders;
use App\Http\Middleware\ApplySupportView;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureCompanyContext;
use App\Http\Middleware\EnsurePlatformAccess;
use App\Http\Middleware\Phase11ApiBoundary;
use App\Http\Middleware\Phase12Boundary;
use App\Http\Middleware\PublicDocumentHeaders;
use App\Http\Middleware\SetCompanyContext;
use App\Http\Middleware\SetLocale;
use App\Support\Diagnostics\RequestId;
use App\Support\Diagnostics\SafeErrorLogger;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(Phase12Boundary::class);
        $middleware->prepend(Phase11ApiBoundary::class);
        $middleware->prepend(ApplicationSecurityHeaders::class);
        $middleware->prepend(AssignRequestId::class);
        $middleware->web(prepend: [PublicDocumentHeaders::class]);
        $middleware->web(append: [
            ApplySupportView::class,
            SetCompanyContext::class,
            SetLocale::class,
        ]);

        $middleware->alias([
            'company.ensure' => EnsureCompanyContext::class,
            'platform' => EnsurePlatformAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['current_password', 'provider_secret', 'password', 'password_confirmation', 'code', 'recovery_code']);

        $exceptions->reportable(static function (Throwable $exception): ?bool {
            $request = app()->bound('request') && ! app()->runningInConsole() ? app('request') : null;
            if ($request instanceof Request && ($request->is('api/v1') || $request->is('api/v1/*'))) {
                return null;
            }

            SafeErrorLogger::report($exception, $request instanceof Request ? $request : null);

            return false;
        });

        $exceptions->respond(static function (Response $response, Throwable $exception, Request $request): Response {
            if (! RequestId::isApiBoundaryRequest($request)) {
                $response->headers->set(RequestId::HEADER, RequestId::forRequest($request));
                if (! $response->headers->has('X-Content-Type-Options')) {
                    $response->headers->set('X-Content-Type-Options', 'nosniff');
                }
            }

            return $response;
        });
    })->create();
