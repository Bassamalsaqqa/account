<?php

declare(strict_types=1);

namespace Tests\Feature\DeepSeekProduct;

use App\Application\AI\DeepSeekQuickConnect;
use App\Livewire\AI\AssistantLauncher;
use App\Models\AiCompanyPolicy;
use App\Models\AssistantRequest;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class AssistantDiagnosticTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    public function test_actionable_categories_have_safe_distinct_arabic_and_english_messages(): void
    {
        $component = new AssistantLauncher;
        $method = new ReflectionMethod($component, 'safeReason');
        $categories = ['provider_authentication', 'provider_credit', 'provider_format', 'provider_parameters',
            'provider_rate_limit', 'provider_timeout', 'provider_outage', 'provider_invalid',
            'credential_invalid', 'credential_revoked', 'provider_configuration', 'connection_missing',
            'connection_unverified', 'budget', 'consent', 'consent_stale', 'proposal_stale',
            'input_invalid', 'proposal_invalid', 'question_invalid', 'question_unsupported', 'disabled'];

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ($categories as $category) {
                $this->assertSame($category, $method->invoke($component, $category));
                $message = __('assistant_v2.error_'.$category);
                $this->assertNotSame('assistant_v2.error_'.$category, $message);
                $this->assertNotSame(__('assistant_v2.error_unavailable'), $message);
            }
            $this->assertSame('unavailable', $method->invoke($component, 'secret synthetic-provider-body'));
        }
    }

    private function enableTypedAssistant(): void
    {
        $this->salesFixtures();
        config(['ai_assistant.enabled' => true, 'ai_assistant.questions.enabled' => true]);
        AiCompanyPolicy::create(['company_id' => $this->company->id, 'enabled' => true,
            'personal_credentials_enabled' => false, 'follow_up_enabled' => true,
            'workflow_mode' => 'seamless', 'speech_method' => 'disabled', 'revision' => 1,
            'settings' => ['allowed_intents' => ['sales'], 'text_connection' => null,
                'global_button_enabled' => true, 'questions' => ['enabled' => true,
                    'allowed_reports' => ['sales.summary']]]]);
        Http::preventStrayRequests();
    }

    public function test_transcription_is_editable_and_never_dispatches_or_creates_records(): void
    {
        $this->enableTypedAssistant();
        $component = Livewire::test(AssistantLauncher::class)->call('showAssistant');
        foreach (['sales today', 'sell two products to Ahmed', 'purchases today'] as $transcript) {
            $component->call('receiveTranscript', $transcript, null)
                ->assertSet('text', $transcript)->assertSet('reportResult', null)
                ->assertSet('token', null)->assertSet('error', null)->assertNoRedirect();
        }
        $component->set('text', 'sales today')->call('prepare')
            ->assertSet('reportResult.report_key', 'sales.summary');
        Http::assertNothingSent();
        $this->assertSame(0, AssistantRequest::count());
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_launcher_contains_no_provider_picker_and_oversize_transcript_is_refused(): void
    {
        $this->enableTypedAssistant();
        Livewire::test(AssistantLauncher::class)->call('showAssistant')
            ->assertDontSeeHtml('wire:model="textConnection"')
            ->assertDontSeeHtml('wire:model="speechConnection"')
            ->assertDontSeeHtml('wire:model="transcriptionChoice"')
            ->assertDontSeeHtml('wire:click="selectConnections"')
            ->set('text', 'keep this editable text')->call('receiveTranscript', str_repeat('a', 4001), null)
            ->assertSet('text', 'keep this editable text')
            ->assertSet('error', __('assistant_v2.error_input_invalid'));
        Http::assertNothingSent();
        $this->assertSame(0, AssistantRequest::count());
    }

    public function test_provider_authentication_failure_is_shown_without_raw_response_or_financial_writes(): void
    {
        $this->salesFixtures();
        $this->owner->update(['password' => Hash::make('synthetic-owner-password')]);
        config(['ai_assistant.enabled' => true, 'ai_assistant.provider' => 'direct',
            'ai_assistant.provider_approved' => true, 'ai_assistant.request_cost_ceiling_micros' => 10000,
            'ai_assistant.daily_company_cost_ceiling_micros' => 100000,
            'ai_assistant.monthly_company_cost_ceiling_micros' => 1000000,
            'ai_assistant.daily_user_cost_ceiling_micros' => 100000,
            'ai_assistant.monthly_user_cost_ceiling_micros' => 1000000]);
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['model' => 'deepseek-flash',
            'choices' => [['message' => ['content' => '{"status":"ok"}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5]])
            ->push(['error' => ['message' => 'private synthetic provider body']], 401);
        $this->assertSame('verified', app(DeepSeekQuickConnect::class)->testConnection($this->company, $this->owner,
            'synthetic-test-key-not-a-real-key', 'synthetic-owner-password', true, (string) Str::uuid(), 0, 0)['status']);
        Livewire::test(AssistantLauncher::class, ['context' => 'sales'])->call('showAssistant')
            ->set('text', 'Please sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS')
            ->call('prepare')->assertSet('error', __('assistant_v2.error_provider_authentication'))
            ->assertDontSee('private synthetic provider body')->assertNoRedirect();
        $this->assertSame('provider_authentication', AssistantRequest::latest('id')->firstOrFail()->failure_code);
        $this->assertSame(0, SalesInvoice::count());
    }
}
