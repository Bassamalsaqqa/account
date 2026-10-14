<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApplicationSecurityHeaders
{
    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // Preserve stricter document policies and endpoint-specific headers.
        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'; base-uri 'self'; object-src 'none'",
            'Permissions-Policy' => 'camera=(), geolocation=(), microphone=(self)',
        ] as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }
        if ($request->user() !== null) {
            $response->headers->removeCacheControlDirective('public');
            $response->headers->addCacheControlDirective('private');
            $response->headers->addCacheControlDirective('no-store');
        }

        return $response;
    }
}
