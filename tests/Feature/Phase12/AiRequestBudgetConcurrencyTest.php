<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiGovernance;
use App\Application\AI\AiRequestBudget;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCostEstimate;
use App\Models\AiCompanyPolicy;
use App\Models\AiProviderConnection;
use App\Models\AssistantRequest;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

/** Independent child processes require committed rows; intentionally no RefreshDatabase outer transaction. */
class AiRequestBudgetConcurrencyTest extends TestCase
{
    use FinalAiFixtures;

    private ?DisposableMariaDbSchema $fixtureSchema = null;

    /** @var array<string, int> */
    private array $primaryCounts = [];

    private Company $controlCompany;

    private string $controlFingerprint;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $primary = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($primary);

        foreach ([
            'companies', 'users', 'company_users', 'assistant_requests',
            'ai_provider_connections', 'ai_company_policies', 'ai_consents',
            'purchases', 'sales_invoices', 'posting_batches', 'stock_movements',
        ] as $table) {
            if (Schema::hasTable($table)) {
                $this->primaryCounts[$table] = DB::table($table)->count();
            }
        }

        $this->fixtureSchema = DisposableMariaDbSchema::createFromSource($primary);
        $this->fixtureSchema->switchLaravelConnection();

        // Establish main company AI fixtures in the isolated schema.
        $this->finalAiFixtures();

