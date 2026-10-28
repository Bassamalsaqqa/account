<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Platform\PlatformAccess;
use App\Application\Platform\PlatformAudit;
use App\Application\Platform\PlatformReauthenticationRequired;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePlatformAccess
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        try {
            $grant = app(PlatformAccess::class)->authorize($permission);
            $request->attributes->set('platform_permissions', $grant->permissions);
        } catch (PlatformReauthenticationRequired $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('platform.password_required')], 423);
            }
            $request->session()->put('url.intended', $request->isMethod('GET') ? $request->fullUrl() : route('platform.index'));

            return redirect()->route('password.confirm');
        } catch (AuthorizationException $e) {
            app(PlatformAudit::class)->record('access.denied', $request->user()?->id);

            return response()->view('platform.denied', [], 403);
        }

        return $next($request);
    }
}
