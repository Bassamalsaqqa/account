<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Application\AI\AiGovernance;
use App\Application\AI\AiSpeechMethods;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\TransactionAssistant;
use App\Livewire\AI\AssistantLauncher;
use App\Models\AiUserPreference;
use App\Models\AssistantRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AiSpeechPolicyTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
    }

    public function test_user_may_choose_company_permitted_local_only_without_overriding_guided_mode(): void
    {
        $this->aiPolicy->update(['speech_method' => 'local_if_supported', 'workflow_mode' => 'guided']);
        Livewire::test(AssistantLauncher::class)->call('showAssistant')
            ->set('transcriptionChoice', 'local_only')->call('selectConnections')
            ->assertSet('speechMethod', 'local_only')->assertSet('mode', 'guided');
        $this->assertSame('local_only', AiUserPreference::firstOrFail()->preferences['speech_method']);
        $this->assertSame('guided', $this->aiPolicy->fresh()->workflow_mode);
        try {
            app(AiGovernance::class)->authorizeUse($this->company, $this->owner, 'sales', true);
            $this->fail('A local-only selection must refuse cloud speech.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('disabled', $e->reason);
        }
        $this->assertSame(0, AssistantRequest::count());
        Http::assertNothingSent();
    }

    public function test_company_policy_constrains_preferences_and_ignores_a_now_disallowed_choice(): void
    {
        $this->aiPolicy->update(['speech_method' => 'local_if_supported']);
        app(AiSpeechMethods::class)->choose($this->company, $this->owner, 'local_only');
        $this->aiPolicy->update(['speech_method' => 'cloud', 'revision' => $this->aiPolicy->revision + 1]);
        $this->assertSame('cloud', app(AiSpeechMethods::class)->effective($this->aiPolicy->fresh(), $this->owner));
        try {
            app(AiSpeechMethods::class)->choose($this->company, $this->owner, 'local_only');
            $this->fail('User preferences must not broaden company-approved speech methods.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_configuration', $e->reason);
        }
        Http::assertNothingSent();
    }

    public function test_text_only_company_keeps_typed_forms_and_refuses_audio_before_reservation(): void
    {
        $this->aiPolicy->update(['speech_method' => 'disabled']);
        Livewire::test(AssistantLauncher::class)->call('showAssistant')->assertSet('speechMethod', 'disabled')
            ->assertDontSee('@click="start()"', false);
        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner,
            'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS', 'sales',
            (string) Str::uuid(), (string) Str::uuid(), session()->getId());
        $this->assertSame('handoff', $result['state']);
        $before = AssistantRequest::count();
        $this->postJson(route('assistant.audio'), ['form_key' => (string) Str::uuid(),
            'request_key' => (string) Str::uuid(), 'context' => 'sales', 'locale' => 'ar'])->assertForbidden();
        $this->assertSame($before, AssistantRequest::count());
        Http::assertNothingSent();
    }
}
