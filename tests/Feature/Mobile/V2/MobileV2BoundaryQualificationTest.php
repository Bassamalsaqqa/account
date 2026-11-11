<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V2;

use App\Http\Middleware\MobileV2ApiBoundary;
use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

    protected function setUp(): void
    {
        parent::setUp();

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
            ->middleware('mobile.v2.boundary')
            ->group(function () use ($router): void {
                $router->get('/public-probe', function () {
                    return response()->json(['status' => 'ok', 'scope' => 'public']);
                });

                $router->get('/auth-probe', function () {
                    return response()->json(['status' => 'ok', 'scope' => 'authenticated']);
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

                $router->get('/exception-probe', function () {
                    throw new RuntimeException('Simulated internal failure with SELECT sensitive_data FROM secret_table');
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

    public function test_boundary_rejects_get_requests_containing_request_body(): void
    {
        $response = $this->call(
            'GET',
            '/api/mobile/v2/test-harness/public-probe',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['illegal' => 'body']) ?: ''
        );

        $response->assertStatus(400)
            ->assertJson([
                'error' => [
                    'code' => 'BAD_REQUEST',
                    'message' => 'GET requests must not contain a request body.',
                ],
            ]);
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

    public function test_boundary_sanitizes_uncaught_exceptions_without_leaks(): void
    {
        $response = $this->getJson('/api/mobile/v2/test-harness/exception-probe');

        $response->assertStatus(500)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertJson([
                'error' => [
                    'code' => 'INTERNAL_SERVER_ERROR',
                    'message' => 'An unexpected server error occurred.',
                ],
            ]);

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString('sensitive_data', $content);
        $this->assertStringNotContainsString('secret_table', $content);
        $this->assertStringNotContainsString('RuntimeException', $content);
        $this->assertStringNotContainsString('Stack trace', $content);
    }

    // =========================================================================
    // 4. Identity & Scope Qualification
    // =========================================================================

    public function test_session_cookies_alone_and_support_impersonation_fail_with_401(): void
    {
        // 1. Session cookies alone without Bearer token
        $response1 = $this->actingAs($this->user)
            ->getJson('/api/mobile/v2/test-harness/auth-probe');

        $response1->assertStatus(401)
            ->assertJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required or token expired.',
                ],
            ]);

        // 2. Synthetic support impersonation session without Bearer token
        $response2 = $this->withSession(['support_user_id' => 999])
            ->getJson('/api/mobile/v2/test-harness/auth-probe');

        $response2->assertStatus(401)
            ->assertJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                ],
            ]);
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

    public function test_route_company_vs_payload_company_disagreement_rejected_before_lookups(): void
    {
        $token = $this->issueToken();

        // Disagreement: Route is company A, payload body declares company B
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->postJson(
            '/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/payload-probe',
            ['company_id' => $this->companyB->public_id, 'data' => 'test']
        );

        $response->assertStatus(422)
            ->assertJson([
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'Company identifier in payload does not match route context.',
                ],
            ]);
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

    public function test_financial_authority_unresolved_explicit_denial_no_fake_pass(): void
    {
        $token = $this->issueToken();

        // Even though user has Cashier/Admin role or permissions, financial operations on V2 MUST fail explicitly
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->postJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/financial-attempt', []);

        $response->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'FINANCIAL_AUTHORITY_UNRESOLVED',
                    'message' => 'Financial authority is unresolved on Mobile V2. Bearer token proves staff/device identity only, never financial posting permission for [sales.invoice.post].',
                ],
            ]);
    }

    public function test_authenticated_actor_rate_limiter(): void
    {
        $token = $this->issueToken();
        $actorKey = 'mobile_v2:actor:'.$this->user->id.':company:'.$this->companyA->public_id;
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

        // Error request
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyB->public_id.'/read-probe');
        $this->assertFalse($context->hasCompany());
        $this->assertNull(getPermissionsTeamId());

        // Route without company parameter never activates ambient company
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/auth-probe');
        $this->assertFalse($context->hasCompany());
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_no_background_jobs_dispatched_and_no_queue_leak(): void
    {
        Queue::fake();

        $token = $this->issueToken();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mobile/v2/test-harness/companies/'.$this->companyA->public_id.'/read-probe');

        Queue::assertNothingPushed();
    }
}
