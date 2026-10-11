<?php

declare(strict_types=1);

namespace Tests\Feature\Phase11;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Application\Commands\SalesInvoiceCommands;
use App\Http\Middleware\Phase11ApiBoundary;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyUser;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Providers\Phase11ServiceProvider;
use App\Services\Security\CommandSessionGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\Support\Phase11CsrfTestMiddleware;
use Tests\TestCase;

class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;

    protected Company $companyA;

    protected User $userB;

    protected Company $companyB;

    protected CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure JSON requests transmit cookies
        $this->withCredentials();

        // Register Phase 11 provider
        $this->app->register(Phase11ServiceProvider::class);

        $this->context = app(CompanyContext::class);

        // Ensure roles & permissions exist
        Permission::findOrCreate('sales.invoice.view', 'web');
        Permission::findOrCreate('sales.invoice.create', 'web');
        Permission::findOrCreate('sales.invoice.edit_draft', 'web');
        Permission::findOrCreate('sales.invoice.post', 'web');

        // Provision User A and Company A
        $this->userA = User::factory()->create([
            'name' => 'User A',
            'email' => 'user_a@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->companyA = app(CreateCompanyAction::class)->execute($this->userA, [
            'name_ar' => 'شركة أ',
            'name_en' => 'Company A',
            'base_currency_code' => 'ILS',
            'default_locale' => 'ar',
            'timezone' => 'Asia/Hebron',
        ]);

        // Provision User B and Company B
        $this->userB = User::factory()->create([
            'name' => 'User B',
            'email' => 'user_b@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->companyB = app(CreateCompanyAction::class)->execute($this->userB, [
            'name_ar' => 'شركة ب',
            'name_en' => 'Company B',
            'base_currency_code' => 'USD',
            'default_locale' => 'en',
            'timezone' => 'UTC',
        ]);
    }

    /**
     * Helper to create an authentic database session row with serialized guard, company, marker, and password hash.
     */
    protected function createPersistedDatabaseSession(
        User $user,
        Company $company,
        ?int $marker = null,
        ?int $lastActivity = null,
        ?string $token = null
    ): array {
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        auth()->forgetGuards();
        $sessionId = Str::random(40);
        $csrfToken = $token ?? Str::random(40);
        $guardKey = auth()->guard('web')->getName();

        $sessionData = [
            '_token' => $csrfToken,
            $guardKey => $user->id,
            'password_hash_web' => $user->getAuthPassword(),
            'active_company_id' => $company->id,
        ];

        if ($marker !== null || func_num_args() < 3) {
            $sessionData['phase11_session_started_at'] = $marker ?? now()->timestamp;
        }

        $payload = base64_encode(serialize($sessionData));

        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestBrowser/1.0',
            'payload' => $payload,
            'last_activity' => $lastActivity ?? now()->timestamp,
        ]);

        return [
            'id' => $sessionId,
            'token' => $csrfToken,
        ];
    }

    public function test_disabled_api_returns_404_before_session_csrf_and_zero_db_queries(): void
    {
        config(['commands.api_enabled' => false]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        // Mutating POST request through boundary without CSRF, without auth, without session
        $request = Request::create(
            uri: "/api/v1/companies/{$this->companyA->id}/sales/invoices/draft",
            method: 'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: '{"lines":[]}'
        );

        $boundary = app(Phase11ApiBoundary::class);
        $response = $boundary->handle($request, fn () => response('OK'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->get('X-Trace-Id'));

        // Invariant: zero DB queries executed when feature is off
        $this->assertEmpty(DB::getQueryLog(), 'Expected zero database queries when API is disabled.');
    }

    public function test_unauthenticated_request_triggers_guest_limiter_and_returns_401(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'database',
        ]);

        $response = $this->getJson("/api/v1/companies/{$this->companyA->id}/sales/invoices/INV-SAMPLE");
        $response->assertStatus(401);
        $response->assertJson([
            'title' => 'Unauthorized',
            'status' => 401,
        ]);
        $response->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
        $this->assertNotEmpty($response->headers->get('X-Trace-Id'));
    }

    public function test_actual_guest_limiter_repeated_401_results_in_429(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'database',
        ]);

        $ip = '127.0.0.1';
        RateLimiter::clear("phase11:guest:{$ip}");

        // Execute 30 unauthenticated requests hitting 401
        for ($i = 0; $i < 30; $i++) {
            $res = $this->getJson("/api/v1/companies/{$this->companyA->id}/sales/invoices/INV-SAMPLE");
            $res->assertStatus(401);
        }

        // 31st unauthenticated request must be blocked by guest rate limiter returning 429
        $resBlocked = $this->getJson("/api/v1/companies/{$this->companyA->id}/sales/invoices/INV-SAMPLE");
        $resBlocked->assertStatus(429);
        $resBlocked->assertJson([
            'title' => 'Too Many Requests',
            'status' => 429,
        ]);
        $this->assertNotEmpty($resBlocked->headers->get('Retry-After'));
    }

    public function test_fresh_login_event_sets_session_marker_even_with_remember_checkbox(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'database',
        ]);

        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $session = app('session.store');
        $session->start();
        session()->setId('fresh-session-test');

        // 1. Fresh login event without remember checkbox sets marker
        session()->forget('phase11_session_started_at');
        event(new Login('web', $this->userA, false));
        $markerA = session('phase11_session_started_at');
        $this->assertNotNull($markerA);
        $this->assertIsInt($markerA);

        // 2. Legitimate fresh login event WITH remember checkbox also sets marker
        session()->forget('phase11_session_started_at');
        event(new Login('web', $this->userA, true));
        $markerB = session('phase11_session_started_at');
        $this->assertNotNull($markerB, 'Expected fresh login with remember checkbox to set phase11_session_started_at.');
        $this->assertIsInt($markerB);
    }

    public function test_future_or_expired_marker_refuses_401_without_acting_as(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'database',
        ]);

        // Future marker refused
        $sessionFuture = $this->createPersistedDatabaseSession(
            $this->userA,
            $this->companyA,
            marker: now()->timestamp + 3600
        );

        $resFuture = $this->withCookie(config('session.cookie'), $sessionFuture['id'])
            ->getJson('/api/v1/session');
        $resFuture->assertStatus(401);

        // Expired marker (> 8 hours) refused
        $sessionExpired = $this->createPersistedDatabaseSession(
            $this->userA,
            $this->companyA,
            marker: now()->timestamp - 30000
        );

        $resExpired = $this->withCookie(config('session.cookie'), $sessionExpired['id'])
            ->getJson('/api/v1/session');
        $resExpired->assertStatus(401);
    }

    public function test_non_database_session_driver_capability_refusal(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'file',
        ]);

        $response = $this->actingAs($this->userA)
            ->withSession([
                'active_company_id' => $this->companyA->id,
                'phase11_session_started_at' => now()->timestamp,
            ])
            ->getJson('/api/v1/session');

        // Capability refusal with 503 Service Unavailable
        $response->assertStatus(503);
        $response->assertJson([
            'title' => 'Service Unavailable',
            'status' => 503,
        ]);
    }

    public function test_persisted_database_session_then_revoked_and_refused_without_acting_as(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'database',
        ]);

        // Seed real persisted database session row
        $session = $this->createPersistedDatabaseSession($this->userA, $this->companyA);
        $sessionId = $session['id'];

        // 1. Demonstrate WORKING authenticated request via actual DB session cookie (NO actingAs)
        $workingResponse = $this->withCookie(config('session.cookie'), $sessionId)
            ->getJson('/api/v1/session');

        $workingResponse->assertStatus(200);
        $workingResponse->assertJsonPath('user.email', 'user_a@example.com');
        $this->assertSame($this->companyA->id, $workingResponse->json('company.id'));

        // 2. Revoke that exact session by deleting the database session row
        DB::table('sessions')->where('id', $sessionId)->delete();

        // 3. Subsequent request with the same session cookie MUST be refused with 401 (NO actingAs)
        $revokedResponse = $this->withCookie(config('session.cookie'), $sessionId)
            ->getJson('/api/v1/session');

        $revokedResponse->assertStatus(401);
        $revokedResponse->assertJson([
            'title' => 'Unauthorized',
            'status' => 401,
        ]);
    }

    public function test_separate_session_connection_fails_closed(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'database',
            'session.connection' => 'non_default_separate_connection',
            'database.connections.non_default_separate_connection' => config('database.connections.mysql'),
        ]);

        // Inner guard refuses capability when session connection differs from canonical connection
        $session = $this->createPersistedDatabaseSession($this->userA, $this->companyA);
        $resGuard = $this->withCookie(config('session.cookie'), $session['id'])
            ->getJson('/api/v1/session');

        $resGuard->assertStatus(503);

        // CommandSessionGuard also fails closed
        $guard = app(CommandSessionGuard::class);
        $this->expectException(AuthenticationException::class);
        DB::transaction(fn () => $guard->authorize($this->userA));
    }

    public function test_tenant_mismatch_returns_403_and_never_switches_active_company(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'database',
        ]);

        CompanyUser::create([
            'company_id' => $this->companyB->id,
            'user_id' => $this->userA->id,
            'role' => 'viewer',
            'status' => 'active',
        ]);

        $session = $this->createPersistedDatabaseSession($this->userA, $this->companyA);

        $response = $this->withCookie(config('session.cookie'), $session['id'])
            ->getJson("/api/v1/companies/{$this->companyB->id}/sales/invoices/INV-SAMPLE");

        $response->assertStatus(403);
        $response->assertJson([
            'title' => 'Forbidden',
            'status' => 403,
        ]);
    }

    public function test_foreign_public_id_fails_404(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'database',
        ]);

        setPermissionsTeamId($this->companyA->id);
        $this->userA->givePermissionTo('sales.invoice.view');

        $session = $this->createPersistedDatabaseSession($this->userA, $this->companyA);

        $response = $this->withCookie(config('session.cookie'), $session['id'])
            ->getJson("/api/v1/companies/{$this->companyA->id}/sales/invoices/INV-NONEXISTENT");

        $response->assertStatus(404);
        $response->assertJson([
            'title' => 'Not Found',
            'status' => 404,
        ]);
    }

    public function test_direct_view_grant_revoked_mid_session_denies_projection(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'database',
        ]);

        // Create an editor member User C in Company A (not owner, so permissions can be truly revoked)
        $userC = User::factory()->create([
            'name' => 'User C',
            'email' => 'user_c@example.com',
            'password' => bcrypt('password123'),
        ]);

        $membership = CompanyUser::create([
            'company_id' => $this->companyA->id,
            'user_id' => $userC->id,
            'is_owner' => false,
            'status' => 'active',
        ]);

        setPermissionsTeamId($this->companyA->id);
        $userC->givePermissionTo('sales.invoice.view');

        $session = $this->createPersistedDatabaseSession($userC, $this->companyA);

        $this->context->setCompany($this->companyA, $this->userA);
        $customer = Customer::create([
            'company_id' => $this->companyA->id,
            'public_id' => (string) Str::ulid(),
            'name_ar' => 'عميل اختبار',
            'created_by' => $this->userA->id,
            'active' => true,
        ]);

        $this->actingAs($this->userA);
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->companyA, $this->userA, [
            'customer_id' => $customer->id, 'currency_code' => 'ILS',
            'exchange_rate' => '1', 'issue_date' => '2026-10-11',
            'lines' => [['item_description' => 'Authorized view fixture', 'quantity' => '1', 'unit_price' => '100']],
        ]);
        auth()->forgetGuards();
        // 1. Initial view works with permission (using real session cookie)
        $resInitial = $this->withCookie(config('session.cookie'), $session['id'])
            ->getJson("/api/v1/companies/{$this->companyA->id}/sales/invoices/{$invoice->public_id}");
        $resInitial->assertStatus(200);

        // Revoke the explicit grant; default Viewer still legitimately has Sales view.
        $userC->revokePermissionTo('sales.invoice.view');
        $userC->unsetRelation('permissions')->unsetRelation('roles');
        $this->assertFalse($userC->hasPermissionTo('sales.invoice.view'));

        // 3. Subsequent view fails with 403 Forbidden
        $resDowngraded = $this->withCookie(config('session.cookie'), $session['id'])
            ->getJson("/api/v1/companies/{$this->companyA->id}/sales/invoices/{$invoice->public_id}");
        $resDowngraded->assertStatus(403);
        $resDowngraded->assertJson([
            'title' => 'Forbidden',
            'status' => 403,
        ]);
    }

    public function test_csrf_token_strictly_enforced_with_testing_bypass_disabled(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'database',
        ]);

        // Bind test subclass overriding runningUnitTests=false
        $this->app->bind(PreventRequestForgery::class, Phase11CsrfTestMiddleware::class);
        $this->app->bind(ValidateCsrfToken::class, Phase11CsrfTestMiddleware::class);

        $session = $this->createPersistedDatabaseSession($this->userA, $this->companyA);
        $url = "/api/v1/companies/{$this->companyA->id}/sales/invoices/draft";

        // 1. Request with session cookie but WITHOUT CSRF token MUST be rejected with 419
        $resNoToken = $this->withCookie(config('session.cookie'), $session['id'])
            ->withHeader('Idempotency-Key', 'csrf-key-1')
            ->postJson($url, ['lines' => []]);

        $resNoToken->assertStatus(419);
        $resNoToken->assertJson([
            'title' => 'CSRF Token Mismatch',
            'status' => 419,
        ]);

        // 2. Request with valid CSRF token passes CSRF middleware
        $resWithToken = $this->withCookie(config('session.cookie'), $session['id'])
            ->withHeader('Idempotency-Key', 'csrf-key-2')
            ->withHeader('X-CSRF-TOKEN', $session['token'])
            ->postJson($url, ['lines' => []]);

        // Passed CSRF middleware (handled by controller domain validation: lines empty => 422)
        $resWithToken->assertStatus(422);
        $this->assertNotSame(419, $resWithToken->getStatusCode());
    }

    public function test_mutation_payload_validation_and_sanitized_errors_without_sql_or_path_leak(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'database',
        ]);

        $boundary = app(Phase11ApiBoundary::class);

        // 1. Float rejection
        $reqFloat = Request::create(
            uri: '/api/v1/companies/1/sales/invoices/draft',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"quantity": 10.5}'
        );
        $resFloat = $boundary->handle($reqFloat, fn () => response('OK'));
        $this->assertSame(422, $resFloat->getStatusCode());
        $this->assertStringContainsString('Floating-point numbers are prohibited', (string) $resFloat->getContent());

        // 2. Cost injection rejection
        $reqCost = Request::create(
            uri: '/api/v1/companies/1/sales/invoices/draft',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"cost": "100.00"}'
        );
        $resCost = $boundary->handle($reqCost, fn () => response('OK'));
        $this->assertSame(422, $resCost->getStatusCode());
        $this->assertStringContainsString('unsupported', (string) $resCost->getContent());

        // 3. Non-JSON Content-Type rejection
        $reqType = Request::create(
            uri: '/api/v1/companies/1/sales/invoices/draft',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'text/plain'],
            content: 'raw-body'
        );
        $resType = $boundary->handle($reqType, fn () => response('OK'));
        $this->assertSame(415, $resType->getStatusCode());

        // 4. Large payload (> 64 KiB) rejection
        $reqLarge = Request::create(
            uri: '/api/v1/companies/1/sales/invoices/draft',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['data' => str_repeat('X', 66000)])
        );
        $resLarge = $boundary->handle($reqLarge, fn () => response('OK'));
        $this->assertSame(413, $resLarge->getStatusCode());

        // Invariant: errors must not leak SQL syntax, table names, or private file paths
        $content = (string) $resCost->getContent();
        $this->assertStringNotContainsString('SELECT', $content);
        $this->assertStringNotContainsString('INSERT', $content);
        $this->assertStringNotContainsString('database', $content);
        $this->assertStringNotContainsString('C:\\', $content);
        $this->assertStringNotContainsString('/home/', $content);
    }

    public function test_unexpected_rendered_error_with_app_debug_true_sanitizes_response_and_logs(): void
    {
        config([
            'app.debug' => true,
            'commands.api_enabled' => true,
            'session.driver' => 'database',
        ]);

        $boundary = app(Phase11ApiBoundary::class);

        $sentinelSql = 'SELECT password_hash FROM internal_secret_credentials WHERE id=99';
        $sentinelPath = 'C:\\var\\secret\\credentials.env';
        $sentinelToken = 'SECRET_TOKEN_XYZ_123';

        // Mock next middleware throwing an unexpected error with sentinels
        $request = Request::create(
            uri: '/api/v1/session',
            method: 'GET',
            server: ['HTTP_ACCEPT' => 'application/json']
        );

        $logs = [];
        Log::listen(function ($level, $message = null, $context = []) use (&$logs) {
            $logs[] = [
                'level' => $level,
                'message' => is_string($level) ? $message : $level->message,
                'context' => is_array($message) ? $message : $context,
            ];
        });

        $response = $boundary->handle($request, function () use ($sentinelSql, $sentinelPath, $sentinelToken) {
            throw new RuntimeException("DB Failure: {$sentinelSql} in {$sentinelPath} with token {$sentinelToken}");
        });

        // 1. Response must be sanitized 500 problem JSON
        $this->assertSame(500, $response->getStatusCode());
        $content = (string) $response->getContent();

        $this->assertStringNotContainsString($sentinelSql, $content);
        $this->assertStringNotContainsString($sentinelPath, $content);
        $this->assertStringNotContainsString($sentinelToken, $content);
        $this->assertStringNotContainsString('RuntimeException', $content);
        $this->assertStringContainsString('Internal Server Error', $content);

        // 2. Logs must contain ONLY exception class and trace ID, never sentinel values
        $serializedLogs = serialize($logs);
        $this->assertStringNotContainsString($sentinelSql, $serializedLogs);
        $this->assertStringNotContainsString($sentinelPath, $serializedLogs);
        $this->assertStringNotContainsString($sentinelToken, $serializedLogs);
    }

    public function test_all_lookups_exercise_actual_schema_without_cost_leak(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'database',
        ]);

        setPermissionsTeamId($this->companyA->id);
        $this->userA->givePermissionTo('sales.invoice.view');

        $session = $this->createPersistedDatabaseSession($this->userA, $this->companyA);

        $this->context->setCompany($this->companyA, $this->userA);

        // Seed customer
        Customer::create([
            'company_id' => $this->companyA->id,
            'public_id' => (string) Str::ulid(),
            'name_ar' => 'عميل تجريبي',
            'created_by' => $this->userA->id,
            'active' => true,
        ]);

        // Seed warehouse
        Warehouse::create([
            'company_id' => $this->companyA->id,
            'public_id' => (string) Str::ulid(),
            'code' => 'WH-01',
            'name_ar' => 'المستودع الرئيسي',
            'active' => true,
            'is_default' => true,
            'created_by' => $this->userA->id,
        ]);

        // Seed currency
        $this->assertNotNull(Currency::where('code', 'ILS')->first());
        CompanyCurrency::updateOrCreate([
            'company_id' => $this->companyA->id,
            'currency_code' => 'ILS',
            'enabled' => true,
            'is_base' => true,
            'display_order' => 1,
        ]);

        // Seed tax rate using authoritative schema (no is_default column)
        TaxRate::create([
            'company_id' => $this->companyA->id,
            'public_id' => (string) Str::ulid(),
            'code' => 'VAT-16',
            'name_ar' => 'ضريبة القيمة المضافة',
            'rate' => '16.000000',
            'calculation' => 'exclusive',
            'active' => true,
        ]);

        // Seed product
        Product::create([
            'company_id' => $this->companyA->id,
            'public_id' => (string) Str::ulid(),
            'sku' => 'PROD-001',
            'name_ar' => 'منتج تجريبي',
            'product_type' => 'stock',
            'base_unit_id' => Unit::where('company_id', $this->companyA->id)->where('code', 'piece')->firstOrFail()->id,
            'active' => true,
            'default_sale_price_base' => '50.000000',
            'default_purchase_cost_base' => '30.000000',
            'created_by' => $this->userA->id,
        ]);

        $base = "/api/v1/companies/{$this->companyA->id}/lookups";
        $cookies = [config('session.cookie') => $session['id']];

        // 1. Customers lookup
        $resCust = $this->withCookies($cookies)->getJson("{$base}/customers");
        $resCust->assertStatus(200);
        $this->assertNotEmpty($resCust->json('data'));

        // 2. Warehouses lookup
        $resWh = $this->withCookies($cookies)->getJson("{$base}/warehouses");
        $resWh->assertStatus(200);
        $this->assertNotEmpty($resWh->json('data'));

        // 3. Currencies lookup
        $resCurr = $this->withCookies($cookies)->getJson("{$base}/currencies");
        $resCurr->assertStatus(200);
        $this->assertNotEmpty($resCurr->json('data'));

        // 4. Taxes lookup (verifies no is_default column error)
        $resTax = $this->withCookies($cookies)->getJson("{$base}/taxes");
        $resTax->assertStatus(200);
        $this->assertNotEmpty($resTax->json('data'));
        $this->assertSame('16.000000', $resTax->json('data.0.rate'));

        // 5. Products lookup (verifies default_purchase_cost_base is NOT exposed)
        $resProd = $this->withCookies($cookies)->getJson("{$base}/products");
        $resProd->assertStatus(200);
        $prodData = $resProd->json('data.0');
        $this->assertSame('50.000000', $prodData['sale_price_suggestion']);
        $this->assertArrayNotHasKey('default_purchase_cost_base', $prodData);
        $this->assertArrayNotHasKey('cost', $prodData);
    }

    public function test_foreign_session_handle_revocation_denied(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'database',
        ]);

        $sessionB = $this->createPersistedDatabaseSession($this->userB, $this->companyB);
        $sessionA = $this->createPersistedDatabaseSession($this->userA, $this->companyA);

        $keyB = config('app.key').':session:'.$this->userB->id;
        $handleB = hash_hmac('sha256', $sessionB['id'], $keyB);

        $response = $this->actingAs($this->userA)
            ->withSession([
                'auth.password_confirmed_at' => now()->timestamp,
                'active_company_id' => $this->companyA->id,
                'phase11_session_started_at' => now()->timestamp,
            ])
            ->withCookie(config('session.cookie'), $sessionA['id'])
            ->deleteJson("/api/v1/sessions/{$handleB}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('sessions', ['id' => $sessionB['id']]);
    }

    public function test_sales_invoice_conflict_distinguished_from_internal_error(): void
    {
        config([
            'commands.api_enabled' => true,
            'session.driver' => 'database',
        ]);

        setPermissionsTeamId($this->companyA->id);
        $this->userA->givePermissionTo('sales.invoice.create');

        $session = $this->createPersistedDatabaseSession($this->userA, $this->companyA);
        $url = "/api/v1/companies/{$this->companyA->id}/sales/invoices/draft";

        // Bind mock commands service that throws an expected lifecycle status conflict
        $mockCommands = $this->createStub(SalesInvoiceCommands::class);
        $mockCommands->method('create')
            ->willThrowException(new \InvalidArgumentException('Cannot edit sales invoice in posted status. Only drafts can be edited.'));
        $this->app->instance(SalesInvoiceCommands::class, $mockCommands);

        $resConflict = $this->withCookie(config('session.cookie'), $session['id'])
            ->withHeader('Idempotency-Key', 'test-key-conflict')
            ->postJson($url, ['lines' => [['product_id' => 1, 'quantity' => '5.000000', 'unit_price' => '10.000000']]]);

        $resConflict->assertStatus(409);
        $resConflict->assertJson([
            'title' => 'Conflict',
            'status' => 409,
        ]);

        // Bind mock commands service that throws an unexpected unexpected error
        $mockError = $this->createStub(SalesInvoiceCommands::class);
        $mockError->method('create')
            ->willThrowException(new RuntimeException('Fatal unexpected database driver error'));
        $this->app->instance(SalesInvoiceCommands::class, $mockError);
        app('router')->getRoutes()->getByName('api.v1.sales.invoices.draft')->flushController();

        $resError = $this->withCookie(config('session.cookie'), $session['id'])
            ->withHeader('Idempotency-Key', 'test-key-error')
            ->postJson($url, ['lines' => [['product_id' => 1, 'quantity' => '5.000000', 'unit_price' => '10.000000']]]);

        $resError->assertStatus(500);
        $resError->assertJson([
            'title' => 'Internal Server Error',
            'status' => 500,
        ]);
        $this->assertStringNotContainsString('Fatal unexpected database driver error', (string) $resError->getContent());
    }
}
