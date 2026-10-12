<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedAssistant;

use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantQuestionPlanner;
use App\Application\AI\AssistantUnavailable;
use App\Models\AiProviderConnection;
use App\Models\AssistantRequest;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

final class QuestionProviderBoundaryTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        config(['ai_assistant.questions.enabled' => true]);
        $this->questionPolicy();
        Http::preventStrayRequests();
    }

    public function test_transaction_consent_cannot_authorize_question_dispatch(): void
    {
        $governance = app(AiGovernance::class);
        foreach ([$this->textConnection, $this->fallbackConnection] as $connection) {
            $governance->recordConsent($this->company, $this->owner, $connection->public_id,
                $governance->consentFingerprint($this->aiPolicy, $connection));
        }
        try {
            $this->plan();
            $this->fail('Transaction consent must not authorize question processing.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('consent', $e->reason);
        }
        $this->assertSame(0, AssistantRequest::count());
        Http::assertNothingSent();
    }

    public function test_one_bounded_question_plan_contains_no_business_data_and_retains_unknown_cost(): void
    {
        $this->consentQuestions();
        Http::fake(['*' => Http::response($this->response())]);
        $plan = $this->plan();
        $this->assertSame('sales.summary', $plan->reportKey);
        $this->assertSame(['preset' => 'this_month'], $plan->filters);
        $request = AssistantRequest::sole();
        $this->assertSame('question', $request->purpose);
        $this->assertSame('question', $request->intent_kind);
        $this->assertSame('completed', $request->status);
        $this->assertSame(1, $request->attempt_count);
        $this->assertSame(15, $request->input_units);
        $this->assertSame(10, $request->output_units);
        $this->assertSame('unknown', $request->cost_status);
        $this->assertGreaterThan(0, $request->reserved_cost_micros);
        Http::assertSentCount(1);
        Http::assertSent(function ($http): bool {
            $body = json_encode($http->data(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('SOAP-3L', $body);
            $this->assertStringNotContainsString('Ahmed', $body);
            $this->assertStringNotContainsString('gross_sales_base', $body);

            return str_contains($body, 'sales.summary');
        });
        $this->assertNoEconomicWrites();
    }

    public function test_outage_only_approved_fallback_and_strict_nullable_schema(): void
    {
        $this->consentQuestions();
        Http::fake([
            'https://api.deepseek.com/*' => Http::response([], 503),
            'https://api.openai.com/*' => Http::response($this->response()),
        ]);
        $this->assertSame('sales.summary', $this->plan()->reportKey);
        Http::assertSentCount(2);
        Http::assertSent(function ($http): bool {
            if (! str_starts_with($http->url(), 'https://api.openai.com/')) {
                return false;
            }
            $schema = $http->data()['response_format']['json_schema']['schema'];
            $this->assertFalse($schema['additionalProperties']);
            $this->assertSame(['preset', 'currency_code'], $schema['properties']['filters']['required']);
            $this->assertSame(['string', 'null'], $schema['properties']['filters']['properties']['preset']['type']);
            $this->assertSame(['sales.summary'], $schema['properties']['report_key']['enum']);

            return true;
        });
        $this->assertSame(2, AssistantRequest::sole()->attempt_count);
    }

    public function test_untrusted_extra_fields_fail_without_fallback_or_partial_result(): void
    {
        $this->consentQuestions();
        $bad = ['version' => 1, 'type' => 'report_query', 'report_key' => 'sales.summary',
            'filters' => ['preset' => 'this_month', 'currency_code' => null, 'customer_id' => 5], 'display' => 'summary'];
        Http::fake(['*' => Http::response($this->response($bad))]);
        try {
            $this->plan();
            $this->fail('Untrusted filters must be rejected, never discarded.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('question_invalid', $e->reason);
        }
        Http::assertSentCount(1);
        $this->assertSame('failed', AssistantRequest::sole()->status);
        $this->assertGreaterThan(0, AssistantRequest::sole()->reserved_cost_micros);
        $this->assertNoEconomicWrites();
    }

    public function test_revoked_primary_during_planning_refuses_result_and_preserves_reservation(): void
    {
        $this->consentQuestions();
        Http::fake(function () {
            app(AiGovernance::class)->revoke($this->company, $this->owner, $this->textConnection->public_id, 'synthetic-configuration-only');

            return Http::response($this->response());
        });
        try {
            $this->plan();
            $this->fail('A revoked primary cannot deliver a plan.');
        } catch (AssistantUnavailable $e) {
            $this->assertContains($e->reason, ['credential_revoked', 'proposal_stale']);
        }
        $this->assertSame('failed', AssistantRequest::sole()->status);
        $this->assertGreaterThan(0, AssistantRequest::sole()->reserved_cost_micros);
        Http::assertSentCount(1);
    }

    public function test_membership_revocation_after_provider_latency_blocks_delivery(): void
    {
        $reader = $this->reader();
        $this->consentQuestions($reader);
        Http::fake(function () use ($reader) {
            CompanyUser::where('company_id', $this->company->id)->where('user_id', $reader->id)->update(['status' => 'inactive']);

            return Http::response($this->response());
        });
        try {
            $this->plan($reader);
            $this->fail('Revoked membership cannot receive a plan.');
        } catch (AuthorizationException) {
            $this->assertSame('failed', AssistantRequest::sole()->status);
        }
        Http::assertSentCount(1);
        $this->assertNoEconomicWrites();
    }

    public function test_report_only_speech_reuses_budget_and_never_creates_a_transaction_handoff(): void
    {
        $speech = $this->speechConnection();
        $reader = $this->reader();
        app(AiGovernance::class)->recordConsent($this->company, $reader, $speech->public_id,
            app(AiGovernance::class)->consentFingerprint($this->aiPolicy, $speech, 'question'), 'question');
        Http::fake(['*' => Http::response(['text' => 'sales summary this month'])]);
        $file = UploadedFile::fake()->createWithContent('question.wav', $this->wav());
        $this->postJson(route('assistant.audio'), ['audio' => $file, 'context' => 'question',
            'form_key' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(), 'locale' => 'en'])
            ->assertOk()->assertJsonPath('text', 'sales summary this month')->assertJsonPath('audio_token', null);
        $this->assertFileDoesNotExist($file->getPathname());
        $this->assertSame('question', AssistantRequest::sole()->intent_kind);
        $this->assertSame('completed', AssistantRequest::sole()->status);
        Http::assertSentCount(1);
        $this->assertNoEconomicWrites();
    }

    public function test_speech_key_revocation_after_dispatch_refuses_transcript_and_cleans_upload(): void
    {
        $speech = $this->speechConnection();
        app(AiGovernance::class)->recordConsent($this->company, $this->owner, $speech->public_id,
            app(AiGovernance::class)->consentFingerprint($this->aiPolicy, $speech, 'question'), 'question');
        Http::fake(function () use ($speech) {
            app(AiGovernance::class)->revoke($this->company, $this->owner, $speech->public_id, 'synthetic-configuration-only');

            return Http::response(['text' => 'Never delivered']);
        });
        $file = UploadedFile::fake()->createWithContent('question.wav', $this->wav());
        $this->postJson(route('assistant.audio'), ['audio' => $file, 'context' => 'question',
            'form_key' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(), 'locale' => 'en'])
            ->assertUnprocessable()->assertJsonMissingPath('text');
        $this->assertFileDoesNotExist($file->getPathname());
        $this->assertSame('failed', AssistantRequest::sole()->status);
        $this->assertGreaterThan(0, AssistantRequest::sole()->reserved_cost_micros);
        Http::assertSentCount(1);
    }

    private function questionPolicy(?array $settings = null): void
    {
        $settings ??= $this->aiPolicy->fresh()->settings;
        $settings['questions'] = ['enabled' => true, 'allowed_reports' => ['sales.summary']];
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => true,
            'workflow_mode' => 'seamless', 'speech_method' => 'cloud', 'settings' => $settings,
        ], (int) $this->aiPolicy->fresh()->revision);
    }

    private function consentQuestions(?User $actor = null): void
    {
        $actor ??= $this->owner;
        foreach ([$this->textConnection, $this->fallbackConnection] as $connection) {
            app(AiGovernance::class)->recordConsent($this->company, $actor, $connection->public_id,
                app(AiGovernance::class)->consentFingerprint($this->aiPolicy, $connection, 'question'), 'question');
        }
    }

    private function plan(?User $actor = null): mixed
    {
        return app(AssistantQuestionPlanner::class)->plan($this->company, $actor ?? $this->owner,
            'Show our turnover for this month', (string) Str::uuid(), session()->getId());
    }

    private function response(?array $plan = null): array
    {
        $plan ??= ['version' => 1, 'type' => 'report_query', 'report_key' => 'sales.summary',
            'filters' => ['preset' => 'this_month', 'currency_code' => null], 'display' => 'summary'];

        return ['choices' => [['message' => ['content' => json_encode($plan, JSON_THROW_ON_ERROR)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 10]];
    }

    private function reader(): User
    {
        $reader = User::factory()->create();
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $reader->id, 'status' => 'active', 'is_owner' => false]);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'Question-only test reader', 'guard_name' => 'web']);
        $role->syncPermissions(['ai.assistant.use', 'reports.sales.view', 'sales.invoice.view']);
        $reader->assignRole($role);
        $this->actingAs($reader);
        app(CompanyContext::class)->setCompany($this->company, $reader);
        $this->assertFalse($reader->can('sales.invoice.create'));

        return $reader;
    }

    private function speechConnection(): AiProviderConnection
    {
        $speech = app(AiGovernance::class)->provision($this->company, $this->owner, [
            'provider' => 'groq', 'model' => 'whisper-large-v3-turbo', 'region' => 'global',
            'capability' => 'audio_transcription', 'credential_source' => 'company_byok',
        ], 'synthetic-question-speech-key', 'synthetic-configuration-only');
        $settings = $this->aiPolicy->fresh()->settings;
        $settings['allowed_connections'][] = $speech->public_id;
        $settings['speech_connection'] = $speech->public_id;
        $settings['pricing'][$speech->public_id] = ['reference' => 'https://example.test/pricing',
            'checked_at' => '2026-10-11', 'input_micros_per_million' => 0, 'output_micros_per_million' => 0, 'audio_micros_per_minute' => 6000];
        $this->questionPolicy($settings);
        $this->withCookie(config('session.cookie'), session()->getId());

        return $speech;
    }

    private function assertNoEconomicWrites(): void
    {
        foreach (['sales_invoices', 'purchases', 'posting_batches', 'stock_movements'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }
}
