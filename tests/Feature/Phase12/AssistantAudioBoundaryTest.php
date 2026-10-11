<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Application\AI\AiGovernance;
use App\Application\AI\TransactionAssistant;
use App\Models\AiProviderConnection;
use App\Models\AiUserPreference;
use App\Models\AssistantRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AssistantAudioBoundaryTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    private AiProviderConnection $speech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        $this->withSession(['active_company_id' => $this->company->id]);
        $this->withCookie(config('session.cookie'), session()->getId());
        $governance = app(AiGovernance::class);
        $this->speech = $governance->provision($this->company, $this->owner, [
            'provider' => 'groq', 'model' => 'whisper-large-v3-turbo', 'region' => 'global',
            'capability' => 'audio_transcription', 'credential_source' => 'company_byok',
        ], 'synthetic-audio-key-never-real', 'synthetic-configuration-only');
        $settings = $this->aiPolicy->settings;
        $settings['allowed_connections'][] = $this->speech->public_id;
        $settings['speech_connection'] = $this->speech->public_id;
        $settings['pricing'][$this->speech->public_id] = [
            'reference' => 'https://example.test/synthetic-pricing', 'checked_at' => '2026-10-11',
            'input_micros_per_million' => 0, 'output_micros_per_million' => 0, 'audio_micros_per_minute' => 6000,
        ];
        $this->aiPolicy = $governance->updatePolicy($this->company, $this->owner, [
            'enabled' => true, 'personal_credentials_enabled' => false, 'follow_up_enabled' => true,
            'workflow_mode' => 'seamless', 'speech_method' => 'cloud', 'settings' => $settings,
        ]);
        foreach ([$this->speech, $this->textConnection, $this->fallbackConnection] as $connection) {
            $governance->recordConsent($this->company, $this->owner, $connection->public_id,
                $governance->consentFingerprint($this->aiPolicy, $connection));
        }
        config(['ai_assistant.provider_ipv4.groq.global' => '8.8.8.8']);
        Http::preventStrayRequests();
    }

    public function test_actual_upload_is_deleted_and_mock_transcript_opens_only_an_unsaved_form(): void
    {
        $text = 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';
        Http::fake(['*' => Http::response(['text' => $text])]);
        $file = UploadedFile::fake()->createWithContent('description.wav', $this->wav());
        $input = $this->input($file);
        $reply = $this->postJson(route('assistant.audio'), $input)->assertOk()
            ->assertJsonPath('text', $text)->assertJsonStructure(['audio_token', 'mode']);
        $this->assertFileDoesNotExist($file->getPathname());
        $token = $reply->json('audio_token');
        $cipher = Cache::get('ai-form-state:'.hash('sha256', $token));
        $this->assertIsString($cipher);
        $this->assertStringNotContainsString($text, $cipher);
        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner, $text, 'sales',
            $input['form_key'], (string) Str::uuid(), session()->getId(), audioToken: $token);
        $this->assertSame('handoff', $result['state']);
        $this->assertSame(2, AssistantRequest::count());
        foreach (['sales_invoices', 'purchases', 'posting_batches', 'stock_movements'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        Http::assertSentCount(1); // Deterministic interpretation makes no text-provider call.
    }

    public function test_missing_consent_refuses_before_reservation_and_cleans_the_upload(): void
    {
        AiUserPreference::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['consents' => []]);
        $file = UploadedFile::fake()->createWithContent('description.wav', $this->wav());
        $this->postJson(route('assistant.audio'), $this->input($file))->assertUnprocessable()->assertJsonMissingPath('text');
        $this->assertFileDoesNotExist($file->getPathname());
        $this->assertSame(0, AssistantRequest::count());
        Http::assertNothingSent();
    }

    public function test_revocation_during_speech_refuses_the_transcript_and_retains_unknown_reservation(): void
    {
        Http::fake(function () {
            app(AiGovernance::class)->revoke($this->company, $this->owner, $this->speech->public_id, 'synthetic-configuration-only');

            return Http::response(['text' => 'Must not be returned after revocation']);
        });
        $file = UploadedFile::fake()->createWithContent('description.wav', $this->wav());
        $this->postJson(route('assistant.audio'), $this->input($file))->assertUnprocessable()->assertJsonMissingPath('text');
        $this->assertFileDoesNotExist($file->getPathname());
        $request = AssistantRequest::firstOrFail();
        $this->assertSame('failed', $request->status);
        $this->assertFalse(Cache::has('ai-request-state:'.$this->company->id.':'.$this->owner->id.':'.$request->id));
        $this->assertSame('unknown', $request->cost_status);
        $this->assertSame(1000, $request->reserved_cost_micros);
        $this->assertSame(0, DB::table('sales_invoices')->count());
        Http::assertSentCount(1);
    }

    public function test_invalid_identity_or_audio_is_refused_and_temporary_files_are_deleted(): void
    {
        foreach (['identity', 'audio'] as $case) {
            $file = UploadedFile::fake()->createWithContent('description.wav', $case === 'audio' ? 'Not a PCM WAV' : $this->wav());
            $input = $this->input($file);
            if ($case === 'identity') {
                $input['form_key'] = 'not-a-form-uuid';
            }
            $this->postJson(route('assistant.audio'), $input)->assertUnprocessable()->assertJsonMissingPath('text');
            $this->assertFileDoesNotExist($file->getPathname());
        }
        $this->assertSame(0, AssistantRequest::count());
        Http::assertNothingSent();
    }

    public function test_disabled_provider_modes_refuse_before_database_or_provider_access(): void
    {
        foreach (['none', 'gateway'] as $mode) {
            config(['ai_assistant.provider' => $mode]);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->postJson(route('assistant.audio'), [])->assertNotFound()->assertJsonMissingPath('text');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertSame([], $queries);
        }
        Http::assertNothingSent();
    }

    private function input(UploadedFile $file): array
    {
        return ['audio' => $file, 'form_key' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(),
            'context' => 'sales', 'locale' => 'mixed'];
    }
}
