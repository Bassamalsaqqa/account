<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostics;

use App\Actions\Company\CreateCompanyAction;
use App\Application\Platform\PlatformAccess;
use App\Application\Platform\PlatformAudit;
use App\Models\BugReport;
use App\Models\Company;
use App\Models\PlatformAuditEvent;
use App\Models\PlatformOperatorGrant;
use App\Models\User;
use App\Services\Operations\DTO\ReconciliationRunResult;
use App\Services\Operations\ReconciliationAlertService;
use App\Support\Diagnostics\RequestId;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class BugReportIntakeSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'name' => 'Canary Owner SecretName',
            'email' => 'canary.owner@example.test',
            'two_factor_secret' => Crypt::encrypt('CANARY_TOTP_SECRET_999'),
            'two_factor_recovery_codes' => Crypt::encrypt(json_encode(['CANARY_RECOVERY_CODE_777'])),
        ]);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة الكناري الاختبارية',
            'name_en' => 'Canary Trading LLC',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->clear();
    }

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'idempotency_key' => (string) Str::uuid(),
            'category' => 'save_error',
            'note' => 'Clicking confirm did not advance the screen.',
            'include_diagnostics' => true,
            'screen' => [
                'route_name' => 'dashboard',
                'page_request_id' => 'req_'.str_repeat('1', 32),
                'viewport' => 'desktop',
            ],
        ], $overrides);
    }

    public function test_guest_intake_is_denied_and_no_guest_route_exists(): void
    {
        $this->postJson(route('diagnostics.bug-reports.store'), $this->validPayload())
            ->assertUnauthorized();

        $this->assertDatabaseCount('bug_reports', 0);
    }

    public function test_csrf_is_enforced_for_authenticated_intake(): void
    {
        $this->app->instance(PreventRequestForgery::class,
            new class($this->app, $this->app->make(Encrypter::class)) extends PreventRequestForgery
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            });
        $this->actingAs($this->owner);

        $response = $this->call(
            'POST',
            route('diagnostics.bug-reports.store'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            json_encode($this->validPayload(), JSON_THROW_ON_ERROR),
        );

        $response->assertStatus(419);
        $this->assertDatabaseCount('bug_reports', 0);
    }

    public function test_restricted_users_without_company_and_platform_operators_can_submit_outside_company_ensure(): void
    {
        // 1. User with zero companies
        $zeroCompanyUser = User::factory()->create();
        $resZero = $this->actingAs($zeroCompanyUser)
            ->postJson(route('diagnostics.bug-reports.store'), $this->validPayload([
                'screen' => ['route_name' => 'companies.setup'],
            ]));
        $resZero->assertCreated()->assertJson(['status' => 'received', 'idempotent_replay' => false]);

        $zeroRow = BugReport::query()->where('public_ref', $resZero->json('public_ref'))->firstOrFail();
        $this->assertSame($zeroCompanyUser->id, $zeroRow->reporter_user_id);
        $this->assertSame($zeroCompanyUser->id, $zeroRow->effective_user_id);
        $this->assertNull($zeroRow->company_id);

        // 2. User with two companies and no active selection
        $multiUser = User::factory()->create();
        app(CreateCompanyAction::class)->execute($multiUser, ['name_ar' => 'شركة 1', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->clear();
        app(CreateCompanyAction::class)->execute($multiUser, ['name_ar' => 'شركة 2', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->clear();

        $multiUser->updateQuietly(['last_active_company_id' => null]);
        session()->forget('active_company_id');

        $resMulti = $this->actingAs($multiUser)
            ->postJson(route('diagnostics.bug-reports.store'), $this->validPayload([
                'screen' => ['route_name' => 'companies.select'],
            ]));
        $resMulti->assertCreated();
        $this->assertNull(BugReport::query()->where('public_ref', $resMulti->json('public_ref'))->value('company_id'));

        // 3. Platform operator without tenant memberships
        $operator = User::factory()->create();
        $grant = new PlatformOperatorGrant;
        $grant->forceFill(['user_id' => $operator->id, 'active' => true, 'permissions' => ['company.read'], 'revision' => 1])->save();

        $resOp = $this->actingAs($operator)
            ->postJson(route('diagnostics.bug-reports.store'), $this->validPayload([
                'screen' => ['route_name' => 'platform.index'],
            ]));
        $resOp->assertCreated();
        $opRow = BugReport::query()->where('public_ref', $resOp->json('public_ref'))->firstOrFail();
        $this->assertNull($opRow->company_id);
        $this->assertTrue((bool) ($opRow->sanitized_context['server']['has_platform_grant'] ?? false));
    }

    public function test_support_impersonation_captures_actual_operator_effective_owner_and_company(): void
    {
        $operator = User::factory()->create(['name' => 'Real Support Operator']);
        $grant = new PlatformOperatorGrant;
        $grant->forceFill(['user_id' => $operator->id, 'active' => true, 'permissions' => PlatformAccess::PERMISSIONS, 'revision' => 1])->save();

        $this->post('/login', ['email' => $operator->email, 'password' => 'password'])->assertRedirect();
        $this->post(route('platform.support.start', $this->company->public_id), [
            'reason' => 'Investigate invoice rendering issue',
            'current_password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $response = $this->postJson(route('diagnostics.bug-reports.store'), $this->validPayload([
            'screen' => ['route_name' => 'invoices.create'],
        ]));
        $response->assertCreated();

        $row = BugReport::query()->where('public_ref', $response->json('public_ref'))->firstOrFail();
        $this->assertSame($operator->id, $row->reporter_user_id);
        $this->assertSame($this->owner->id, $row->effective_user_id);
        $this->assertSame($this->company->id, $row->company_id);
        $this->assertTrue($row->is_impersonated);
        $this->assertSame($operator->id, auth()->id());
    }

    public function test_unhandled_exception_during_impersonation_still_logs_captured_actor_and_company_safely(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/__test/impersonated-crash', static function (): never {
            throw new RuntimeException('CANARY_RAW_SQL SELECT two_factor_secret FROM users WHERE amount=12345.67');
        });

        $operator = User::factory()->create();
        $grant = new PlatformOperatorGrant;
        $grant->forceFill(['user_id' => $operator->id, 'active' => true, 'permissions' => PlatformAccess::PERMISSIONS, 'revision' => 1])->save();

        $this->post('/login', ['email' => $operator->email, 'password' => 'password'])->assertRedirect();
        $this->post(route('platform.support.start', $this->company->public_id), [
            'reason' => 'Reproduce crash cleanly',
            'current_password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $logs = [];
        Log::listen(static function ($entry) use (&$logs): void {
            $logs[] = ['message' => $entry->message, 'context' => $entry->context];
        });

        $response = $this->get('/__test/impersonated-crash');
        $response->assertStatus(500);

        $headerId = $response->headers->get(RequestId::HEADER);
        $this->assertTrue(RequestId::isValid($headerId));
        $response->assertSee($headerId);
        $response->assertDontSee('CANARY_RAW_SQL');
        $response->assertDontSee('12345.67');
        $response->assertDontSee('<script', false);

        $this->assertNotEmpty($logs);
        $serializedLogs = json_encode($logs, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('CANARY_RAW_SQL', $serializedLogs);
        $this->assertStringNotContainsString('12345.67', $serializedLogs);
        $this->assertStringContainsString($headerId, $serializedLogs);

        $crashCtx = $logs[0]['context'];
        $this->assertSame($operator->id, $crashCtx['actual_user_id'] ?? null);
        $this->assertSame($this->owner->id, $crashCtx['effective_user_id'] ?? null);
        $this->assertSame($this->company->id, $crashCtx['company_id'] ?? null);
        $this->assertTrue((bool) ($crashCtx['is_impersonated'] ?? false));
    }

    public function test_intake_enforces_size_schema_depth_and_enum_bounds(): void
    {
        $this->actingAs($this->owner);

        // Oversized body > 16 KiB -> 413
        $huge = $this->validPayload(['note' => str_repeat('a', 17000)]);
        $this->postJson(route('diagnostics.bug-reports.store'), $huge)->assertStatus(413);

        // Non-JSON content-type -> 415
        $this->post(route('diagnostics.bug-reports.store'), $this->validPayload())->assertStatus(415);

        // Unknown top-level key -> 422
        $this->postJson(route('diagnostics.bug-reports.store'), $this->validPayload(['company_id' => 999]))
            ->assertStatus(422);

        // Unknown nested screen key -> 422
        $this->postJson(route('diagnostics.bug-reports.store'), $this->validPayload(['screen' => ['url' => 'https://example.test/?secret=1']]))
            ->assertStatus(422);

        // Excessive JSON depth -> 422
        $deepJson = json_encode([
            'idempotency_key' => (string) Str::uuid(),
            'category' => 'other',
            'note' => 'Deep payload test',
            'include_diagnostics' => true,
            'screen' => ['route_name' => ['nested' => ['deep' => 1]]],
        ], JSON_THROW_ON_ERROR);
        $this->call('POST', route('diagnostics.bug-reports.store'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => csrf_token(),
        ], $deepJson)->assertStatus(422);

        // Invalid category -> 422
        $this->postJson(route('diagnostics.bug-reports.store'), $this->validPayload(['category' => 'sql_dump']))
            ->assertStatus(422);

        // Note too long (>1000) or control char -> 422
        $this->postJson(route('diagnostics.bug-reports.store'), $this->validPayload(['note' => str_repeat('x', 1001)]))
            ->assertStatus(422);
        $this->postJson(route('diagnostics.bug-reports.store'), $this->validPayload(['note' => "Bad\x00note"]))
            ->assertStatus(422);

        // Forged page_request_id -> 422
        $this->postJson(route('diagnostics.bug-reports.store'), $this->validPayload([
            'screen' => ['page_request_id' => 'client-spoofed-123'],
        ]))->assertStatus(422);

        $this->assertDatabaseCount('bug_reports', 0);
    }

    public function test_include_diagnostics_false_omits_optional_context_and_client_routes_never_grant_tenant_scope(): void
    {
        $this->actingAs($this->owner);

        $res = $this->postJson(route('diagnostics.bug-reports.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'category' => 'other',
            'note' => 'Opted out of technical diagnostics.',
            'include_diagnostics' => false,
            'screen' => [
                'route_name' => 'unknown.forged.route',
                'page_request_id' => 'req_'.str_repeat('2', 32),
            ],
        ]);
        $res->assertCreated();

        $row = BugReport::query()->where('public_ref', $res->json('public_ref'))->firstOrFail();
        $this->assertFalse($row->include_diagnostics);
        $this->assertNull($row->release_version);
        $this->assertNull($row->release_source_sha);
        $this->assertNull($row->asset_manifest_sha256);
        $this->assertNull($row->route_name);
        $this->assertNull($row->screen_path);
        $this->assertNull($row->request_id);
        $this->assertNull($row->sanitized_context);
        $this->assertSame($this->company->id, $row->company_id);
    }

    public function test_idempotency_returns_same_ref_on_replay_and_409_on_changed_payload(): void
    {
        $this->actingAs($this->owner);
        $key = (string) Str::uuid();
        $payload = $this->validPayload(['idempotency_key' => $key]);

        $first = $this->postJson(route('diagnostics.bug-reports.store'), $payload)->assertCreated();
        $ref = $first->json('public_ref');
        $this->assertFalse($first->json('idempotent_replay'));

        $replay = $this->postJson(route('diagnostics.bug-reports.store'), $payload)->assertOk();
        $this->assertSame($ref, $replay->json('public_ref'));
        $this->assertTrue($replay->json('idempotent_replay'));
        $this->assertSame(1, BugReport::query()->count());

        $changed = $this->validPayload(['idempotency_key' => $key, 'note' => 'Different note on same idempotency key.']);
        $this->postJson(route('diagnostics.bug-reports.store'), $changed)->assertStatus(409);
        $this->assertSame(1, BugReport::query()->count());
    }

    public function test_rate_limit_blocks_repeated_intake_bursts_and_tenant_cannot_read_by_public_ref(): void
    {
        $user = User::factory()->create();
        RateLimiter::clear('bug_report:ip:127.0.0.1');
        RateLimiter::clear('bug_report:actor:'.$user->id);

        $this->actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('diagnostics.bug-reports.store'), $this->validPayload([
                'note' => "Rate limit test report #{$i}",
            ]))->assertCreated();
        }

        $blocked = $this->postJson(route('diagnostics.bug-reports.store'), $this->validPayload([
            'note' => 'Rate limit exceeded test report',
        ]));
        $blocked->assertStatus(429);
        $this->assertNotEmpty($blocked->headers->get('Retry-After'));

        $firstRef = BugReport::query()->value('public_ref');
        $this->get('/diagnostics/bug-reports/'.$firstRef)->assertNotFound();
        $this->get(route('platform.bug-reports.show', $firstRef))->assertForbidden();
    }

    public function test_ordinary_page_load_adds_zero_bug_report_queries_and_preserves_api_boundaries(): void
    {
        $this->actingAs($this->owner);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $dash = $this->get(route('dashboard'))->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertStringNotContainsString('bug_reports', $queries);
        $dash->assertSee('data-report-problem-trigger', false);
        $dash->assertSee('id="problem-reporter-template"', false);
        $this->assertTrue(RequestId::isValid($dash->headers->get(RequestId::HEADER)));

        // Preserve API v1 disabled 404 X-Trace-Id & security headers without adding web X-Request-Id.
        config(['commands.api_enabled' => false]);
        $apiRes = $this->getJson('/api/v1/commands/unknown');
        $apiRes->assertStatus(404);
        $this->assertNotEmpty($apiRes->headers->get('X-Trace-Id'));
        $apiRes->assertHeaderMissing(RequestId::HEADER);
    }

    public function test_logger_failure_is_caught_preserving_http_500_response(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/__test/web-boom', static function (): never {
            throw new RuntimeException('Canary web crash');
        });

        Log::shouldReceive('error')->andThrow(new RuntimeException('Unwritable log storage'));

        $response = $this->get('/__test/web-boom');
        $response->assertStatus(500);
        $this->assertTrue(RequestId::isValid($response->headers->get(RequestId::HEADER)));
        $response->assertSee($response->headers->get(RequestId::HEADER));
    }

    public function test_reporter_canary_secrets_never_appear_in_stored_payloads_or_response(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson(route('diagnostics.bug-reports.store'), $this->validPayload([
            'screen' => [
                'route_name' => 'invoices.create',
                'page_request_id' => 'req_'.str_repeat('a', 32),
                'failed_request_id' => 'req_'.str_repeat('b', 32),
                'failed_status' => 500,
                'livewire_failed' => true,
            ],
        ]));
        $response->assertCreated();

        $row = BugReport::query()->where('public_ref', $response->json('public_ref'))->firstOrFail();
        $serialized = json_encode([$row->toArray(), $response->json()], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('CANARY_TOTP_SECRET_999', $serialized);
        $this->assertStringNotContainsString('CANARY_RECOVERY_CODE_777', $serialized);
        $this->assertStringNotContainsString('canary.owner@example.test', $serialized);
        $this->assertStringNotContainsString('Canary Owner SecretName', $serialized);
        $this->assertStringNotContainsString('Canary Trading LLC', $serialized);
    }

    public function test_context_construction_canary_and_logger_failure_fall_through_framework_without_leaks(): void
    {
        config(['app.debug' => false]);
        $capturedErrors = [];
        Log::listen(static function ($entry) use (&$capturedErrors): void {
            if ($entry->level === 'error') {
                $capturedErrors[] = ['message' => $entry->message, 'context' => $entry->context];
            }
        });

        Route::middleware('web')->get('/__test/context-canary-boom', static function (Request $request): never {
            $realRoute = $request->route();
            $armed = true;
            $request->setRouteResolver(static function () use ($realRoute, &$armed) {
                if ($armed) {
                    $armed = false;
                    throw new RuntimeException('CANARY_CTX_SQL SELECT secret FROM users');
                }

                return $realRoute;
            });

            throw new RuntimeException('CANARY_INNER_EXCEPTION_SQL amount=9999.99');
        });

        $first = $this->get('/__test/context-canary-boom');
        $first->assertStatus(500);
        $requestId = $first->headers->get(RequestId::HEADER);
        $this->assertTrue(RequestId::isValid($requestId));
        $first->assertHeader('X-Content-Type-Options', 'nosniff');
        $first->assertSee($requestId);
        $this->assertStringNotContainsString('CANARY_CTX_SQL', $first->getContent());
        $this->assertStringNotContainsString('CANARY_INNER_EXCEPTION_SQL', $first->getContent());
        $this->assertStringNotContainsString('9999.99', $first->getContent());

        // Strictly one sanitized framework log entry (zero duplicates, zero canary SQL/messages)
        $this->assertCount(1, $capturedErrors);
        $logJson = json_encode($capturedErrors, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('CANARY_CTX_SQL', $logJson);
        $this->assertStringNotContainsString('CANARY_INNER_EXCEPTION_SQL', $logJson);

        // API v1 falls through to Phase11ApiBoundary without adding web X-Request-Id
        config(['commands.api_enabled' => false]);
        $api = $this->getJson('/api/v1/commands/unknown');
        $api->assertStatus(404);
        $this->assertNotEmpty($api->headers->get('X-Trace-Id'));
        $api->assertHeaderMissing(RequestId::HEADER);

        // Now combine context-construction canary WITH total logger failure through the real framework
        Log::shouldReceive('error')->andThrow(new RuntimeException('CANARY_LOG_STORAGE_DOWN'));

        $second = $this->get('/__test/context-canary-boom');
        $second->assertStatus(500);
        $secondId = $second->headers->get(RequestId::HEADER);
        $this->assertTrue(RequestId::isValid($secondId));
        $second->assertSee($secondId);
        $this->assertStringNotContainsString('CANARY_CTX_SQL', $second->getContent());
        $this->assertStringNotContainsString('CANARY_INNER_EXCEPTION_SQL', $second->getContent());
        $this->assertStringNotContainsString('CANARY_LOG_STORAGE_DOWN', $second->getContent());
    }

    public function test_exception_isolation_preserves_financial_responses_and_security_operational_alerts(): void
    {
        config(['app.debug' => false, 'reconciliation.alerts.enabled' => false]);
        $warnings = [];
        Log::listen(static function ($entry) use (&$warnings): void {
            if ($entry->level === 'warning') {
                $warnings[] = ['message' => $entry->message, 'context' => $entry->context];
            }
        });

        Route::middleware('web')->get('/__test/context-alert-isolation', static function (Request $request): never {
            $realRoute = $request->route();
            $armed = true;
            $request->setRouteResolver(static function () use ($realRoute, &$armed) {
                if ($armed) {
                    $armed = false;
                    throw new RuntimeException('CANARY_ROUTE_FAILURE_SQL');
                }

                return $realRoute;
            });

            throw new RuntimeException('CANARY_UNHANDLED_SQL');
        });

        $crash = $this->get('/__test/context-alert-isolation');
        $crash->assertStatus(500);

        // Operational reconciliation alert and platform security audit must remain unsuppressed
        $alertResult = new ReconciliationRunResult(
            status: 'NONZERO_FINDINGS',
            durationSeconds: 0.25,
            companiesAudited: 1,
            domainSummary: ['accounting' => ['healthy' => false, 'violation_count' => 1]],
            findings: [[
                'company_public_id' => $this->company->public_id,
                'domain' => 'accounting',
                'category' => 'trial_balance',
                'status' => 'violation',
                'count' => 1,
            ]],
        );
        app(ReconciliationAlertService::class)->sendNonzeroAlert($alertResult);

        $this->assertCount(1, $warnings);
        $this->assertSame('Reconciliation integrity violations detected during audit.', $warnings[0]['message']);

        app(PlatformAudit::class)->record('support.started', $this->owner->id, $this->company->id, ['reason' => 'diagnostics-check']);
        $this->assertTrue(
            PlatformAuditEvent::query()->where('event_key', 'support.started')->where('actor_user_id', $this->owner->id)->exists()
        );

        // Financial UI / draft creation route still responds cleanly after isolated reporter failure
        $this->actingAs($this->owner)
            ->withSession(['active_company_id' => $this->company->id])
            ->get(route('invoices.index'))
            ->assertOk();
    }
}
