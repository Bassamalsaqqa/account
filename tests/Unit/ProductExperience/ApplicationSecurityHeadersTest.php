<?php

declare(strict_types=1);

namespace Tests\Unit\ProductExperience;

use App\Http\Middleware\ApplicationSecurityHeaders;
use App\Models\User;
use Illuminate\Http\Request;
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
}
