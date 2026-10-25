<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseB1Quality;

use App\Application\AI\AiGovernance;
use App\Application\AI\AssistantReportCatalog;
use App\Models\AssistantRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

final class AssistantQualityIntegrationTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        config(['ai_assistant.questions.enabled' => true, 'ai_assistant.turn_enabled' => false]);
        $settings = $this->aiPolicy->settings;
        $settings['questions'] = ['enabled' => true, 'allowed_reports' => AssistantReportCatalog::KEYS];
        $this->aiPolicy = app(AiGovernance::class)->updatePolicy($this->company, $this->owner, [
            'enabled' => true,
            'personal_credentials_enabled' => false,
            'follow_up_enabled' => true,
            'workflow_mode' => 'seamless',
            'speech_method' => 'disabled',
            'settings' => $settings,
        ], (int) $this->aiPolicy->revision);
        app(AiGovernance::class)->recordConsent($this->company, $this->owner, $this->textConnection->public_id,
            app(AiGovernance::class)->consentFingerprint($this->aiPolicy, $this->textConnection, 'question'), 'question');
        Http::preventStrayRequests();
    }

    /**
     * Verifies that 'What were my sales today?' has typed/transcribed parity,
     * resolves deterministically to active-company sales without provider calls or actor filtering.
     */
    public function test_what_were_my_sales_today_parity_and_no_provider_dispatch(): void
    {
        $phrase = 'What were my sales today?';

        // Typed
        Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->set('text', $phrase)
            ->call('prepare')
            ->assertSet('error', null)
            ->assertSet('reportResult.report_key', 'sales.summary')
            ->assertSet('reportResult.period.preset', 'today');

        // Transcribed
        Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->call('receiveTranscript', $phrase, null)
            ->assertSet('text', $phrase)
            ->assertNoRedirect()
            ->call('prepare')
            ->assertSet('error', null)
            ->assertSet('reportResult.report_key', 'sales.summary')
            ->assertSet('reportResult.period.preset', 'today');

        Http::assertNothingSent();
        $this->assertSame(0, AssistantRequest::count());
        $this->assertNoEconomicWrites();
    }

    /**
     * Verifies that 'Show my sales' prompts for period clarification across typed and transcribed.
     */
    public function test_show_my_sales_clarifies_period_with_parity(): void
    {
        $phrase = 'Show my sales';

        // Typed
        Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->set('text', $phrase)
            ->call('prepare')
            ->assertSet('error', null)
            ->assertSet('clarifyPeriodReport', 'sales.summary');

        // Transcribed
        Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->call('receiveTranscript', $phrase, null)
            ->assertSet('text', $phrase)
            ->assertNoRedirect()
            ->call('prepare')
            ->assertSet('error', null)
            ->assertSet('clarifyPeriodReport', 'sales.summary');

        Http::assertNothingSent();
        $this->assertSame(0, AssistantRequest::count());
        $this->assertNoEconomicWrites();
    }

    /**
     * Verifies that 'كم مبيعاتي؟' prompts for period clarification across typed and transcribed.
     */
    public function test_arabic_my_sales_clarifies_period_with_parity(): void
    {
        $phrase = 'كم مبيعاتي؟';

        // Typed
        Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->set('text', $phrase)
            ->call('prepare')
            ->assertSet('error', null)
            ->assertSet('clarifyPeriodReport', 'sales.summary');

        // Transcribed
        Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->call('receiveTranscript', $phrase, null)
            ->assertSet('text', $phrase)
            ->assertNoRedirect()
            ->call('prepare')
            ->assertSet('error', null)
            ->assertSet('clarifyPeriodReport', 'sales.summary');

        Http::assertNothingSent();
        $this->assertSame(0, AssistantRequest::count());
        $this->assertNoEconomicWrites();
    }

    /**
     * Verifies that 'كم مبيعات اليوم؟' resolves deterministically with parity.
     */
    public function test_arabic_sales_today_resolves_with_parity(): void
    {
        $phrase = 'كم مبيعات اليوم؟';

        // Typed
        Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->set('text', $phrase)
            ->call('prepare')
            ->assertSet('error', null)
            ->assertSet('reportResult.report_key', 'sales.summary')
            ->assertSet('reportResult.period.preset', 'today');

        // Transcribed
        Livewire::test('ai.assistant-launcher')
            ->call('showAssistant')
            ->call('receiveTranscript', $phrase, null)
            ->assertSet('text', $phrase)
            ->assertNoRedirect()
            ->call('prepare')
            ->assertSet('error', null)
            ->assertSet('reportResult.report_key', 'sales.summary')
            ->assertSet('reportResult.period.preset', 'today');

        Http::assertNothingSent();
        $this->assertSame(0, AssistantRequest::count());
        $this->assertNoEconomicWrites();
    }

    /**
     * Verifies that qualified requests with exclusions are refused deterministically with parity.
     */
    public function test_excluded_qualifiers_refuse_with_parity(): void
    {
        $queries = [
            'What were sales excluding Ahmed today?',
            'What were my sales excluding Ahmed today?',
            'مبيعات اليوم بدون أحمد',
            'مبيعاتي اليوم بدون أحمد',
        ];

        foreach ($queries as $query) {
            Livewire::test('ai.assistant-launcher')
                ->call('showAssistant')
                ->set('text', $query)
                ->call('prepare')
                ->assertSet('reportResult', null)
                ->assertSet('error', __('assistant_v2.error_question_invalid'));

            Livewire::test('ai.assistant-launcher')
                ->call('showAssistant')
                ->call('receiveTranscript', $query, null)
                ->assertSet('text', $query)
                ->assertNoRedirect()
                ->call('prepare')
                ->assertSet('reportResult', null)
                ->assertSet('error', __('assistant_v2.error_question_invalid'));
        }

        Http::assertNothingSent();
        $this->assertSame(0, AssistantRequest::count());
        $this->assertNoEconomicWrites();
    }

    private function assertNoEconomicWrites(): void
    {
        foreach (['sales_invoices', 'purchases', 'posting_batches', 'stock_movements'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }
}