        // Control tenant: an independent company with approved AI configuration and a reserved request.
        app(CompanyContext::class)->clear();
        $this->controlCompany = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة ضبط الميزانية',
            'name_en' => 'Budget Race Control',
            'base_currency_code' => 'ILS',
        ]);
        $this->activate($this->controlCompany);

        $governance = app(AiGovernance::class);
        $controlConn = $governance->provision($this->controlCompany, $this->owner, [
            'provider' => 'deepseek', 'model' => 'deepseek-flash', 'region' => 'global',
            'capability' => 'text_structured', 'credential_source' => 'company_byok',
        ], 'synthetic-control-key-never-real', 'synthetic-configuration-only');

        $controlPricing = [
            $controlConn->public_id => [
                'reference' => 'https://example.test/synthetic-pricing',
                'checked_at' => '2026-10-11',
                'input_micros_per_million' => 1000,
                'output_micros_per_million' => 2000,
                'audio_micros_per_minute' => 6000,
            ],
        ];

        $controlPolicy = $governance->updatePolicy($this->controlCompany, $this->owner, [
            'enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => true,
            'workflow_mode' => 'seamless', 'speech_method' => 'disabled', 'settings' => [
                'allowed_connections' => [$controlConn->public_id],
                'text_connection' => $controlConn->public_id,
                'speech_connection' => null,
                'fallback' => ['enabled' => false, 'text_connection' => null, 'speech_connection' => null],
                'privacy' => [
                    'operator' => 'Synthetic fixture',
                    'retention' => 'No real dispatch',
                    'policy_url' => 'https://example.test/privacy',
                ],
                'pricing' => $controlPricing,
                'limits' => [
                    'request_micros' => 1000,
                    'daily_company_micros' => 100000,
                    'monthly_company_micros' => 1000000,
                    'daily_user_micros' => 100000,
                    'monthly_user_micros' => 1000000,
                    'daily_company_requests' => 100,
                    'daily_user_requests' => 25,
                ],
                'retention_days' => 30,
            ],
        ]);
        $governance->recordConsent($this->controlCompany, $this->owner, $controlConn->public_id,
            $governance->consentFingerprint($controlPolicy, $controlConn));

        app(AiRequestBudget::class)->reserve(
            $this->controlCompany,
            $this->owner,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'intent',
            'sales',
            hash('sha256', 'control-synthetic-hash'),
            [$controlConn],
        );
        $this->controlFingerprint = $this->fingerprint($this->controlCompany);

        // Reactivate main trading company.
        $this->activate($this->company);
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        try {
            $this->fixtureSchema?->restoreLaravelConnection();
            $this->fixtureSchema?->drop();
            foreach ($this->primaryCounts as $table => $count) {
                if (Schema::hasTable($table)) {
                    $this->assertSame($count, DB::table($table)->count(), "Race fixtures must not leak into primary test schema [{$table}].");
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_concurrent_identical_reservations_yield_one_row_and_same_id_with_exact_combined_reservation(): void
    {
        $requestKey = (string) Str::uuid();
        $formKey = (string) Str::uuid();
        $inputHash = hash('sha256', 'order-soap-quantity-10');
        $connections = [$this->textConnection->id];

        // Calculate expected per-request cost from active fixture and source formula.
        $estimateService = app(ProviderCostEstimate::class);
        $costPerConnection = $estimateService->tokens(6000, 4000, 1000, 2000);
        $this->assertSame(14, $costPerConnection);
        $expectedCombinedReservation = $costPerConnection;
        $this->assertSame(14, $expectedCombinedReservation);

        $intent = [
            'operation' => 'reserve',
            'company_id' => $this->company->id,
            'actor_id' => $this->owner->id,
            'request_key' => $requestKey,
            'form_key' => $formKey,
            'purpose' => 'intent',
            'intent' => 'sales',
            'input_hash' => $inputHash,
            'connection_ids' => $connections,
        ];

        $results = $this->race($intent, $intent);

        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame('ok', $results[1]['status']);
        $this->assertSame($results[0]['request_id'], $results[1]['request_id']);

        $createdFlags = [$results[0]['created'], $results[1]['created']];
        sort($createdFlags);
        $this->assertSame([false, true], $createdFlags, 'Exactly one winner must have created=true and one created=false.');

        $this->assertSame(1, AssistantRequest::where('company_id', $this->company->id)->count());

        $fresh = AssistantRequest::where('company_id', $this->company->id)->findOrFail($results[0]['request_id']);
        $this->assertSame($requestKey, $fresh->request_key);
        $this->assertSame($formKey, $fresh->form_key);
        $this->assertSame('intent', $fresh->purpose);
        $this->assertSame('sales', $fresh->intent_kind);
        $this->assertSame($inputHash, $fresh->input_hash);
        $this->assertSame(14, $fresh->reserved_cost_micros);
        $this->assertSame(14, $fresh->estimated_cost_micros);
        $this->assertSame(0, $fresh->attempt_count);
        $this->assertSame('unknown', $fresh->cost_status);
        $this->assertSame('pending', $fresh->status);
        $this->assertSame($this->textConnection->provider, $fresh->provider);

        // No business documents created.
        $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());
        $this->assertSame(0, SalesInvoice::where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('posting_batches')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->where('company_id', $this->company->id)->count());

        Http::assertNothingSent();
        $this->assertHealthyAndControlUnchanged();
    }

    public function test_concurrent_conflicting_hash_yields_one_reservation_and_one_request_conflict(): void
    {
        $requestKey = (string) Str::uuid();
        $formKey = (string) Str::uuid();
        $inputHashA = hash('sha256', 'proposal-version-alpha');
        $inputHashB = hash('sha256', 'proposal-version-beta');
        $connections = [$this->textConnection->id];

        $intentA = [
            'operation' => 'reserve',
            'company_id' => $this->company->id,
            'actor_id' => $this->owner->id,
            'request_key' => $requestKey,
            'form_key' => $formKey,
            'purpose' => 'intent',
            'intent' => 'sales',
            'input_hash' => $inputHashA,
            'connection_ids' => $connections,
        ];
        $intentB = [
            'operation' => 'reserve',
            'company_id' => $this->company->id,
            'actor_id' => $this->owner->id,
            'request_key' => $requestKey,
            'form_key' => $formKey,
            'purpose' => 'intent',
            'intent' => 'sales',
            'input_hash' => $inputHashB,
            'connection_ids' => $connections,
        ];

        $results = $this->race($intentA, $intentB);

        $statuses = [$results[0]['status'], $results[1]['status']];
        sort($statuses);
        $this->assertSame(['assistant_unavailable', 'ok'], $statuses);

        $winnerIndex = $results[0]['status'] === 'ok' ? 0 : 1;
        $loserIndex = 1 - $winnerIndex;

        $this->assertTrue($results[$winnerIndex]['created']);
        $this->assertSame('assistant_unavailable', $results[$loserIndex]['status']);
        $this->assertSame('request_conflict', $results[$loserIndex]['reason']);

        $this->assertSame(1, AssistantRequest::where('company_id', $this->company->id)->count());

        $fresh = AssistantRequest::where('company_id', $this->company->id)->findOrFail($results[$winnerIndex]['request_id']);
        $winningHash = $winnerIndex === 0 ? $inputHashA : $inputHashB;
        $this->assertSame($winningHash, $fresh->input_hash, 'Persisted row must match the winning payload without last-write-wins.');
        $this->assertSame($requestKey, $fresh->request_key);
        $this->assertSame($formKey, $fresh->form_key);
        $this->assertSame('intent', $fresh->purpose);
        $this->assertSame(14, $fresh->reserved_cost_micros);

        // Binding variation: replay same request UUID with conflicting form_key and purpose is explicitly refused.
        $budget = app(AiRequestBudget::class);
        try {
            $budget->reserve(
                $this->company,
                $this->owner,
                $requestKey,
                (string) Str::uuid(), // conflicting form key
                'intent',
                'sales',
                $winningHash,
                [$this->textConnection],
            );
            $this->fail('Replay with conflicting form_key must throw request_conflict.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('request_conflict', $e->reason);
        }

        try {
            $budget->reserve(
                $this->company,
                $this->owner,
                $requestKey,
                $formKey,
                'patch', // conflicting purpose
                'sales',
                $winningHash,
                [$this->textConnection],
            );
            $this->fail('Replay with conflicting purpose must throw request_conflict.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('request_conflict', $e->reason);
        }

        Http::assertNothingSent();
        $this->assertHealthyAndControlUnchanged();
    }

    public function test_concurrent_monetary_caps_permit_exactly_one_reservation(): void
    {
        // Each combined request costs exactly 14 micro-units (14 primary + 14 fallback).
        $cases = [
            'daily_company_policy_limit' => [
                'limits' => ['daily_company_micros' => 14],
                'config' => [],
                'active_cap' => 14,
            ],
            'monthly_company_policy_limit' => [
                'limits' => ['monthly_company_micros' => 14],
                'config' => [],
                'active_cap' => 14,
            ],
            'daily_user_policy_limit' => [
                'limits' => ['daily_user_micros' => 14],
                'config' => [],
                'active_cap' => 14,
            ],
            'monthly_user_policy_limit' => [
                'limits' => ['monthly_user_micros' => 14],
                'config' => [],
                'active_cap' => 14,
            ],
            'platform_ceiling_lower_than_company_cap' => [
                'limits' => ['daily_company_micros' => 100000],
                'config' => ['ai_assistant.daily_company_cost_ceiling_micros' => 14],
                'active_cap' => 14,
            ],
        ];

        foreach ($cases as $caseName => $spec) {
            $isolated = $this->createIsolatedCompanyWithPolicy($spec['limits']);
            $co = $isolated['company'];
            $actor = $isolated['user'];
            $textConn = $isolated['textConnection'];

            $intentA = [
                'operation' => 'reserve',
                'company_id' => $co->id,
                'actor_id' => $actor->id,
                'request_key' => (string) Str::uuid(),
                'form_key' => (string) Str::uuid(),
                'purpose' => 'intent',
                'intent' => 'sales',
                'input_hash' => hash('sha256', 'request-A-'.$caseName),
                'connection_ids' => [$textConn->id],
            ];
            $intentB = [
                'operation' => 'reserve',
                'company_id' => $co->id,
                'actor_id' => $actor->id,
                'request_key' => (string) Str::uuid(),
                'form_key' => (string) Str::uuid(),
                'purpose' => 'intent',
                'intent' => 'sales',
                'input_hash' => hash('sha256', 'request-B-'.$caseName),
                'connection_ids' => [$textConn->id],
            ];

            $results = $this->race($intentA, $intentB, null, $spec['config']);

            $statuses = [$results[0]['status'], $results[1]['status']];
            sort($statuses);
            $this->assertSame(['assistant_unavailable', 'ok'], $statuses, "Case [{$caseName}] must have exactly one ok and one budget refusal.");

            $loserIndex = $results[0]['status'] === 'assistant_unavailable' ? 0 : 1;
            $this->assertSame('budget', $results[$loserIndex]['reason'], "Case [{$caseName}] loser must fail with 'budget'.");

            $this->activate($co, $actor);
            $this->assertSame(1, AssistantRequest::where('company_id', $co->id)->count(), "Case [{$caseName}] must produce exactly one request row.");
            $totalReserved = (int) AssistantRequest::where('company_id', $co->id)->sum('reserved_cost_micros');
            $this->assertSame(14, $totalReserved, "Case [{$caseName}] total reserved must be exactly 14 micro-units.");
            $this->assertLessThanOrEqual($spec['active_cap'], $totalReserved, "Case [{$caseName}] must not exceed active cap {$spec['active_cap']}.");
        }

        $this->activate($this->company);
        Http::assertNothingSent();
        $this->assertHealthyAndControlUnchanged();
    }

    public function test_concurrent_daily_request_count_boundary_refuses_second_deterministic_reservation(): void
    {
        $isolated = $this->createIsolatedCompanyWithPolicy([
            'daily_company_requests' => 1,
            'daily_user_requests' => 1,
        ]);
        $co = $isolated['company'];
        $actor = $isolated['user'];

        // Deterministic zero-provider reservation (connection_ids = []).
        $intentA = [
            'operation' => 'reserve',
            'company_id' => $co->id,
            'actor_id' => $actor->id,
            'request_key' => (string) Str::uuid(),
            'form_key' => (string) Str::uuid(),
            'purpose' => 'intent',
            'intent' => 'sales',
            'input_hash' => hash('sha256', 'deterministic-payload-A'),
            'connection_ids' => [],
        ];
        $intentB = [
            'operation' => 'reserve',
            'company_id' => $co->id,
            'actor_id' => $actor->id,
            'request_key' => (string) Str::uuid(),
            'form_key' => (string) Str::uuid(),
            'purpose' => 'intent',
            'intent' => 'sales',
            'input_hash' => hash('sha256', 'deterministic-payload-B'),
            'connection_ids' => [],
        ];

        $results = $this->race($intentA, $intentB);

        $statuses = [$results[0]['status'], $results[1]['status']];
        sort($statuses);
        $this->assertSame(['assistant_unavailable', 'ok'], $statuses);

        $winnerIndex = $results[0]['status'] === 'ok' ? 0 : 1;
        $loserIndex = 1 - $winnerIndex;

        $this->assertTrue($results[$winnerIndex]['created']);
        $this->assertSame(0, $results[$winnerIndex]['reserved_cost_micros']);
        $this->assertSame('no_text_call', $results[$winnerIndex]['cost_status']);

        $this->assertSame('assistant_unavailable', $results[$loserIndex]['status']);
        $this->assertSame('budget', $results[$loserIndex]['reason']);

        $this->activate($co, $actor);
        $this->assertSame(1, AssistantRequest::where('company_id', $co->id)->count());
        $this->assertSame(0, (int) AssistantRequest::where('company_id', $co->id)->sum('reserved_cost_micros'));

        // No business documents created.
        $this->assertSame(0, Purchase::where('company_id', $co->id)->count());
        $this->assertSame(0, SalesInvoice::where('company_id', $co->id)->count());

        $this->activate($this->company);
        Http::assertNothingSent();
        $this->assertHealthyAndControlUnchanged();
    }

    public function test_fresh_authority_refuses_pending_reservations_at_lock_boundary(): void
    {
        // 1. Role downgrade behind company lock refuses pending reservations without writes.
        $isolatedRole = $this->createIsolatedCompanyWithPolicy();
        $coA = $isolatedRole['company'];
        $actorA = $isolatedRole['user'];
        $textConnA = $isolatedRole['textConnection'];

        $viewerRole = Role::where('company_id', $coA->id)->where('name', 'Viewer')->firstOrFail();
        $downgradeRole = function () use ($coA, $actorA, $viewerRole): void {
            setPermissionsTeamId($coA->id);
            $actorA->syncRoles($viewerRole);
        };

        $intentA = [
            'operation' => 'reserve',
            'company_id' => $coA->id,
            'actor_id' => $actorA->id,
            'request_key' => (string) Str::uuid(),
            'form_key' => (string) Str::uuid(),
            'purpose' => 'intent',
            'intent' => 'sales',
            'input_hash' => hash('sha256', 'role-downgrade-intent-1'),
            'connection_ids' => [$textConnA->id],
        ];
        $intentB = [
            'operation' => 'reserve',
            'company_id' => $coA->id,
            'actor_id' => $actorA->id,
            'request_key' => (string) Str::uuid(),
            'form_key' => (string) Str::uuid(),
            'purpose' => 'intent',
            'intent' => 'sales',
            'input_hash' => hash('sha256', 'role-downgrade-intent-2'),
            'connection_ids' => [$textConnA->id],
        ];

        $resultsRole = $this->race($intentA, $intentB, $downgradeRole);
        $this->assertSame('unauthorized', $resultsRole[0]['status']);
        $this->assertSame('unauthorized', $resultsRole[1]['status']);
        $this->activate($coA, $actorA);
        $this->assertSame(0, AssistantRequest::where('company_id', $coA->id)->count(), 'Role downgrade must refuse without writes.');

        // 2. Membership revocation behind company lock refuses pending reservations without writes.
        $isolatedMember = $this->createIsolatedCompanyWithPolicy();
        $coB = $isolatedMember['company'];
        $ownerB = $isolatedMember['user'];
        $textConnB = $isolatedMember['textConnection'];

        $collaborator = User::factory()->create();
        CompanyUser::create([
            'company_id' => $coB->id,
            'user_id' => $collaborator->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        setPermissionsTeamId($coB->id);
        $collaborator->givePermissionTo('ai.assistant.use');
        $collaborator->givePermissionTo('ai.sales.use');
        $collaborator->givePermissionTo('sales.invoice.create');
        $collaborator->givePermissionTo('sales.invoice.view');

        $revokeMembership = function () use ($coB, $collaborator): void {
            CompanyUser::where('company_id', $coB->id)
                ->where('user_id', $collaborator->id)
                ->update(['status' => 'inactive']);
        };

        $intentC = [
            'operation' => 'reserve',
            'company_id' => $coB->id,
            'actor_id' => $collaborator->id,
            'request_key' => (string) Str::uuid(),
            'form_key' => (string) Str::uuid(),
            'purpose' => 'intent',
            'intent' => 'sales',
            'input_hash' => hash('sha256', 'membership-revoke-intent-1'),
            'connection_ids' => [$textConnB->id],
        ];
        $intentD = [
            'operation' => 'reserve',
            'company_id' => $coB->id,
            'actor_id' => $collaborator->id,
            'request_key' => (string) Str::uuid(),
            'form_key' => (string) Str::uuid(),
            'purpose' => 'intent',
            'intent' => 'sales',
            'input_hash' => hash('sha256', 'membership-revoke-intent-2'),
            'connection_ids' => [$textConnB->id],
        ];

        $resultsMember = $this->race($intentC, $intentD, $revokeMembership);
        $this->assertSame('unauthorized', $resultsMember[0]['status']);
        $this->assertSame('unauthorized', $resultsMember[1]['status']);
        $this->activate($coB, $ownerB); // Inspect as the still-authorized owner; revoked membership must stay revoked.
        $this->assertSame(0, AssistantRequest::where('company_id', $coB->id)->count(), 'Membership revocation must refuse without writes.');

        // 3. Company AI disable behind company lock refuses pending reservations without writes.
        $isolatedDisable = $this->createIsolatedCompanyWithPolicy();
        $coC = $isolatedDisable['company'];
        $actorC = $isolatedDisable['user'];
        $textConnC = $isolatedDisable['textConnection'];

        $disablePolicy = function () use ($coC, $actorC): void {
            $this->activate($coC, $actorC);
            $this->assertSame(1, AiCompanyPolicy::where('company_id', $coC->id)->update(['enabled' => false]));
        };

        $intentE = [
            'operation' => 'reserve',
            'company_id' => $coC->id,
            'actor_id' => $actorC->id,
            'request_key' => (string) Str::uuid(),
            'form_key' => (string) Str::uuid(),
            'purpose' => 'intent',
            'intent' => 'sales',
            'input_hash' => hash('sha256', 'policy-disable-intent-1'),
            'connection_ids' => [$textConnC->id],
        ];
        $intentF = [
            'operation' => 'reserve',
            'company_id' => $coC->id,
            'actor_id' => $actorC->id,
            'request_key' => (string) Str::uuid(),
            'form_key' => (string) Str::uuid(),
            'purpose' => 'intent',
            'intent' => 'sales',
            'input_hash' => hash('sha256', 'policy-disable-intent-2'),
            'connection_ids' => [$textConnC->id],
        ];

        $resultsDisable = $this->race($intentE, $intentF, $disablePolicy);
        $this->assertSame('assistant_unavailable', $resultsDisable[0]['status']);
        $this->assertSame('disabled', $resultsDisable[0]['reason']);
        $this->assertSame('assistant_unavailable', $resultsDisable[1]['status']);
        $this->assertSame('disabled', $resultsDisable[1]['reason']);
        $this->activate($coC, $actorC);
        $this->assertSame(0, AssistantRequest::where('company_id', $coC->id)->count(), 'Policy disable must refuse without writes.');

        $this->activate($this->company);
        Http::assertNothingSent();
        $this->assertHealthyAndControlUnchanged();
    }

    public function test_concurrent_begin_attempt_for_same_connection_allows_exactly_one_increment(): void
    {
        $budget = app(AiRequestBudget::class);
        $requestKey = (string) Str::uuid();
        $formKey = (string) Str::uuid();
        $inputHash = hash('sha256', 'begin-attempt-concurrency-hash');

        $reservation = $budget->reserve(
            $this->company,
            $this->owner,
            $requestKey,
            $formKey,
            'intent',
            'sales',
            $inputHash,
            [$this->textConnection],
        );
        $request = $reservation['request']->refresh();
        $this->assertSame(0, (int) $request->attempt_count);
        $this->assertSame(14, $request->reserved_cost_micros);
        $this->assertSame('unknown', $request->cost_status);

        $intent = [
            'operation' => 'begin_attempt',
            'company_id' => $this->company->id,
            'actor_id' => $this->owner->id,
            'request_id' => $request->id,
            'connection_id' => $this->textConnection->id,
        ];

        $results = $this->race($intent, $intent);

        $statuses = [$results[0]['status'], $results[1]['status']];
        sort($statuses);
        $this->assertSame(['assistant_unavailable', 'ok'], $statuses);

        $winnerIndex = $results[0]['status'] === 'ok' ? 0 : 1;
        $loserIndex = 1 - $winnerIndex;

        $this->assertSame(1, $results[$winnerIndex]['attempt_count']);
        $this->assertSame('assistant_unavailable', $results[$loserIndex]['status']);
        $this->assertSame('fallback_invalid', $results[$loserIndex]['reason']);

        $request->refresh();
        $this->assertSame(1, $request->attempt_count);
        $this->assertSame(14, $request->reserved_cost_micros, 'Reservation cost must remain intact.');
        $this->assertSame('unknown', $request->cost_status, 'Cost status must remain unknown.');

        // A replay cannot authorize a second request or release the unknown reservation.
        try {
            $budget->beginAttempt($this->company, $this->owner, $request, $this->textConnection);
            $this->fail('Second dispatch must refuse.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('fallback_invalid', $e->reason);
        }
        $this->assertSame(1, $request->fresh()->attempt_count);
        $this->assertSame(14, $request->fresh()->reserved_cost_micros);
        $this->assertSame('unknown', $request->fresh()->cost_status);

        Http::assertNothingSent();
        $this->assertHealthyAndControlUnchanged();
    }

    public function test_foreign_company_connection_and_request_cannot_be_adopted(): void
    {
        // Foreign company with independent configuration and request.
        app(CompanyContext::class)->clear();
        $foreignCompany = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة أجنبية للميزانية',
            'name_en' => 'Foreign Budget Co',
            'base_currency_code' => 'ILS',
        ]);
        $this->activate($foreignCompany);

        $governance = app(AiGovernance::class);
        $foreignConnection = $governance->provision($foreignCompany, $this->owner, [
            'provider' => 'deepseek', 'model' => 'deepseek-flash', 'region' => 'global',
            'capability' => 'text_structured', 'credential_source' => 'company_byok',
        ], 'synthetic-foreign-key-never-real', 'synthetic-configuration-only');

        $foreignPricing = [
            $foreignConnection->public_id => [
                'reference' => 'https://example.test/synthetic-pricing',
                'checked_at' => '2026-10-11',
                'input_micros_per_million' => 1000,
                'output_micros_per_million' => 2000,
                'audio_micros_per_minute' => 6000,
            ],
        ];

        $foreignPolicy = $governance->updatePolicy($foreignCompany, $this->owner, [
            'enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => true,
            'workflow_mode' => 'seamless', 'speech_method' => 'disabled', 'settings' => [
                'allowed_connections' => [$foreignConnection->public_id],
                'text_connection' => $foreignConnection->public_id,
                'speech_connection' => null,
                'fallback' => ['enabled' => false, 'text_connection' => null, 'speech_connection' => null],
                'privacy' => [
                    'operator' => 'Synthetic fixture',
                    'retention' => 'No real dispatch',
                    'policy_url' => 'https://example.test/privacy',
                ],
                'pricing' => $foreignPricing,
                'limits' => [
                    'request_micros' => 1000,
                    'daily_company_micros' => 100000,
                    'monthly_company_micros' => 1000000,
                    'daily_user_micros' => 100000,
                    'monthly_user_micros' => 1000000,
                    'daily_company_requests' => 100,
                    'daily_user_requests' => 25,
                ],
                'retention_days' => 30,
            ],
        ]);
        $governance->recordConsent($foreignCompany, $this->owner, $foreignConnection->public_id,
            $governance->consentFingerprint($foreignPolicy, $foreignConnection));

        $budget = app(AiRequestBudget::class);
        $foreignReservation = $budget->reserve(
            $foreignCompany,
            $this->owner,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'intent',
            'sales',
            hash('sha256', 'foreign-synthetic-hash'),
            [$foreignConnection],
        );
        $foreignRequest = $foreignReservation['request'];
        $foreignFingerprint = $this->fingerprint($foreignCompany);

        // Re-activate main company.
        $this->activate($this->company);

        // 1. Foreign connection cannot be reserved in main company.
        try {
            $budget->reserve(
                $this->company,
                $this->owner,
                (string) Str::uuid(),
                (string) Str::uuid(),
                'intent',
                'sales',
                hash('sha256', 'adopt-foreign-conn'),
                [$foreignConnection],
            );
            $this->fail('Foreign connection reservation must refuse.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_configuration', $e->reason);
        }

        // 2. Foreign connection cannot be passed to beginAttempt on main company request.
        $mainReservation = $budget->reserve(
            $this->company,
            $this->owner,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'intent',
            'sales',
            hash('sha256', 'main-legit-request'),
            [$this->textConnection],
        );
        $mainRequest = $mainReservation['request'];

        try {
            $budget->beginAttempt($this->company, $this->owner, $mainRequest, $foreignConnection);
            $this->fail('Foreign connection beginAttempt must refuse.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('fallback_invalid', $e->reason);
        }

        // 3. Foreign request cannot be adopted by main company in beginAttempt.
        try {
            $budget->beginAttempt($this->company, $this->owner, $foreignRequest, $this->textConnection);
            $this->fail('Foreign request beginAttempt must refuse with ModelNotFoundException.');
        } catch (ModelNotFoundException) {
            // Expected: Tenant isolation ensures request lookup fails.
        }

        $this->assertSame($foreignFingerprint, $this->fingerprint($foreignCompany), 'Foreign company fingerprint must be unchanged.');
        Http::assertNothingSent();
        $this->assertHealthyAndControlUnchanged();
    }

    private function activate(Company $company, ?User $actor = null): void
    {
        $user = $actor ?? $this->owner;
        $this->actingAs($user);
        app(CompanyContext::class)->setCompany($company, $user);
        setPermissionsTeamId($company->id);
    }

    /**
     * @param  array<string, int>  $limits
     * @return array{company: Company, user: User, textConnection: AiProviderConnection, policy: AiCompanyPolicy}
     */
    private function createIsolatedCompanyWithPolicy(array $limits = []): array
    {
        app(CompanyContext::class)->clear();
        $user = User::factory()->create([
            'locale' => 'en',
            'password' => Hash::make('synthetic-configuration-only'),
        ]);
        $company = app(CreateCompanyAction::class)->execute($user, [
            'name_ar' => 'شركة ميزانية منعزلة',
            'name_en' => 'Isolated Budget Company',
            'base_currency_code' => 'ILS',
        ]);
        $this->activate($company, $user);

        $governance = app(AiGovernance::class);
        $textConn = $governance->provision($company, $user, [
            'provider' => 'deepseek', 'model' => 'deepseek-flash', 'region' => 'global',
            'capability' => 'text_structured', 'credential_source' => 'company_byok',
        ], 'synthetic-primary-key', 'synthetic-configuration-only');
        $pricing = [];
        foreach ([$textConn] as $c) {
            $pricing[$c->public_id] = [
                'reference' => 'https://example.test/synthetic-pricing',
                'checked_at' => '2026-10-11',
                'input_micros_per_million' => 1000,
                'output_micros_per_million' => 2000,
                'audio_micros_per_minute' => 6000,
            ];
        }

        $defaultLimits = [
            'request_micros' => 1000,
            'daily_company_micros' => 100000,
            'monthly_company_micros' => 1000000,
            'daily_user_micros' => 100000,
            'monthly_user_micros' => 1000000,
            'daily_company_requests' => 100,
            'daily_user_requests' => 25,
        ];

        $policy = $governance->updatePolicy($company, $user, [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'seamless',
            'speech_method' => 'disabled',
            'settings' => [
                'allowed_connections' => [$textConn->public_id],
                'text_connection' => $textConn->public_id,
                'speech_connection' => null,
                'fallback' => [
                    'enabled' => false,
                    'text_connection' => null,
                    'speech_connection' => null,
                ],
                'privacy' => [
                    'operator' => 'Synthetic fixture',
                    'retention' => 'No real dispatch',
                    'policy_url' => 'https://example.test/privacy',
                ],
                'pricing' => $pricing,
                'limits' => array_replace($defaultLimits, $limits),
                'retention_days' => 30,
            ],
        ]);

        foreach ([$textConn] as $c) {
            $governance->recordConsent($company, $user, $c->public_id, $governance->consentFingerprint($policy, $c));
        }

        $this->activate($this->company);

        return [
            'company' => $company,
            'user' => $user,
            'textConnection' => $textConn,
            'policy' => $policy,
        ];
    }

    private function fingerprint(Company $company): string
    {
        $rows = [];
        $rows['companies'] = DB::table('companies')->where('id', $company->id)->get()->all();
        foreach ([
            'company_users', 'ai_company_policies', 'ai_provider_connections',
            'ai_consents', 'assistant_requests', 'audit_events',
        ] as $table) {
            if (Schema::hasTable($table)) {
                $rows[$table] = DB::table($table)->where('company_id', $company->id)->orderBy('id')->get()->all();
            }
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function assertHealthyAndControlUnchanged(): void
    {
        $this->assertSame($this->controlFingerprint, $this->fingerprint($this->controlCompany), 'Control tenant fingerprint must remain unchanged.');
        $this->assertSame(0, DB::transactionLevel());
        app(CompanyContext::class)->clear();
        auth()->forgetUser();
        setPermissionsTeamId(null);
    }

    /**
     * @param  array<string, mixed>  $intentA
     * @param  array<string, mixed>  $intentB
     * @param  (?callable(): void)  $beforeRelease
     * @param  array<string, mixed>  $configOverrides
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function race(
        array $intentA,
        array $intentB,
        ?callable $beforeRelease = null,
        array $configOverrides = [],
        ?string $frozenTime = null,
    ): array {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase12-ai-budget-race-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create owned race directory.');
        }

        $release = $directory.DIRECTORY_SEPARATOR.'release';
        $processes = [];
        $files = [];
        $readyFiles = [];
        $attemptFiles = [];

        $appKey = (string) (config('app.key') ?: getenv('APP_KEY'));
        if ($appKey === '') {
            throw new \RuntimeException('A valid APP_KEY is required for the AI budget race harness.');
        }

        $environment = [
            'APP_KEY' => $appKey,
            'DB_URL' => '',
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'COMMAND_API_ENABLED' => 'false',
        ];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) config('database.connections.mysql.'.$key);
        }
        foreach ([
            'PHASE8_ALLOW_DISPOSABLE_DB', 'PHASE8_TEST_DB_HOST', 'PHASE8_TEST_DB_PORT', 'PHASE8_TEST_DB_USERNAME',
            'PHASE8_TEST_DB_EMPTY_PASSWORD', 'PHASE8_DISPOSABLE_DB_PROOF', 'PHASE8_DISPOSABLE_DB_NONCE', 'APP_CONFIG_CACHE',
        ] as $key) {
            $environment[$key] = getenv($key);
        }
        $environment['PHASE8_TEST_DB_PASSWORD'] = getenv('PHASE8_TEST_DB_PASSWORD');
        $environment = array_replace($environment, $this->fixtureSchema->environment());

        $intents = [$intentA, $intentB];
        $companyId = (int) ($intentA['company_id'] ?? $this->company->id);

        $locked = false;
        try {
            DB::beginTransaction();
            $locked = true;
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();

            foreach ($intents as $index => $intent) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR.'payload-'.$index.'.json';
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $attempting = $directory.DIRECTORY_SEPARATOR.'attempting-'.$index;
                $files = array_merge($files, [$payloadPath, $ready, $attempting]);
                $readyFiles[] = $ready;
                $attemptFiles[] = $attempting;

                $payloadData = array_merge($intent, [
                    'ready' => $ready,
                    'attempting' => $attempting,
                    'release' => $release,
                    'config_overrides' => $configOverrides,
                    'frozen_time' => $frozenTime ?? '2026-10-11 12:00:00',
                ]);

                file_put_contents($payloadPath, json_encode($payloadData, JSON_THROW_ON_ERROR));
                chmod($payloadPath, 0600);

                $process = new Process(
                    [PHP_BINARY, base_path('tests/Support/Phase12/ai-request-budget-race-worker.php'), $payloadPath],
                    base_path(),
                    $environment,
                );
                $process->setTimeout(45)->start();
                $processes[] = $process;
            }

            $this->waitForFiles($readyFiles, $processes);
            file_put_contents($release, 'release');
            $this->waitForFiles($attemptFiles, $processes);
            usleep(200000);

            foreach ($processes as $process) {
                $this->assertTrue(
                    $process->isRunning(),
                    'Both independent AI budget attempts must overlap behind the company lock. ExitCode: '
                    .$process->getExitCode().' Stderr: '.$process->getErrorOutput().' Stdout: '.$process->getOutput()
                );
                $this->assertSame('', $process->getOutput());
            }

            if ($beforeRelease !== null) {
                $beforeRelease();
            }

            DB::commit();
            $locked = false;

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return [$results[0], $results[1]];
        } finally {
            if ($locked) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (array_merge($files, [$release]) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * @param  list<string>  $files
     * @param  list<Process>  $processes
     */
    private function waitForFiles(array $files, array $processes): void
    {
        $deadline = microtime(true) + 20;
        do {
            if (count(array_filter($files, 'is_file')) === count($files)) {
                return;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('AI budget race child exited before barrier: '.$process->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('AI budget race barrier timed out.');
    }
}
