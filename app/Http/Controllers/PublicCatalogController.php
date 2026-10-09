<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Catalogs\CatalogAccess;
use App\Services\Catalogs\CatalogRenderer;
use App\Services\Catalogs\CatalogService;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class PublicCatalogController extends Controller
{
    public function show(string $token, Request $request, CatalogService $catalogs, CatalogAccess $access): Response
    {
        $ipKey = 'catalog-ip:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($ipKey, 60)) {
            return $this->unavailable(429);
        }
        RateLimiter::hit($ipKey, 60);
        try {
            $grant = $catalogs->resolve($token);
            if ($request->isMethod('POST')) {
                $key = 'catalog-password:'.hash('sha256', $grant->public_id.'|'.$request->ip());
                if (RateLimiter::tooManyAttempts($key, 5)) {
                    return $this->unavailable(429);
                }
                RateLimiter::hit($key, 60);
                $access->unlock($grant, $request);

                return redirect('/catalog/'.$token);
            }
            if (! $access->allows($grant, $request)) {
                return response()->view('catalogs.public', ['requiresPassword' => true, 'token' => $token, 'data' => null, 'unavailable' => false]);
            }
            $options = $request->validate(['locale' => ['nullable', 'in:ar,en'], 'page' => ['nullable', 'integer', 'min:1', 'max:11'], 'format' => ['nullable', 'in:json,pdf,print']]);
            $locale = $options['locale'] ?? app()->getLocale();
            $page = (int) ($options['page'] ?? 1);
            $pdf = ($options['format'] ?? '') === 'pdf';
            if ($pdf) {
                $key = 'catalog-pdf:'.hash('sha256', $grant->public_id.'|'.$request->ip());
                if (RateLimiter::tooManyAttempts($key, 5)) {
                    return $this->unavailable(429);
                }
                RateLimiter::hit($key, 60);
            }
            $data = $catalogs->publicData($grant, $locale, $page, $pdf);
            app()->setLocale($locale);
            $response = match ($options['format'] ?? '') {
                'json' => response()->json($data),
                'pdf' => response(app(CatalogRenderer::class)->pdf($data, true), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="catalog.pdf"']),
                'print' => response(app(CatalogRenderer::class)->html($data, true, $token)),
                default => response()->view('catalogs.public', ['data' => $data, 'token' => $token, 'requiresPassword' => false, 'unavailable' => false]),
            };
            $fresh = $catalogs->resolve($token);
            if (! $access->allows($fresh, $request) || $catalogs->publicData($fresh, $locale, $page)['revision'] !== $data['revision']) {
                return $this->unavailable();
            }
            CompanyScope::executeWithoutScope(fn () => $fresh->increment('view_count', 1, ['last_viewed_at' => now()]));

            return $response;
        } catch (\Throwable) {
            return $this->unavailable();
        }
    }

    private function unavailable(int $status = 404): Response
    {
        return response()->view('sales.share-landing', ['unavailable' => true], $status);
    }
}
