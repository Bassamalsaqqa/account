<?php

declare(strict_types=1);

namespace Tests\Feature\AiV22;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AssistantTurnService;
use App\Application\AI\AssistantUnavailable;
use App\Models\AiCompanyPolicy;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\Support\Phase11CsrfTestMiddleware;
use Tests\TestCase;

class AssistantTurnApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'commands.api_enabled' => true,
            'ai_assistant.enabled' => true,
            'ai_assistant.turn_enabled' => true,
            'session.driver' => 'database',
        ]);
        $this->withCredentials();
        $this->app->bind(PreventRequestForgery::class, Phase11CsrfTestMiddleware::class);
        $this->app->bind(ValidateCsrfToken::class, Phase11CsrfTestMiddleware::class);
        Permission::findOrCreate('ai.assistant.use', 'web');
        $this->actor = User::factory()->create();
        $this->company = app(CreateCompanyAction::class)->execute($this->actor, [
            'name_ar' => 'Test company', 'name_en' => 'Test company', 'base_currency_code' => 'ILS',
            'default_locale' => 'en', 'timezone' => 'Asia/Hebron',
        ]);
        setPermissionsTeamId($this->company->id);
        $this->actor->givePermissionTo('ai.assistant.use');
    }

    public function test_authenticated_turn_runs_outside_company_and_session_lock_transaction(): void
    {
        $session = $this->persistedSession();
        $key = (string) Str::uuid();
        $conversation = (string) Str::uuid();
        $transactionLevel = DB::transactionLevel();
        $mock = Mockery::mock(AssistantTurnService::class);
        $mock->shouldReceive('run')->once()->withArgs(function ($company, $actor, $text, $requestKey, $sessionId, $token) use ($session, $key, $conversation, $transactionLevel): bool {
            $this->assertSame($transactionLevel, DB::transactionLevel(), 'API locks must be released before provider I/O.');

            return $company->is($this->company) && $actor->is($this->actor) && $text === 'Sales today'
                && $requestKey === $key && $sessionId === $session['id'] && $token === $conversation;
        })->andReturn($this->answer($key));
        $this->app->instance(AssistantTurnService::class, $mock);

        $response = $this->withCookie(config('session.cookie'), $session['id'])
            ->withHeader('X-CSRF-TOKEN', $session['token'])
            ->postJson($this->url(), ['text' => 'Sales today', 'request_key' => $key, 'conversation_token' => $conversation, 'source' => 'voice']);
        $response->assertOk()->assertJsonPath('status', 'answer')->assertJsonPath('request_key', $key);
        $response->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
        $this->assertNotEmpty($response->headers->get('X-Trace-Id'));
    }

    public function test_actual_read_only_turn_executes_canonical_report_and_strips_internal_delivery_scope(): void
    {
        config(['ai_assistant.questions.enabled' => true,
            'ai_assistant.request_cost_ceiling_micros' => 1000,
            'ai_assistant.daily_company_cost_ceiling_micros' => 10000,
            'ai_assistant.monthly_company_cost_ceiling_micros' => 100000,
            'ai_assistant.daily_user_cost_ceiling_micros' => 10000,
            'ai_assistant.monthly_user_cost_ceiling_micros' => 100000]);
        $this->actingAs($this->actor);
        app(CompanyContext::class)->setCompany($this->company, $this->actor);
        AiCompanyPolicy::create(['company_id' => $this->company->id, 'enabled' => true, 'follow_up_enabled' => true,
            'settings' => ['questions' => ['enabled' => true, 'allowed_reports' => ['inventory.low-stock']],
                'limits' => ['request_micros' => 1000, 'daily_company_micros' => 10000, 'monthly_company_micros' => 100000,
                    'daily_user_micros' => 10000, 'monthly_user_micros' => 100000,
                    'daily_company_requests' => 100, 'daily_user_requests' => 25]]]);
        Http::preventStrayRequests();
        Http::fake();
        $before = DB::table('posting_lines')->count();
        $session = $this->persistedSession();
        $response = $this->withCookie(config('session.cookie'), $session['id'])->withHeader('X-CSRF-TOKEN', $session['token'])
            ->postJson($this->url(), ['text' => 'What items are running low?', 'request_key' => (string) Str::uuid()]);
        $response->assertOk()->assertJsonPath('status', 'answer')->assertJsonPath('model_calls', 0)
            ->assertJsonPath('reports.0.report_key', 'inventory.low-stock')->assertJsonPath('reports.0.totals.total_alert_count', 0);
        $this->assertStringNotContainsString('_authorization_scope', $response->getContent());
        $this->assertSame($before, DB::table('posting_lines')->count());
        $this->assertSame(1, DB::table('assistant_requests')->count());
        Http::assertNothingSent();
    }

    public function test_changed_projection_scope_is_refused_before_sensitive_answer_delivery(): void
    {
        config(['ai_assistant.questions.enabled' => true]);
        $this->actingAs($this->actor);
        app(CompanyContext::class)->setCompany($this->company, $this->actor);
        AiCompanyPolicy::create(['company_id' => $this->company->id, 'enabled' => true,
            'settings' => ['questions' => ['enabled' => true, 'allowed_reports' => ['money.balances']]]]);
        $session = $this->persistedSession();
        $payload = $this->payload();
        $mock = Mockery::mock(AssistantTurnService::class);
        $mock->shouldReceive('run')->once()->andReturn([
            ...$this->answer($payload['request_key']), '_authorization_scope' => str_repeat('0', 64),
            'reports' => [['secret' => 'previously-authorized-cash-result']],
        ]);
        $this->app->instance(AssistantTurnService::class, $mock);
        $response = $this->withCookie(config('session.cookie'), $session['id'])
            ->withHeader('X-CSRF-TOKEN', $session['token'])->postJson($this->url(), $payload);
        $response->assertStatus(503)->assertJsonPath('category', 'context_expired');
        $this->assertStringNotContainsString('previously-authorized-cash-result', $response->getContent());
        $this->assertStringNotContainsString('_authorization_scope', $response->getContent());
    }

    public function test_wrong_company_is_rejected_before_service(): void
    {
        $this->serviceMustNotRun();
        $session = $this->persistedSession();
        $this->withCookie(config('session.cookie'), $session['id'])->withHeader('X-CSRF-TOKEN', $session['token'])
            ->postJson('/api/v1/companies/'.Str::uuid().'/assistant/turn', $this->payload())->assertForbidden();
    }

    public function test_revoked_persisted_session_is_rejected_before_service(): void
    {
        $this->serviceMustNotRun();
        $session = $this->persistedSession();
        DB::table('sessions')->where('id', $session['id'])->delete();
        $this->withCookie(config('session.cookie'), $session['id'])->withHeader('X-CSRF-TOKEN', $session['token'])
            ->postJson($this->url(), $this->payload())->assertStatus(419);
    }

    public function test_csrf_is_required_for_a_real_authenticated_database_session(): void
    {
        $this->serviceMustNotRun();
        $session = $this->persistedSession();
        $this->withCookie(config('session.cookie'), $session['id'])
            ->postJson($this->url(), $this->payload())->assertStatus(419)->assertJsonPath('title', 'CSRF Token Mismatch');
    }

    public function test_session_revocation_during_provider_io_prevents_answer_release(): void
    {
        $session = $this->persistedSession();
        $mock = Mockery::mock(AssistantTurnService::class);
        $mock->shouldReceive('run')->once()->andReturnUsing(function () use ($session): array {
            DB::table('sessions')->where('id', $session['id'])->delete();

            return $this->answer((string) Str::uuid());
        });
        $this->app->instance(AssistantTurnService::class, $mock);
        $response = $this->withCookie(config('session.cookie'), $session['id'])->withHeader('X-CSRF-TOKEN', $session['token'])
            ->postJson($this->url(), $this->payload());
        $response->assertUnauthorized()->assertJsonMissingPath('reports');
    }

    public function test_membership_revocation_during_provider_io_prevents_answer_release(): void
    {
        $session = $this->persistedSession();
        $mock = Mockery::mock(AssistantTurnService::class);
        $mock->shouldReceive('run')->once()->andReturnUsing(function (): array {
            DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->actor->id)
                ->update(['status' => 'inactive']);

            return $this->answer((string) Str::uuid());
        });
        $this->app->instance(AssistantTurnService::class, $mock);
        $response = $this->withCookie(config('session.cookie'), $session['id'])->withHeader('X-CSRF-TOKEN', $session['token'])
            ->postJson($this->url(), $this->payload());
        $response->assertForbidden()->assertJsonMissingPath('reports');
    }

    public function test_payload_rejects_unknown_fields_invalid_types_and_bounds(): void
    {
        $this->serviceMustNotRun();
        foreach ([
            ['actor_id' => 1], ['company_id' => 1], ['unexpected' => 'x'], ['text' => str_repeat('x', 4001)],
            ['text' => []], ['text' => '   '], ['request_key' => 'not-a-uuid'], ['conversation_token' => 'bad'],
            ['conversation_token' => null], ['source' => 'audio'],
        ] as $invalid) {
            $session = $this->persistedSession();
            $this->withCookie(config('session.cookie'), $session['id'])->withHeader('X-CSRF-TOKEN', $session['token'])
                ->postJson($this->url(), array_replace($this->payload(), $invalid))->assertUnprocessable();
        }
    }

    public function test_disabled_turn_flags_return_404_with_zero_queries_even_when_commands_enabled(): void
    {
        $this->serviceMustNotRun();
        foreach ([['ai_assistant.enabled' => false, 'ai_assistant.turn_enabled' => true],
            ['ai_assistant.enabled' => true, 'ai_assistant.turn_enabled' => false]] as $flags) {
            config($flags);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->postJson($this->url(), $this->payload())->assertNotFound();
            $this->assertSame([], DB::getQueryLog());
            DB::disableQueryLog();
        }
    }

    public function test_ai_turn_can_run_with_commands_disabled_while_other_api_paths_stay_closed(): void
    {
        config(['commands.api_enabled' => false]);
        $session = $this->persistedSession();
        $mock = Mockery::mock(AssistantTurnService::class);
        $mock->shouldReceive('run')->once()->andReturn($this->answer((string) Str::uuid()));
        $this->app->instance(AssistantTurnService::class, $mock);
        $this->withCookie(config('session.cookie'), $session['id'])->withHeader('X-CSRF-TOKEN', $session['token'])
            ->postJson($this->url(), $this->payload())->assertOk();

        foreach (['/api/v1/session', '/api/v1/companies/'.$this->company->id.'/lookups/customers', $this->url().'/extra'] as $path) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($path)->assertNotFound();
            $this->assertSame([], DB::getQueryLog());
            DB::disableQueryLog();
        }
    }

    public function test_unavailable_categories_are_allowlisted_and_never_include_raw_exception_data(): void
    {
        foreach (['provider_credit' => 'provider_unavailable', 'secret-provider-error-and-key' => 'unavailable'] as $reason => $category) {
            $session = $this->persistedSession();
            $mock = Mockery::mock(AssistantTurnService::class);
            $mock->shouldReceive('run')->once()->andThrow(new AssistantUnavailable($reason));
            $this->app->instance(AssistantTurnService::class, $mock);
            $response = $this->withCookie(config('session.cookie'), $session['id'])->withHeader('X-CSRF-TOKEN', $session['token'])
                ->postJson($this->url(), $this->payload());
            $response->assertStatus(503)->assertJsonPath('category', $category);
            $this->assertStringNotContainsString($reason, $response->getContent());
        }
    }

    /** @return array{id: string, token: string} */
    private function persistedSession(): array
    {
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        auth()->forgetGuards();
        $id = Str::random(40);
        $token = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $this->actor->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'TestBrowser/1.0',
            'payload' => base64_encode(serialize([
                '_token' => $token, auth()->guard('web')->getName() => $this->actor->id,
                'password_hash_web' => $this->actor->getAuthPassword(), 'active_company_id' => $this->company->id,
                'phase11_session_started_at' => now()->timestamp,
            ])),
            'last_activity' => now()->timestamp,
        ]);

        return ['id' => $id, 'token' => $token];
    }

    private function serviceMustNotRun(): void
    {
        $mock = Mockery::mock(AssistantTurnService::class);
        $mock->shouldNotReceive('run');
        $this->app->instance(AssistantTurnService::class, $mock);
    }

    private function url(): string
    {
        return '/api/v1/companies/'.$this->company->id.'/assistant/turn';
    }

    /** @return array{text: string, request_key: string} */
    private function payload(): array
    {
        return ['text' => 'Sales today', 'request_key' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function answer(string $key): array
    {
        return ['status' => 'answer', 'reports' => [], 'model_calls' => 0, 'conversation_token' => null, 'request_key' => $key];
    }
}
