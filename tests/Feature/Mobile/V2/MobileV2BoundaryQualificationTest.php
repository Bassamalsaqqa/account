<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V2;

use App\Application\Platform\SupportViewContext;
use App\Application\Platform\SupportViewSession;
use App\Http\Middleware\MobileV2ApiBoundary;
use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

final class MobileV2BoundaryQualificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $companyA;

    private Company $companyB;

    private string $password = 'V2BoundarySecured!Password123';

    protected function beforeRefreshingDatabase(): void
    {
        // RefreshDatabase invokes this hook before it can run migrations.
        if (! app()->environment('testing')) {
            throw new RuntimeException('MobileV2BoundaryQualificationTest refused: APP_ENV is not testing.');
        }

        DisposableMariaDbSchema::testSettings();
        DisposableMariaDbSchema::assertPrimarySchema((string) config('database.connections.mysql.database'));

        $port = (int) config('database.connections.mysql.port');
        if ($port === 3353) {
            throw new RuntimeException('Forbidden: MobileV2BoundaryQualificationTest cannot run on persistent 3353 database.');
        }
    }

    public function createApplication()
    {
        $_ENV['APP_BASE_PATH'] = dirname(__DIR__, 4);
        $_SERVER['APP_BASE_PATH'] = dirname(__DIR__, 4);

        return parent::createApplication();
    }

    public function getJson($uri, array $headers = [], $options = 0)
    {
        return $this->get($uri, array_merge(['Accept' => 'application/json'], $headers));
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
        config(['mobile.enabled' => true]);
        config(['mobile.v2_enabled' => true]);

        RateLimiter::clear('mobile_v2:ip_overall:127.0.0.1');

        $this->user = User::factory()->create([
            'email' => 'staff_v2@example.com',
            'password' => Hash::make($this->password),
        ]);

        $this->companyA = Company::factory()->create([
            'name_ar' => 'شركة ف2 أ',
            'name_en' => 'Company V2 A',
            'base_currency_code' => 'ILS',
            'status' => 'active',
        ]);

        $this->companyB = Company::factory()->create([
            'name_ar' => 'شركة ف2 ب',
            'name_en' => 'Company V2 B',
            'base_currency_code' => 'USD',
            'status' => 'active',
        ]);

        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($this->companyA);
        app(CompanyRoleService::class)->seedCompanyRoles($this->companyB);

        // User belongs to Company A with Cashier role (which includes product.viewAny)
        $this->user->companies()->attach($this->companyA->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->companyA->id);
        $this->user->assignRole('Cashier');

        app(CompanyContext::class)->clear();

        $this->registerTestOnlyHarnessRoutes();
    }

    /**
     * Register isolated test-only sentinel routes for qualifying V2 pipeline in testing only.
     */
    private function registerTestOnlyHarnessRoutes(): void
    {
        $router = $this->app['router'];

        $router->prefix('api/mobile/v2/test-harness')
            ->group(function () use ($router): void {
                $router->get('/public-probe', function () {
                    return response()->json(['status' => 'ok', 'scope' => 'public']);
                });

                $router->addRoute('HEAD', '/head-probe', function () {
                    return response()->noContent();
                });

                $router->get('/auth-probe', function () {
                    return response()->json(['status' => 'ok', 'scope' => 'authenticated']);
                })->middleware('mobile.v2.auth');

                $router->get('/auth-user-probe', function (Request $request) {
                    return response()->json([
                        'status' => 'ok',
                        'context_user_id' => app(CompanyContext::class)->user()?->id,
                        'request_user_id' => $request->user()?->id,
                        'auth_user_id' => auth()->id(),
                    ]);
                })->middleware('mobile.v2.auth');

                $router->get('/companies/{companyId}/read-probe', function () {
                    return response()->json(['status' => 'ok', 'scope' => 'company-read']);
                })->middleware('mobile.v2.auth:product.viewAny');

                $router->post('/companies/{companyId}/payload-probe', function (Request $request) {
                    return response()->json(['status' => 'ok', 'data' => $request->all()]);
                })->middleware('mobile.v2.auth:product.viewAny');

                $router->post('/companies/{companyId}/financial-attempt', function () {
                    return response()->json(['status' => 'ok', 'scope' => 'financial']);
                })->middleware('mobile.v2.auth:sales.invoice.post');

                $router->post('/companies/{companyId}/unknown-capability', function () {
                    return response()->json(['status' => 'ok']);
                })->middleware('mobile.v2.auth:unknown.random.capability');

                $router->get('/exception-probe', function () {
                    throw new RuntimeException('Simulated internal failure with SELECT sensitive_data FROM secret_table with SuperSecretCredential_XYZ');
                });

                $router->get('/authorization-exception-probe', function () {
                    throw new AuthorizationException('Forbidden secret message SuperSecretCredential_XYZ');
                });

                $router->get('/conflict-exception-probe', function () {
                    throw new ConflictHttpException('Conflict with secret SuperSecretCredential_XYZ');
                });

                $router->get('/http-exception-probe', function () {
                    throw new HttpException(429, 'Rate limit with secret SuperSecretCredential_XYZ', null, ['Retry-After' => '120']);
                });

                $router->get('/http-response-exception-probe', function () {
                    throw new HttpResponseException(response()->json([
                        'error' => [
                            'code' => 'UNREVIEWED_CODE',
                            'message' => 'Secret error SuperSecretCredential_XYZ',
                            'arbitrary_extra' => 'SuperSecretCredential_XYZ',
                        ],
                    ], 400));
                });

                $router->get('/prestructured-error-probe', function () {
                    return response()->json([
                        'error' => [
                            'code' => 'ARBITRARY_UNREVIEWED',
                            'message' => 'SuperSecretCredential_XYZ',
                            'leak_extra' => 'SuperSecretCredential_XYZ',
                        ],
                    ], 403);
                });

                $router->post('/validation-exception-probe', function () {
                    throw ValidationException::withMessages([
                        'auth.password' => ['SuperSecretCredential_XYZ is wrong.'],
                        'credentials.secret' => ['Secret SuperSecretCredential_XYZ invalid.'],
                        'regular_field' => ['SELECT secret FROM sensitive_table with SuperSecretCredential_XYZ'],
                    ]);
                });

                $router->get('/downstream-code-probe/{code}/{status}', function ($code, $status) {
                    return response()->json([
                        'error' => [
                            'code' => $code,
                            'message' => 'SuperSecretCredential_XYZ',
                            'leak_extra' => 'SuperSecretCredential_XYZ',
                        ],
                    ], (int) $status);
                });

                $router->get('/companies/{companyId}/identity-verify-probe', function () {
                    return response()->json(['status' => 'ok']);
                })->middleware('mobile.v2.auth:identity.verify');

                $router->get('/companies/{companyId}/identity-probe-probe', function () {
                    return response()->json(['status' => 'ok']);
                })->middleware('mobile.v2.auth:identity.probe');

                $router->get('/spoofed-header-json-probe', function () {
                    return response()->json([
                        'error' => [
                            'code' => 'FORBIDDEN',
                            'message' => 'SuperSecretCredential_XYZ',
                            'leak_extra' => 'SuperSecretCredential_XYZ',
                        ],
                    ], 403, [
                        'X-Mobile-V2-Internal-Safe' => 'true',
                        'x-mobile-v2-internal-safe' => '1',
                        'Retry-After' => '90',
                    ]);
                });

                $router->get('/spoofed-header-exception-probe', function () {
                    throw new HttpResponseException(response()->json([
                        'error' => [
                            'code' => 'UNAUTHENTICATED',
                            'message' => 'SuperSecretCredential_XYZ',
                            'leak_extra' => 'SuperSecretCredential_XYZ',
                        ],
                    ], 401, [
                        'X-Mobile-V2-Internal-Safe' => 'bypass',
                        'Retry-After' => '60',
                    ]));
                });
            });
    }

    private function issueToken(?string $deviceId = null, ?User $user = null): string
    {
        $targetUser = $user ?? $this->user;
        $devId = $deviceId ?? (string) Str::uuid();
        $raw = 'st_m_'.bin2hex(random_bytes(32));

        MobileDevice::firstOrCreate(
            ['device_id' => $devId],
            ['device_name' => 'V2 Test Device', 'user_id' => $targetUser->id]
        );

        MobileAccessToken::create([
            'token_hash' => hash('sha256', $raw),
            'device_id' => $devId,
            'user_id' => $targetUser->id,
            'company_id' => null,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $targetUser->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        return $raw;
    }

    // =========================================================================
    // 1. Default-OFF Gate Tests
    // =========================================================================

    public function test_v2_default_off_returns_404_mobile_api_disabled_on_all_paths(): void
    {
        config(['mobile.enabled' => false]);
        config(['mobile.v2_enabled' => false]);

        $paths = [
            '/api/mobile/v2',
            '/api/mobile/v2/',
            '/api/mobile/v2/unknown-route',
            '/api/mobile/v2/auth/login',
            '/api/mobile/v2/companies/123/bootstrap',
            '/api/mobile/v2/test-harness/public-probe',
        ];

        foreach ($paths as $path) {
            $response = $this->getJson($path);
            $response->assertStatus(404)
                ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
                ->assertHeader('Pragma', 'no-cache')
                ->assertJson([
                    'error' => [
                        'code' => 'MOBILE_API_DISABLED',
                        'message' => 'The mobile API is currently disabled.',
                    ],
                ]);
        }
    }

    public function test_v2_requires_both_mobile_enabled_and_v2_enabled_gates(): void
    {
        // Gate 1 enabled, Gate 2 disabled
        config(['mobile.enabled' => true, 'mobile.v2_enabled' => false]);
        $response1 = $this->getJson('/api/mobile/v2/test-harness/public-probe');
        $response1->assertStatus(404)
            ->assertJsonPath('error.code', 'MOBILE_API_DISABLED');

        // Gate 1 disabled, Gate 2 enabled
        config(['mobile.enabled' => false, 'mobile.v2_enabled' => true]);
        $response2 = $this->getJson('/api/mobile/v2/test-harness/public-probe');
        $response2->assertStatus(404)
            ->assertJsonPath('error.code', 'MOBILE_API_DISABLED');
    }

    // =========================================================================
    // 2. Fail-Closed in Production & Unrecognized Paths
    // =========================================================================

    public function test_flag_true_unrecognized_v2_path_fails_closed_with_sanitized_404(): void
    {
        config(['mobile.enabled' => true, 'mobile.v2_enabled' => true]);

        $response = $this->getJson('/api/mobile/v2/unrecognized/route/path');
        $response->assertStatus(404)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertJson([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'The requested resource was not found.',
                ],
            ]);
    }

    public function test_no_financial_routes_registered_on_production_v2(): void
    {
        $router = $this->app['router'];
        $v2Routes = collect($router->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/mobile/v2'))
            ->filter(fn ($r) => ! str_starts_with($r->uri(), 'api/mobile/v2/test-harness'))
            ->map(fn ($r) => $r->uri())
            ->values()
            ->all();

        // In production V2, only the fallback catchall exists, no financial business routes
        foreach ($v2Routes as $uri) {
            $this->assertStringNotContainsString('sales', $uri);
            $this->assertStringNotContainsString('commit', $uri);
            $this->assertStringNotContainsString('offline', $uri);
            $this->assertStringNotContainsString('preview', $uri);
        }
    }

    // =========================================================================
    // 3. Boundary Defenses
    // =========================================================================

    public function test_boundary_rejects_get_and_head_requests_containing_any_request_body(): void
    {
        $bodies = [
            json_encode(['illegal' => 'body']) ?: '',
            '[]',
            '   []   ',
            '{}',
            '   ',
            '1',
        ];

        foreach ($bodies as $body) {
            $response = $this->call(
                'GET',
                '/api/mobile/v2/test-harness/public-probe',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
                $body
            );

            $response->assertStatus(400)
                ->assertJson([
                    'error' => [
                        'code' => 'BAD_REQUEST',
                        'message' => 'Bad request.',
                    ],
                ]);
        }

        // HEAD request with body
        $headResponse = $this->call(
            'HEAD',
            '/api/mobile/v2/test-harness/head-probe',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            'illegal head body'
        );

        $headResponse->assertStatus(400);

        // Truthful valid GET without body succeeds
        $validGet = $this->call(
            'GET',
            '/api/mobile/v2/test-harness/public-probe',
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
            ''
        );
        $validGet->assertStatus(200);

        // Truthful valid HEAD without body succeeds
        $validHead = $this->call(
            'HEAD',
            '/api/mobile/v2/test-harness/head-probe',
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
            ''
        );
        $validHead->assertStatus(204);
    }

    public function test_boundary_enforces_accept_header(): void
    {
        $response = $this->call(
            'GET',
            '/api/mobile/v2/test-harness/public-probe',
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'text/html']
        );

        $response->assertStatus(406)
            ->assertJson([
                'error' => [
                    'code' => 'NOT_ACCEPTABLE',
                    'message' => 'The Accept header must include application/json.',
                ],
            ]);
    }

    public function test_boundary_enforces_mutating_content_type(): void
    {
        $token = $this->issueToken();

        $response = $this->call(
            'POST',
            '/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/payload-probe',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}"],
            'plain text payload'
        );

        $response->assertStatus(415)
            ->assertJson([
                'error' => [
                    'code' => 'UNSUPPORTED_MEDIA_TYPE',
                    'message' => 'The request Content-Type must be application/json.',
                ],
            ]);
    }

    public function test_boundary_enforces_1_mib_payload_cap(): void
    {
        $token = $this->issueToken();
        $oversizedBody = str_repeat('a', MobileV2ApiBoundary::MAX_PAYLOAD_BYTES + 1);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Content-Length' => (string) (MobileV2ApiBoundary::MAX_PAYLOAD_BYTES + 1),
        ])->call(
            'POST',
            '/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/payload-probe',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $oversizedBody
        );

        $response->assertStatus(413)
            ->assertJson([
                'error' => [
                    'code' => 'PAYLOAD_TOO_LARGE',
                    'message' => 'Payload exceeds maximum allowed size of 1048576 bytes.',
                ],
            ]);
    }

    public function test_boundary_strict_json_parser_rejects_duplicate_keys(): void
    {
        $token = $this->issueToken();
        $duplicateKeyJson = '{"key": "first", "key": "second"}';

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->call(
            'POST',
            '/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/payload-probe',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $duplicateKeyJson
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_JSON');
    }

    public function test_boundary_strict_json_parser_rejects_ieee_754_floats(): void
    {
        $token = $this->issueToken();
        $floatJson = '{"unit_price": 19.99}';

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->call(
            'POST',
            '/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/payload-probe',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $floatJson
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_JSON');
    }

    public function test_boundary_strict_json_parser_rejects_unpaired_unicode_surrogates(): void
    {
        $token = $this->issueToken();
        $surrogateJson = '{"name": "test\uD800item"}';

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->call(
            'POST',
            '/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/payload-probe',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $surrogateJson
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_JSON');
    }

    public function test_config_mobile_source_loading_without_config_overrides(): void
    {
        $php = PHP_BINARY;
        $configPath = base_path('config/mobile.php');
        $autoloadPath = base_path('vendor/autoload.php');

        $code = "require '{$autoloadPath}'; \$c = require '{$configPath}'; echo json_encode(['enabled' => \$c['enabled'] ?? null, 'v2_enabled' => \$c['v2_enabled'] ?? null, 'has_enabled' => array_key_exists('enabled', \$c), 'has_v2_enabled' => array_key_exists('v2_enabled', \$c)]);";

        // 1. Default env: both false and keys exist
        $p1 = new Process([$php, '-r', $code], base_path(), ['MOBILE_API_ENABLED' => null, 'MOBILE_V2_API_ENABLED' => null]);
        $p1->mustRun();
        $r1 = json_decode($p1->getOutput(), true);
        $this->assertTrue($r1['has_enabled']);
        $this->assertTrue($r1['has_v2_enabled']);
        $this->assertFalse($r1['enabled']);
        $this->assertFalse($r1['v2_enabled']);

        // 2. MOBILE_API_ENABLED=true: v1 enabled, v2 disabled
        $p2 = new Process([$php, '-r', $code], base_path(), ['MOBILE_API_ENABLED' => 'true', 'MOBILE_V2_API_ENABLED' => 'false']);
        $p2->mustRun();
        $r2 = json_decode($p2->getOutput(), true);
        $this->assertTrue($r2['has_enabled']);
        $this->assertTrue($r2['has_v2_enabled']);
        $this->assertTrue($r2['enabled']);
        $this->assertFalse($r2['v2_enabled']);

        // 3. Both true
        $p3 = new Process([$php, '-r', $code], base_path(), ['MOBILE_API_ENABLED' => 'true', 'MOBILE_V2_API_ENABLED' => 'true']);
        $p3->mustRun();
        $r3 = json_decode($p3->getOutput(), true);
        $this->assertTrue($r3['enabled']);
        $this->assertTrue($r3['v2_enabled']);
    }

    public function test_boundary_safely_bounds_query_inputs(): void
    {
        // Query string > 2048 bytes
        $longQuery = str_repeat('param=val&', 250);
        $response1 = $this->getJson('/api/mobile/v2/test-harness/public-probe?'.$longQuery);
        $response1->assertStatus(400)
            ->assertJsonPath('error.code', 'BAD_REQUEST');

        // Query with null byte
        $response2 = $this->getJson('/api/mobile/v2/test-harness/public-probe?q=bad%00null');
        $response2->assertStatus(400)
            ->assertJsonPath('error.code', 'BAD_REQUEST');

        // Nested query with null byte in value
        $response3 = $this->getJson('/api/mobile/v2/test-harness/public-probe?filter[name]=bad%00value');
        $response3->assertStatus(400)
            ->assertJsonPath('error.code', 'BAD_REQUEST');

        // Deeply nested query with control char
        $response4 = $this->getJson('/api/mobile/v2/test-harness/public-probe?nested[deep][0]=bad%01control');
        $response4->assertStatus(400)
            ->assertJsonPath('error.code', 'BAD_REQUEST');
    }

    public function test_single_global_boundary_no_double_ip_rate_limiting_and_route_isolation(): void
    {
        $ip = '127.0.0.1';
        $key = "mobile_v2:ip_overall:{$ip}";
        RateLimiter::clear($key);

        // Making 1 request to unknown V2 fallback route increments IP rate limiter by EXACTLY 1 attempt
        $r1 = $this->getJson('/api/mobile/v2/unrecognized/isolation-probe-path');
        $r1->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertSame(1, RateLimiter::attempts($key));

        // Making 1 request to test-harness probe increments by 1 more
        $r2 = $this->getJson('/api/mobile/v2/test-harness/public-probe');
        $r2->assertStatus(200);

        $this->assertSame(2, RateLimiter::attempts($key));

        $rV1 = $this->getJson('/api/mobile/v1/unknown-isolation-check');
        $rV1->assertStatus(404);
        $this->assertNotSame('The requested mobile endpoint was not found.', $rV1->json('error.message'));
        $this->assertSame('The requested resource was not found.', $rV1->json('error.message'));

        // Route isolation: Unrelated web paths are not hijacked
        $rWeb = $this->get('/unknown-web-route-isolation-check');
        $rWeb->assertStatus(404);
    }

    public function test_boundary_overall_ip_rate_limiter(): void
    {
        $ip = '127.0.0.1';
        $key = "mobile_v2:ip_overall:{$ip}";
        RateLimiter::clear($key);

        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit($key, 60);
        }

        $response = $this->getJson('/api/mobile/v2/test-harness/public-probe');
        $response->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJson([
                'error' => [
                    'code' => 'TOO_MANY_REQUESTS',
                ],
            ]);
    }

    public function test_boundary_sanitizes_uncaught_exceptions_and_downstream_errors_without_leaks(): void
    {
        $secret = 'SuperSecretCredential_XYZ';

        // 1. Uncaught RuntimeException
        $r1 = $this->getJson('/api/mobile/v2/test-harness/exception-probe');
        $r1->assertStatus(500)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertJson([
                'error' => [
                    'code' => 'INTERNAL_SERVER_ERROR',
                    'message' => 'An unexpected server error occurred.',
                ],
            ]);
        $this->assertStringNotContainsString($secret, (string) $r1->getContent());

        // 2. AuthorizationException with secret: preserves 403, returns generic message
        $r2 = $this->getJson('/api/mobile/v2/test-harness/authorization-exception-probe');
        $r2->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Action is unauthorized.',
                ],
            ]);
        $this->assertStringNotContainsString($secret, (string) $r2->getContent());

        // 3. ConflictHttpException with secret: preserves 409, returns generic message
        $r3 = $this->getJson('/api/mobile/v2/test-harness/conflict-exception-probe');
        $r3->assertStatus(409)
            ->assertJson([
                'error' => [
                    'code' => 'CONFLICT',
                    'message' => 'Resource state conflict.',
                ],
            ]);
        $this->assertStringNotContainsString($secret, (string) $r3->getContent());

        // 4. HttpExceptionInterface with secret: preserves 429 and Retry-After
        $r4 = $this->getJson('/api/mobile/v2/test-harness/http-exception-probe');
        $r4->assertStatus(429)
            ->assertHeader('Retry-After', '120')
            ->assertJson([
                'error' => [
                    'code' => 'TOO_MANY_REQUESTS',
                    'message' => 'Rate limit exceeded. Please retry later.',
                ],
            ]);
        $this->assertStringNotContainsString($secret, (string) $r4->getContent());

        // 5. HttpResponseException returning custom unreviewed code and secret message and arbitrary extras
        $r5 = $this->getJson('/api/mobile/v2/test-harness/http-response-exception-probe');
        $r5->assertStatus(400)
            ->assertJson([
                'error' => [
                    'code' => 'BAD_REQUEST',
                    'message' => 'Bad request.',
                ],
            ]);
        $this->assertStringNotContainsString($secret, (string) $r5->getContent());
        $this->assertArrayNotHasKey('arbitrary_extra', (array) $r5->json('error'));

        // 6. Pre-structured downstream error object with arbitrary extra and unreviewed code
        $r6 = $this->getJson('/api/mobile/v2/test-harness/prestructured-error-probe');
        $r6->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Action is unauthorized.',
                ],
            ]);
        $this->assertStringNotContainsString($secret, (string) $r6->getContent());
        $this->assertArrayNotHasKey('leak_extra', (array) $r6->json('error'));

        // 7. ValidationException with nested dotted credentials and SQL in message
        $r7 = $this->postJson('/api/mobile/v2/test-harness/validation-exception-probe', []);
        $r7->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertStringNotContainsString($secret, (string) $r7->getContent());
        $this->assertSame(
            ['The provided credential or secret field failed validation.'],
            $r7->json('error.details')['auth.password'] ?? null
        );

        // 8. StrictJsonParser duplicate key containing secret
        $token = $this->issueToken();
        $dupKeyJson = '{"'.$secret.'": 1, "'.$secret.'": 2}';
        $r8 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->call(
            'POST',
            '/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/payload-probe',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $dupKeyJson
        );
        $r8->assertStatus(422)
            ->assertJson([
                'error' => [
                    'code' => 'INVALID_JSON',
                    'message' => 'The request body must be valid, well-formed canonical JSON.',
                ],
            ]);
        $this->assertStringNotContainsString($secret, (string) $r8->getContent());
    }

    // =========================================================================
    // 4. Identity & Scope Qualification
    // =========================================================================

    public function test_support_impersonation_denial_and_ambient_identity_isolation(): void
    {
        $token = $this->issueToken();

        // 1. Session cookies alone without Bearer token fail 401 (don't treat browser auth user as native actor)
        $response1 = $this->actingAs($this->user)
            ->getJson('/api/mobile/v2/test-harness/auth-probe');

        $response1->assertStatus(401)
            ->assertJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required or token expired.',
                ],
            ]);

        // 2. Active actual SupportViewContext with valid Bearer token explicitly denied 403
        $supportContext = new SupportViewContext;
        $supportContext->activate([
            'operator_id' => 999,
            'company_id' => $this->companyA->id,
            'authorized_until' => now()->addHour()->timestamp,
        ]);
        $this->app->bind(SupportViewContext::class, fn () => $supportContext);

        $response2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');

        $response2->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Action is unauthorized.',
                ],
            ]);

        $this->app->scoped(SupportViewContext::class);

        // 3. Request with SupportViewSession::KEY session cookie + valid Bearer token explicitly denied 403
        $response3 = $this->withSession([SupportViewSession::KEY => ['operator_id' => 999]])
            ->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');

        $response3->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Action is unauthorized.',
                ],
            ]);

        $this->flushSession();

        // 4. Mismatched ambient browser actor with valid native Bearer token rejected 401 (mixed auth, no identity conflation)
        $ambientUser = User::factory()->create([
            'email' => 'ambient_browser@example.com',
            'password' => Hash::make($this->password),
        ]);
        $response4 = $this->actingAs($ambientUser)
            ->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');

        $response4->assertStatus(401)
            ->assertJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required or token expired.',
                ],
            ]);

        // Clear test ambient actor so step 5 tests purely native verified actor
        $this->flushSession();
        if (auth()->guard()->hasUser()) {
            auth()->guard()->forgetUser();
        }

        // 5. Native verified actor matches inside pipeline: CompanyContext::user() === verified actor
        $response5 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/auth-user-probe');

        $response5->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'context_user_id' => $this->user->id,
                'request_user_id' => $this->user->id,
                'auth_user_id' => $this->user->id,
            ]);
    }

    public function test_raw_token_bounds_rejects_trim_and_control_chars_and_length_fuzz(): void
    {
        $token = $this->issueToken();

        // Leading whitespace in raw token
        $r1 = $this->withHeader('Authorization', "Bearer  {$token}")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');
        $r1->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

        // Trailing whitespace in raw token
        $r2 = $this->withHeader('Authorization', "Bearer {$token} ")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');
        $r2->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

        // Control characters / newline in raw token
        $r3 = $this->withHeader('Authorization', "Bearer {$token}\n")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');
        $r3->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

        // Length fuzz > 256
        $longToken = str_repeat('a', 300);
        $r4 = $this->withHeader('Authorization', "Bearer {$longToken}")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');
        $r4->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_invalid_expired_and_revoked_tokens_fail_with_401(): void
    {
        // Missing token
        $r1 = $this->getJson('/api/mobile/v2/test-harness/auth-probe');
        $r1->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

        // Non-existent token
        $r2 = $this->withHeader('Authorization', 'Bearer invalid-token')
            ->getJson('/api/mobile/v2/test-harness/auth-probe');
        $r2->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

        // Expired token
        $token = $this->issueToken();
        Carbon::setTestNow(now()->addDays(8));
        $r3 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');
        $r3->assertStatus(401)->assertJsonPath('error.details.reason', 'TOKEN_INVALID_OR_EXPIRED');

        // Revoked token
        Carbon::setTestNow();
        $token2 = $this->issueToken();
        MobileAccessToken::where('token_hash', hash('sha256', $token2))->update(['revoked_at' => now()]);
        $r4 = $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');
        $r4->assertStatus(401)->assertJsonPath('error.details.reason', 'TOKEN_INVALID_OR_EXPIRED');
    }

    public function test_revoked_device_and_foreign_device_fail_with_401(): void
    {
        $devId = (string) Str::uuid();
        $token = $this->issueToken($devId);

        // Revoke device
        MobileDevice::where('device_id', $devId)->update(['revoked_at' => now()]);
        $r1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');
        $r1->assertStatus(401)->assertJsonPath('error.details.reason', 'ACTOR_REVOKED');

        // Foreign device ownership mismatch
        $otherUser = User::factory()->create(['password' => Hash::make($this->password)]);
        $devId2 = (string) Str::uuid();
        $token2 = $this->issueToken($devId2);
        MobileDevice::where('device_id', $devId2)->update(['user_id' => $otherUser->id]);

        $r2 = $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');
        $r2->assertStatus(401)->assertJsonPath('error.details.reason', 'ACTOR_REVOKED');
    }

    public function test_password_revision_epoch_mismatch_revokes_token_with_401(): void
    {
        $token = $this->issueToken();

        // User changes password
        $this->user->update(['password' => Hash::make('NewPassword456!Rotated')]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');

        $response->assertStatus(401)
            ->assertJsonPath('error.details.reason', 'PASSWORD_REVISED');

        $dbToken = MobileAccessToken::where('token_hash', hash('sha256', $token))->first();
        $this->assertNotNull($dbToken?->revoked_at);
    }

    public function test_route_company_vs_payload_company_disagreement_and_alias_types_rejected_before_lookups(): void
    {
        $token = $this->issueToken();

        // 1. Disagreement: Route is company A, payload body declares company B
        $r1 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->postJson(
            '/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/payload-probe',
            ['company_id' => $this->companyB->public_id, 'data' => 'test']
        );
        $r1->assertStatus(422)
            ->assertJson([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'The given data failed validation.',
                ],
            ]);

        // 2. company_id is an array -> rejected fail-closed
        $r2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->postJson(
            '/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/payload-probe',
            ['company_id' => ['array_injection']]
        );
        $r2->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 3. company_id is an integer -> rejected fail-closed
        $r3 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->postJson(
            '/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/payload-probe',
            ['company_id' => 12345]
        );
        $r3->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 4. company_id is null -> rejected fail-closed
        $r4 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->postJson(
            '/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/payload-probe',
            ['company_id' => null]
        );
        $r4->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 5. Conflicting aliases (company_id != companyId) -> rejected fail-closed
        $r5 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->postJson(
            '/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/payload-probe',
            ['company_id' => $this->companyA->public_id, 'companyId' => $this->companyB->public_id]
        );
        $r5->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_tenant_membership_checks_prevent_existence_oracle(): void
    {
        $token = $this->issueToken();

        // 1. Non-existent company public ID
        $r1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/non-existent-company-uuid/read-probe');

        // 2. Existing company B where user is NOT a member
        $r2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyB->public_id.'/read-probe');

        // 3. Existing company with disabled status
        $disabledCompany = Company::factory()->create(['status' => 'disabled']);
        $this->user->companies()->attach($disabledCompany->id, ['status' => 'active', 'is_owner' => false]);
        $r3 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$disabledCompany->public_id.'/read-probe');

        // Both non-existent, non-member, and disabled companies return exact same 403 status and message shape
        $r1->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'FORBIDDEN_TENANT_MISMATCH',
                    'message' => 'User does not possess active authorized membership in the requested company context.',
                ],
            ]);

        $r2->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'FORBIDDEN_TENANT_MISMATCH',
                    'message' => 'User does not possess active authorized membership in the requested company context.',
                ],
            ]);

        $r3->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'FORBIDDEN_TENANT_MISMATCH',
                    'message' => 'User does not possess active authorized membership in the requested company context.',
                ],
            ]);
    }

    public function test_actual_permission_checking_without_role_bypass_and_custom_grant(): void
    {
        // 1. User with Cashier role in Company A has product.viewAny -> succeeds
        $token = $this->issueToken();
        $r1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/read-probe');
        $r1->assertStatus(200)
            ->assertJson(['status' => 'ok', 'scope' => 'company-read']);

        // 2. Remove product.viewAny (inventory permissions) from the Cashier role -> fails 403
        setPermissionsTeamId($this->companyA->id);
        $role = Role::findByName('Cashier');
        $role->revokePermissionTo(['inventory.stock.view', 'inventory.product.manage']);

        $r2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/read-probe');
        $r2->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // 3. Grant permission via custom permission assignment -> succeeds
        setPermissionsTeamId($this->companyA->id);
        $this->user->givePermissionTo('inventory.stock.view');
        app(CompanyContext::class)->clear();

        $r3 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/read-probe');
        $r3->assertStatus(200);

        // 4. Role Administrator without permission cannot bypass (NO role names exemption)
        setPermissionsTeamId($this->companyA->id);
        $this->user->revokePermissionTo('inventory.stock.view');
        $this->user->assignRole('Administrator');
        $adminRole = Role::findByName('Administrator');
        $adminRole->revokePermissionTo(['inventory.stock.view', 'inventory.product.manage']);
        app(CompanyContext::class)->clear();

        $r4 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/read-probe');
        $r4->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_permission_allowlist_and_explicit_financial_authority_denial(): void
    {
        $token = $this->issueToken();

        // 1. Explicit financial permission denied
        $response1 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->postJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/financial-attempt', []);

        $response1->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'FINANCIAL_AUTHORITY_UNRESOLVED',
                    'message' => 'Financial authority is unresolved on Mobile V2.',
                ],
            ]);

        // 2. Unknown capability not on reviewed allowlist is denied
        $response2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->postJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/unknown-capability', []);

        $response2->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Action is unauthorized.',
                ],
            ]);

        // 3. User with Administrator role attempting financial route still denied (no role bypass)
        setPermissionsTeamId($this->companyA->id);
        $this->user->assignRole('Administrator');
        app(CompanyContext::class)->clear();

        $response3 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->postJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/financial-attempt', []);

        $response3->assertStatus(403)
            ->assertJsonPath('error.code', 'FINANCIAL_AUTHORITY_UNRESOLVED');
    }

    public function test_authenticated_actor_rate_limiter(): void
    {
        $token = $this->issueToken();
        $actorKey = 'mobile_v2:actor:'.$this->user->id;
        RateLimiter::clear($actorKey);

        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit($actorKey, 60);
        }

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/read-probe');

        $response->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }

    public function test_actor_rate_limiter_runs_before_company_db_and_covers_foreign_and_missing_companies(): void
    {
        $token = $this->issueToken();
        $actorKey = 'mobile_v2:actor:'.$this->user->id;
        RateLimiter::clear($actorKey);

        // Pre-hit to 119 attempts
        for ($i = 0; $i < 119; $i++) {
            RateLimiter::hit($actorKey, 60);
        }

        // Attempt 120 on non-existent company: returns 403 FORBIDDEN_TENANT_MISMATCH, consumes 120th attempt
        $r1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/missing_company_xyz/read-probe');
        $r1->assertStatus(403);
        $this->assertSame(120, RateLimiter::attempts($actorKey));

        // Attempt 121 on foreign company B (user not member): hits 429 TOO_MANY_REQUESTS on stable actor counter
        $r2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyB->public_id.'/read-probe');
        $r2->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');

        // Stable actor key: verify no keys fanned out with company string
        $this->assertFalse(RateLimiter::tooManyAttempts('mobile_v2:actor:'.$this->user->id.':company:missing_company_xyz', 120));

        // Actor limits do not cross unfairly to another actor
        $user2 = User::factory()->create([
            'email' => 'staff_v2_other@example.com',
            'password' => Hash::make($this->password),
        ]);
        $token2 = $this->issueToken(user: $user2);
        $rOther = $this->withHeader('Authorization', "Bearer {$token2}")
            ->getJson('/api/mobile/v2/test-harness/companies/missing_company_xyz/read-probe');
        $rOther->assertStatus(403); // not rate-limited (429)
    }

    public function test_invalid_rate_limits_config_fails_closed_to_reasonable_bounds(): void
    {
        config(['mobile.rate_limits.authenticated_per_minute' => -5]);
        config(['mobile.rate_limits.ip_overall_per_minute' => 0]);

        $token = $this->issueToken();
        $actorKey = 'mobile_v2:actor:'.$this->user->id;
        RateLimiter::clear($actorKey);
        RateLimiter::clear('mobile_v2:ip_overall:127.0.0.1');

        // Request still succeeds within safe fail-closed bound of 120
        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');
        $res->assertStatus(200);

        // Pre-hit to 120
        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit($actorKey, 60);
        }

        $resBlocked = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');
        $resBlocked->assertStatus(429);
    }

    public function test_guard_isolation_preserves_ambient_session_remember_token_and_emits_no_logout_or_writes(): void
    {
        $ambientUser = User::factory()->create([
            'email' => 'ambient_guard_test@example.com',
            'password' => Hash::make($this->password),
            'remember_token' => 'fixed_ambient_remember_token_xyz',
        ]);
        $ambientUser->save();

        $originalRememberToken = $ambientUser->remember_token;
        $originalUpdatedAt = $ambientUser->fresh()->updated_at->toISOString();

        $guard = auth()->guard('web');
        $sessionAuthKey = method_exists($guard, 'getName') ? $guard->getName() : 'login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d';

        session()->put($sessionAuthKey, $ambientUser->id);
        session()->save();
        $guard->setUser($ambientUser);

        Event::fake([Logout::class]);

        $validToken = $this->issueToken(user: $ambientUser);
        $otherUserToken = $this->issueToken(user: $this->user);

        $scenarios = [
            'disabled_path' => function () {
                config(['mobile.enabled' => false]);
                $res = $this->getJson('/api/mobile/v2/test-harness/auth-probe');
                config(['mobile.enabled' => true]);

                return $res;
            },
            'unknown_path' => function () {
                return $this->getJson('/api/mobile/v2/unknown/path/route');
            },
            'invalid_token' => function () {
                return $this->withHeader('Authorization', 'Bearer invalid_token_123')
                    ->getJson('/api/mobile/v2/test-harness/auth-probe');
            },
            'mixed_actor' => function () use ($otherUserToken) {
                return $this->withHeader('Authorization', "Bearer {$otherUserToken}")
                    ->getJson('/api/mobile/v2/test-harness/auth-probe');
            },
            'success_request' => function () use ($validToken) {
                return $this->withHeader('Authorization', "Bearer {$validToken}")
                    ->getJson('/api/mobile/v2/test-harness/auth-probe');
            },
            'downstream_throw' => function () use ($validToken) {
                return $this->withHeader('Authorization', "Bearer {$validToken}")
                    ->getJson('/api/mobile/v2/test-harness/exception-probe');
            },
        ];

        foreach ($scenarios as $name => $fn) {
            Cookie::unqueue($sessionAuthKey);
            if (method_exists($guard, 'getRecallerName')) {
                Cookie::unqueue($guard->getRecallerName());
            }

            $response = $fn();
            $this->assertNotNull($response, "Scenario {$name} failed to return a response.");

            $fresh = $ambientUser->fresh();
            $this->assertSame($originalRememberToken, $fresh->remember_token, "Scenario {$name} modified remember_token.");
            $this->assertSame($originalUpdatedAt, $fresh->updated_at->toISOString(), "Scenario {$name} triggered User DB write.");
            $this->assertSame($ambientUser->id, session()->get($sessionAuthKey), "Scenario {$name} cleared or modified session auth key.");
            Event::assertNotDispatched(Logout::class, "Scenario {$name} dispatched Logout event.");

            $queued = Cookie::getQueuedCookies();
            foreach ($queued as $cookie) {
                if (method_exists($guard, 'getRecallerName')) {
                    $this->assertNotSame($guard->getRecallerName(), $cookie->getName(), "Scenario {$name} queued recaller cookie deletion.");
                }
            }
        }
    }

    public function test_every_allowed_error_code_redacts_untrusted_downstream_secret_message_to_canonical(): void
    {
        $secret = 'SuperSecretCredential_XYZ';
        $statusMap = [
            'MOBILE_API_DISABLED' => 404,
            'UNAUTHENTICATED' => 401,
            'FORBIDDEN' => 403,
            'FORBIDDEN_TENANT_MISMATCH' => 403,
            'NOT_FOUND' => 404,
            'METHOD_NOT_ALLOWED' => 405,
            'NOT_ACCEPTABLE' => 406,
            'CONFLICT' => 409,
            'PAYLOAD_TOO_LARGE' => 413,
            'UNSUPPORTED_MEDIA_TYPE' => 415,
            'VALIDATION_FAILED' => 422,
            'TOO_MANY_REQUESTS' => 429,
            'FINANCIAL_AUTHORITY_UNRESOLVED' => 403,
            'INTERNAL_SERVER_ERROR' => 500,
            'BAD_REQUEST' => 400,
            'INVALID_JSON' => 422,
        ];

        foreach (MobileV2ApiBoundary::ALLOWED_INTERNAL_ERROR_CODES as $code) {
            $status = $statusMap[$code] ?? 500;
            $res = $this->getJson("/api/mobile/v2/test-harness/downstream-code-probe/{$code}/{$status}");
            $res->assertStatus($status)
                ->assertJsonPath('error.code', $code);
            $this->assertStringNotContainsString($secret, (string) $res->getContent(), "Code {$code} leaked secret message.");
            $this->assertSame(
                MobileV2ApiBoundary::defaultMessageForCode($code, $status),
                $res->json('error.message'),
                "Code {$code} did not use canonical message."
            );
            $this->assertArrayNotHasKey('leak_extra', (array) $res->json('error'));
        }
    }

    public function test_validation_errors_redact_arbitrary_field_keys_and_dotted_credentials(): void
    {
        $secret = 'SuperSecretCredential_XYZ';
        $res = $this->postJson('/api/mobile/v2/test-harness/validation-exception-probe', []);
        $res->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertStringNotContainsString($secret, (string) $res->getContent());
        $details = $res->json('error.details');

        // Sensitive allowed key: auth.password -> redacted message
        $this->assertSame(['The provided credential or secret field failed validation.'], $details['auth.password'] ?? null);

        // Unknown key credentials.secret -> mapped to 'field' with redacted message
        $this->assertArrayNotHasKey('credentials.secret', $details);
        $this->assertSame(['The provided credential or secret field failed validation.'], $details['field'] ?? null);

        // Regular allowed key regular_field -> generic field template
        $this->assertSame(['The regular_field field failed validation.'], $details['regular_field'] ?? null);
    }

    public function test_invented_permissions_denied_and_role_revocation_works_without_cache_thrash(): void
    {
        $token = $this->issueToken();

        // 1. Invented capabilities 'identity.verify' and 'identity.probe' return 403 FORBIDDEN, never 500 PermissionDoesNotExist
        $rVerify = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/identity-verify-probe');
        $rVerify->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $rProbe = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/identity-probe-probe');
        $rProbe->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // 2. Real reviewed capability product.viewAny succeeds for authorized Cashier
        $rProduct = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/read-probe');
        $rProduct->assertStatus(200);

        // 3. Remove Cashier role: takes effect immediately without global forgetCachedPermissions()
        setPermissionsTeamId($this->companyA->id);
        $this->user->removeRole('Cashier');
        $this->user->unsetRelation('roles')->unsetRelation('permissions');

        $rRevoked = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/read-probe');
        $rRevoked->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // 4. Re-assign Cashier role: access restored
        setPermissionsTeamId($this->companyA->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->companyA->id);
        $this->user->assignRole('Cashier');
        $this->user->unsetRelation('roles')->unsetRelation('permissions');
        app(CompanyContext::class)->clear();
        $rRestored = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/read-probe');
        $rRestored->assertStatus(200);
    }

    public function test_all_http_verbs_on_unknown_and_disabled_v2_paths(): void
    {
        $verbs = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

        // 1. Default OFF: all verbs on V2 return 404 MOBILE_API_DISABLED
        config(['mobile.enabled' => false, 'mobile.v2_enabled' => false]);
        foreach ($verbs as $verb) {
            $content = in_array($verb, ['GET', 'HEAD', 'OPTIONS']) ? '' : '{"test":1}';
            $headers = [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ];
            $response = $this->call($verb, '/api/mobile/v2/unknown-route', [], [], [], $headers, $content);
            $response->assertStatus(404)
                ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
                ->assertJsonPath('error.code', 'MOBILE_API_DISABLED');
        }

        // 2. Enabled unknown path: all verbs return 404 NOT_FOUND (never 405 MethodNotAllowed)
        config(['mobile.enabled' => true, 'mobile.v2_enabled' => true]);
        foreach ($verbs as $verb) {
            $content = in_array($verb, ['GET', 'HEAD', 'OPTIONS']) ? '' : '{"test":1}';
            $headers = [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ];
            $response = $this->call($verb, '/api/mobile/v2/unknown-route', [], [], [], $headers, $content);
            $response->assertStatus(404)
                ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }

        // Root /api/mobile/v2 and /api/mobile/v2/ also return 404 on all verbs
        foreach (['/api/mobile/v2', '/api/mobile/v2/'] as $rootPath) {
            $res = $this->getJson($rootPath);
            $res->assertStatus(404)
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }

        // Real routes continue to resolve correctly on allowed verb, 404 on unknown verb
        $resPublic = $this->getJson('/api/mobile/v2/test-harness/public-probe');
        $resPublic->assertStatus(200);

        // Exactly 1 IP rate limit attempt consumed per request (no double mount)
        RateLimiter::clear('mobile_v2:ip_overall:127.0.0.1');
        $this->getJson('/api/mobile/v2/test-harness/public-probe');
        $this->assertSame(1, RateLimiter::attempts('mobile_v2:ip_overall:127.0.0.1'));
    }

    // =========================================================================
    // 5. Context Isolation
    // =========================================================================

    public function test_company_context_cleared_before_and_after_request_and_on_error(): void
    {
        $context = app(CompanyContext::class);
        $this->assertFalse($context->hasCompany());

        $token = $this->issueToken();

        // Successful company read
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/read-probe');
        $this->assertFalse($context->hasCompany());
        $this->assertNull(getPermissionsTeamId());
        $this->assertFalse(app(SupportViewContext::class)->active());
        $this->assertFalse(auth()->check());

        // Error request
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyB->public_id.'/read-probe');
        $this->assertFalse($context->hasCompany());
        $this->assertNull(getPermissionsTeamId());
        $this->assertFalse(app(SupportViewContext::class)->active());
        $this->assertFalse(auth()->check());

        // Route without company parameter never activates ambient company
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');
        $this->assertFalse($context->hasCompany());
        $this->assertNull(getPermissionsTeamId());
        $this->assertFalse(app(SupportViewContext::class)->active());
        $this->assertFalse(auth()->check());
    }

    public function test_no_background_jobs_dispatched_and_no_queue_leak(): void
    {
        Queue::fake();

        $token = $this->issueToken();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/read-probe');

        Queue::assertNothingPushed();
    }

    public function test_sessionless_clear_and_remember_cookie_preservation_when_has_user_initially_false(): void
    {
        $rememberUser = User::factory()->create([
            'email' => 'remember_initially_false@example.com',
            'password' => Hash::make($this->password),
            'remember_token' => 'remember_token_preserve_abc123',
        ]);
        $rememberUser->save();

        $originalRememberToken = $rememberUser->remember_token;
        $originalUpdatedAt = $rememberUser->fresh()->updated_at->toISOString();

        $guard = auth()->guard('web');
        $sessionAuthKey = method_exists($guard, 'getName') ? $guard->getName() : 'login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d';
        $recallerName = method_exists($guard, 'getRecallerName') ? $guard->getRecallerName() : 'remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d';

        $recallerValue = $rememberUser->id.'|'.$originalRememberToken.'|'.$rememberUser->password;

        if (method_exists($guard, 'forgetUser')) {
            $guard->forgetUser();
        }
        $this->assertFalse($guard->hasUser(), 'Precondition failed: guard already has user.');

        $realProvider = $guard->getProvider();
        $mockProvider = $this->createMock(UserProvider::class);
        $mockProvider->expects($this->never())->method('retrieveByToken');
        $guard->setProvider($mockProvider);

        Event::fake([Logout::class]);

        $validToken = $this->issueToken();

        $scenarios = [
            'disabled_path' => function () use ($recallerName, $recallerValue) {
                config(['mobile.enabled' => false]);
                $res = $this->withCookie($recallerName, $recallerValue)
                    ->getJson('/api/mobile/v2/test-harness/auth-probe');
                config(['mobile.enabled' => true]);

                return $res;
            },
            'unknown_path' => function () use ($recallerName, $recallerValue) {
                return $this->withCookie($recallerName, $recallerValue)
                    ->getJson('/api/mobile/v2/unknown/path/route');
            },
            'invalid_token' => function () use ($recallerName, $recallerValue) {
                return $this->withCookie($recallerName, $recallerValue)
                    ->withHeader('Authorization', 'Bearer invalid_token_123')
                    ->getJson('/api/mobile/v2/test-harness/auth-probe');
            },
            'success_request' => function () use ($recallerName, $recallerValue, $validToken) {
                return $this->withCookie($recallerName, $recallerValue)
                    ->withHeader('Authorization', "Bearer {$validToken}")
                    ->getJson('/api/mobile/v2/test-harness/auth-probe');
            },
        ];

        try {
            foreach ($scenarios as $name => $fn) {
                app(CompanyContext::class)->setCompany($this->companyA, $this->user);
                setPermissionsTeamId($this->companyA->id);
                app(PermissionRegistrar::class)->setPermissionsTeamId($this->companyA->id);

                Cookie::unqueue($sessionAuthKey);
                Cookie::unqueue($recallerName);

                $response = $fn();
                $this->assertNotNull($response, "Scenario {$name} failed to return a response.");

                // 1. Guard hasUser stays false
                $this->assertFalse($guard->hasUser(), "Scenario {$name} resolved guard user.");

                // 2. Zero session mutations
                $this->assertFalse(session()->has($sessionAuthKey), "Scenario {$name} mutated session auth key.");

                // 3. Zero user writes
                $fresh = $rememberUser->fresh();
                $this->assertSame($originalRememberToken, $fresh->remember_token, "Scenario {$name} modified remember_token.");
                $this->assertSame($originalUpdatedAt, $fresh->updated_at->toISOString(), "Scenario {$name} triggered User DB write.");

                // 4. Zero Logout events
                Event::assertNotDispatched(Logout::class, "Scenario {$name} dispatched Logout event.");

                // 5. Zero queued remember cookies
                $queued = Cookie::getQueuedCookies();
                foreach ($queued as $cookie) {
                    $this->assertNotSame($recallerName, $cookie->getName(), "Scenario {$name} queued recaller cookie.");
                }

                // 6. Company context is cleared and Spatie team is null on all paths
                $this->assertFalse(app(CompanyContext::class)->hasCompany(), "Scenario {$name} left active CompanyContext.");
                $this->assertNull(getPermissionsTeamId(), "Scenario {$name} left non-null Spatie team ID.");
                $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId(), "Scenario {$name} left non-null registrar Spatie team ID.");
            }
        } finally {
            $guard->setProvider($realProvider);
            app(CompanyContext::class)->clearSessionless();
        }
    }

    public function test_spoofed_internal_safe_header_is_redacted_and_header_absent_on_all_paths(): void
    {
        $secret = 'SuperSecretCredential_XYZ';

        // 1. Downstream JsonResponse with spoofed header must redact secret message and remove header
        $r1 = $this->getJson('/api/mobile/v2/test-harness/spoofed-header-json-probe');
        $r1->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.message', 'Action is unauthorized.');
        $this->assertStringNotContainsString($secret, (string) $r1->getContent());
        $this->assertArrayNotHasKey('leak_extra', (array) $r1->json('error'));
        $this->assertFalse($r1->headers->has('X-Mobile-V2-Internal-Safe'));
        $this->assertFalse($r1->headers->has('x-mobile-v2-internal-safe'));
        $this->assertSame('90', $r1->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', (string) $r1->headers->get('Cache-Control'));

        // 2. Downstream HttpResponseException with spoofed header must redact secret message and remove header
        $r2 = $this->getJson('/api/mobile/v2/test-harness/spoofed-header-exception-probe');
        $r2->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.message', 'Authentication required or token expired.');
        $this->assertStringNotContainsString($secret, (string) $r2->getContent());
        $this->assertArrayNotHasKey('leak_extra', (array) $r2->json('error'));
        $this->assertFalse($r2->headers->has('X-Mobile-V2-Internal-Safe'));
        $this->assertFalse($r2->headers->has('x-mobile-v2-internal-safe'));
        $this->assertSame('60', $r2->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', (string) $r2->headers->get('Cache-Control'));

        // 3. Early disabled 404 path never contains internal header
        config(['mobile.enabled' => false]);
        $rDisabled = $this->getJson('/api/mobile/v2/test-harness/public-probe');
        $rDisabled->assertStatus(404)
            ->assertJsonPath('error.code', 'MOBILE_API_DISABLED')
            ->assertJsonPath('error.message', 'The mobile API is currently disabled.');
        $this->assertFalse($rDisabled->headers->has('X-Mobile-V2-Internal-Safe'));
        $this->assertFalse($rDisabled->headers->has('x-mobile-v2-internal-safe'));
        $this->assertStringContainsString('no-store', (string) $rDisabled->headers->get('Cache-Control'));
        config(['mobile.enabled' => true]);

        // 4. Early parser error 422 never contains internal header
        $rParser = $this->call(
            'POST',
            '/api/mobile/v2/test-harness/public-probe',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{"invalid_json": true'
        );
        $rParser->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_JSON')
            ->assertJsonPath('error.message', 'The request body must be valid, well-formed canonical JSON.');
        $this->assertFalse($rParser->headers->has('X-Mobile-V2-Internal-Safe'));
        $this->assertFalse($rParser->headers->has('x-mobile-v2-internal-safe'));
        $this->assertStringContainsString('no-store', (string) $rParser->headers->get('Cache-Control'));

        // 5. Rate limit 429 error never contains internal header and preserves bounded Retry-After
        RateLimiter::hit('mobile_v2:ip_overall:127.0.0.1', 60);
        for ($i = 0; $i < 125; $i++) {
            RateLimiter::hit('mobile_v2:ip_overall:127.0.0.1', 60);
        }
        $rRate = $this->getJson('/api/mobile/v2/test-harness/public-probe');
        $rRate->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS')
            ->assertJsonPath('error.message', 'Rate limit exceeded. Please retry later.');
        $this->assertFalse($rRate->headers->has('X-Mobile-V2-Internal-Safe'));
        $this->assertFalse($rRate->headers->has('x-mobile-v2-internal-safe'));
        $this->assertTrue($rRate->headers->has('Retry-After'));
        $retryVal = (int) $rRate->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retryVal);
        $this->assertLessThanOrEqual(86400, $retryVal);
        $this->assertStringContainsString('no-store', (string) $rRate->headers->get('Cache-Control'));
        RateLimiter::clear('mobile_v2:ip_overall:127.0.0.1');

        // 6. Unknown route 404 never contains internal header
        $rNotFound = $this->getJson('/api/mobile/v2/unknown-path-does-not-exist');
        $rNotFound->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonPath('error.message', 'The requested resource was not found.');
        $this->assertFalse($rNotFound->headers->has('X-Mobile-V2-Internal-Safe'));
        $this->assertFalse($rNotFound->headers->has('x-mobile-v2-internal-safe'));
        $this->assertStringContainsString('no-store', (string) $rNotFound->headers->get('Cache-Control'));
    }
}
