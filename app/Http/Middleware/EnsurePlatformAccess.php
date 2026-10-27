<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Platform\PlatformAccess;
use App\Application\Platform\PlatformAudit;
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
        } catch (AuthorizationException $e) {
            app(PlatformAudit::class)->record('access.denied', $request->user()?->id);

            return response()->view('platform.denied', [], 403);
        }

        return $next($request);
    }
}
