<?php

use App\Http\Middleware\EnsureCompanyContext;
use App\Http\Middleware\Phase11ApiBoundary;
use App\Http\Middleware\Phase12Boundary;
use App\Http\Middleware\PublicDocumentHeaders;
use App\Http\Middleware\SetCompanyContext;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(Phase12Boundary::class);
        $middleware->prepend(Phase11ApiBoundary::class);
        $middleware->web(prepend: [PublicDocumentHeaders::class]);
        $middleware->web(append: [
            SetCompanyContext::class,
            SetLocale::class,
        ]);

        $middleware->alias([
            'company.ensure' => EnsureCompanyContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['current_password', 'provider_secret', 'password', 'password_confirmation']);
    })->create();
