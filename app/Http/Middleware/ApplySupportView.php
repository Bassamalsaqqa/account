<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Platform\SupportViewContext;
use App\Application\Platform\SupportViewSession;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

final class ApplySupportView
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has(SupportViewSession::KEY)) {
            return $next($request);
        }
        if ($request->routeIs('platform.support.end', 'platform.support.locale', 'password.confirm', 'password.confirm.store', 'password.confirmation')) {
            return $next($request);
        }
        $session = app(SupportViewSession::class);
        try {
            $resolved = $session->resolve($request);
        } catch (\Throwable) {
            $session->end($request, 'authorization_expired');

            return ($request->expectsJson() || str_ends_with((string) $request->route()?->getName(), 'livewire.update')) ? response()->json(['message' => __('support.expired')], 403) : redirect()->route('platform.index')->with('platform_success', __('support.expired'));
        }
        // Full tenant workflows remain inside the selected company; platform authority is never inherited.
        if ($request->routeIs('platform.*', 'profile', 'api.v1.sessions.*', 'companies.*', 'company.switch', 'locale.switch', 'logout')
            || $request->is('user/*', 'two-factor-challenge', 'confirm-password', 'reset-password/*', 'forgot-password')) {
            return response()->view('platform.support-denied', [], 403)->header('Cache-Control', 'no-store');
        }
        $operator = Auth::user();
        $context = app(CompanyContext::class);
        $support = app(SupportViewContext::class);
        // Warm the normal tenant permission catalogue before switching the effective actor.
        app(PermissionRegistrar::class)->getPermissions();
        try {
            Auth::setUser($resolved['target']);
            $context->setCompany($resolved['company'], $resolved['target']);
            $support->activate($resolved['details']);

            return $next($request)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'same-origin');
        } catch (AuthorizationException) {
            return response()->view('platform.support-denied', [], 403)->header('Cache-Control', 'no-store, private');
        } finally {
            $support->clear();
            $context->clear();
            if ($operator !== null) {
                Auth::setUser($operator);
            } else {
                Auth::forgetUser();
            }
        }
    }
}
