<?php

declare(strict_types=1);

namespace Tests\Unit\ProductExperience;

use App\Http\Middleware\ApplicationSecurityHeaders;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class ApplicationSecurityHeadersTest extends TestCase
{
    public function test_login_and_disabled_api_boundary_keep_baseline_headers(): void
    {
        $this->get('/login')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        config(['commands.api_enabled' => false]);
        $this->get('/api/v1/commands/unknown')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_authenticated_application_responses_are_not_cacheable(): void
    {
        $request = Request::create('/dashboard');
        $request->setUserResolver(fn () => new User);
        $response = (new ApplicationSecurityHeaders)->handle($request, fn () => new Response('synthetic'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('camera=(), geolocation=(), microphone=(self)', $response->headers->get('Permissions-Policy'));
    }

    public function test_stricter_public_document_headers_are_preserved(): void
    {
        $request = Request::create('/share/example');
        $policy = "default-src 'self'; frame-ancestors 'none'";
        $response = (new ApplicationSecurityHeaders)->handle($request, fn () => new Response('', 200, [
            'Content-Security-Policy' => $policy, 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY',
        ]));
        $this->assertSame($policy, $response->headers->get('Content-Security-Policy'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function test_existing_api_revalidation_directives_are_preserved(): void
    {
        $request = Request::create('/api/v1/ai/turn');
        $request->setUserResolver(fn () => new User);
        $response = (new ApplicationSecurityHeaders)->handle($request, fn () => new Response('', 200, [
            'Cache-Control' => 'must-revalidate, no-cache, no-store, private',
        ]));
        $this->assertSame('must-revalidate, no-cache, no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_authenticated_camera_routes_permit_self_camera(): void
    {
        $cameraRoutes = [
            'products.index',
            'invoices.create',
            'invoices.edit',
            'quotations.create',
            'quotations.edit',
            'purchases.create',
            'purchases.edit',
        ];

        foreach ($cameraRoutes as $routeName) {
            $request = Request::create('/test-route');
            $request->setUserResolver(fn () => new User);
            $route = (new Route('GET', '/test-route', ['uses' => fn () => '']))->name($routeName);
            $request->setRouteResolver(fn () => $route);

            $response = (new ApplicationSecurityHeaders)->handle($request, fn () => new Response('ok'));
            $this->assertSame(
                'camera=(self), geolocation=(), microphone=(self)',
                $response->headers->get('Permissions-Policy'),
                "Route {$routeName} should have camera=(self) when authenticated"
            );
        }
    }

    public function test_unauthenticated_camera_routes_keep_camera_empty(): void
    {
        $request = Request::create('/products');
        $route = (new Route('GET', '/products', ['uses' => fn () => '']))->name('products.index');
        $request->setRouteResolver(fn () => $route);

        $response = (new ApplicationSecurityHeaders)->handle($request, fn () => new Response('ok'));
        $this->assertSame('camera=(), geolocation=(), microphone=(self)', $response->headers->get('Permissions-Policy'));
    }

    public function test_non_camera_authenticated_routes_keep_camera_empty(): void
    {
        $nonCameraRoutes = ['invoices.index', 'products.show', 'dashboard', 'settings.index'];

        foreach ($nonCameraRoutes as $routeName) {
            $request = Request::create('/other');
            $request->setUserResolver(fn () => new User);
            $route = (new Route('GET', '/other', ['uses' => fn () => '']))->name($routeName);
            $request->setRouteResolver(fn () => $route);

            $response = (new ApplicationSecurityHeaders)->handle($request, fn () => new Response('ok'));
            $this->assertSame(
                'camera=(), geolocation=(), microphone=(self)',
                $response->headers->get('Permissions-Policy'),
                "Route {$routeName} should have camera=()"
            );
        }
    }
}
