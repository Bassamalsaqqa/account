<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

final class PublicDocumentHeaders
{
    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('share/*', 'catalog/*')) {
            return $next($request);
        }
        $nonce = base64_encode(random_bytes(18));
        $request->attributes->set('document_csp_nonce', $nonce);
        try {
            $response = $next($request);
        } catch (TokenMismatchException) {
            $response = response()->view('sales.share-landing', ['unavailable' => true], 419);
        }
        foreach (['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff'] as $header => $value) {
            $response->headers->set($header, $value);
        }
        $response->headers->set('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'nonce-{$nonce}'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

        return $response;
    }
}
