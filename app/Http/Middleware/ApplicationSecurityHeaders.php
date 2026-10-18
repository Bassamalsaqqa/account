<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApplicationSecurityHeaders
{
    /** @var list<string> */
    private const CAMERA_ALLOWED_ROUTES = [
        'products.index',
        'products.create',
        'products.edit',
        'inventory.opening-stock.create',
        'inventory.adjustments.create',
        'inventory.transfers.create',
        'invoices.create',
        'invoices.edit',
        'quotations.create',
        'quotations.edit',
        'purchases.create',
        'purchases.edit',
    ];

    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $cameraPolicy = ($request->user() !== null && in_array($request->route()?->getName(), self::CAMERA_ALLOWED_ROUTES, true))
            ? 'camera=(self)'
            : 'camera=()';

        // Preserve stricter document policies and endpoint-specific headers.
        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'; base-uri 'self'; object-src 'none'",
            'Permissions-Policy' => "{$cameraPolicy}, geolocation=(), microphone=(self)",
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
