<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Application\AI\AiFormEditing;
use App\Application\AI\AiFormState;
use App\Application\AI\AiTranscriptRevisions;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\TransactionAssistant;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Models\AssistantRequest;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AiTranscriptRevisionsTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
    }

    public function test_original_description_is_available_only_on_demand_and_reprocessing_requires_confirmation(): void
    {
        $text = 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';
        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner,
            $text, 'sales', (string) Str::uuid(), (string) Str::uuid(), session()->getId());
        Livewire::withQueryParams(['assistant' => $result['token']])->test(InvoiceForm::class)
            ->assertSet('aiTranscriptText', '')
            ->assertSee('Edit what I said')
            ->call('editAiTranscript')
            ->assertSet('aiTranscriptText', $text)
            ->assertSee('may consume additional approved usage')
            ->call('prepareAiTranscript')
            ->assertStatus(409);
        $this->assertSame(1, AssistantRequest::count());
        $this->noBusinessWrites();
    }

    public function test_explicit_reprocessing_opens_a_new_unsaved_sales_form_only_after_acknowledgement(): void
    {
        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner,
            'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS', 'sales', (string) Str::uuid(), (string) Str::uuid(), session()->getId());
        $form = Livewire::withQueryParams(['assistant' => $result['token']])->test(InvoiceForm::class)
            ->set('notes', 'Manual edit explicitly replaced')
            ->call('editAiTranscript')
            ->set('aiTranscriptText', 'sell to "Ahmed": 7 "carton" "SOAP-3L" at 81 ILS')
            ->set('aiTranscriptReplaceConfirmed', true)
            ->call('prepareAiTranscript')
            ->assertSet('lines.0.quantity', '5')
            ->assertSet('notes', 'Manual edit explicitly replaced');
        $prepared = $form->__get('effects')['returns'][0];
        try {
            app(AiFormEditing::class)->adopt($this->company, $this->owner, $prepared, session()->getId(), 'sales');
            $this->fail('A prepared replacement must not bypass acknowledgement through its URL.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        }
        $form->call('applyPreparedAiTranscript', $prepared)->assertRedirect(route('invoices.create', ['assistant' => $prepared]));
        $this->assertNull(Cache::get('ai-form-state:'.hash('sha256', $result['token'])));
        Livewire::withQueryParams(['assistant' => $prepared])->test(InvoiceForm::class)
            ->assertSet('lines.0.quantity', '7')->assertSet('lines.0.unit_price', '81')
            ->assertSet('notes', null)->assertSee('Unsaved editable form');
        $this->assertSame(2, AssistantRequest::count());
        $this->noBusinessWrites();
    }

    public function test_unsynchronized_latest_browser_edit_refuses_replacement_and_discard_cancels_only_the_new_request(): void
    {
        [$token, $state] = $this->editing();
        $service = app(AiTranscriptRevisions::class);
        $prepared = $service->prepare($this->company, $this->owner, $token, 'session', $state['form_key'],
            $state['revision'], $state['data']['payload'], 'sell to "Ahmed": 7 "carton" "SOAP-3L" at 80 ILS');
        $manual = $state['data']['payload'];
        $manual['lines'][0]['quantity'] = '9';
        try {
            $service->acknowledge($this->company, $this->owner, $token, 'session', $state['form_key'], $prepared, $manual);
            $this->fail('Human input after inference began must refuse replacement.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('form_changed', $e->reason);
        }
        $service->discard($this->company, $this->owner, $prepared, 'session', $state['form_key']);
        $this->assertSame(['form_ready', 'canceled'], AssistantRequest::orderBy('id')->pluck('status')->all());
        $this->assertSame('5', app(AiFormState::class)->read($this->company, $this->owner, $token, 'session')['data']['payload']['lines'][0]['quantity']);
        $this->assertSame('9', $manual['lines'][0]['quantity']);
        $this->noBusinessWrites();
    }

    public function test_clarification_preserves_the_old_form_and_requires_a_second_acknowledgement(): void
    {
        [$token, $state] = $this->editing();
        $other = $this->customer->replicate(['public_id']);
        $other->code = 'SYN-SECOND';
        $other->save();
        $service = app(AiTranscriptRevisions::class);
        $prepared = $service->prepare($this->company, $this->owner, $token, 'session', $state['form_key'],
            $state['revision'], $state['data']['payload'], 'sell to "Ahmed": 7 "carton" "SOAP-3L" at 80 ILS');
        $result = $service->acknowledge($this->company, $this->owner, $token, 'session', $state['form_key'], $prepared, $state['data']['payload']);
        $this->assertSame('clarifying', $result['state']);
        $this->assertSame('party', $result['questions'][0]['path']);
        $this->assertCount(2, $result['questions'][0]['candidates']);
        $this->assertSame($prepared, $service->clarify($this->company, $this->owner, $token, 'session',
            $state['form_key'], $prepared, $state['data']['payload'], ['party' => (int) $other->id]));
        $this->assertIsString(Cache::get('ai-form-state:'.hash('sha256', $token)));
        $result = $service->acknowledge($this->company, $this->owner, $token, 'session', $state['form_key'], $prepared, $state['data']['payload']);
        $this->assertSame('handoff', $result['state']);
        $adopted = app(AiFormEditing::class)->adopt($this->company, $this->owner, $prepared, 'session', 'sales');
        $this->assertSame($other->id, $adopted['data']['payload']['customer_id']);
        $this->assertSame('7', $adopted['data']['payload']['lines'][0]['quantity']);
        $this->noBusinessWrites();
    }

    public function test_numeric_clarification_uses_the_rendered_index_and_keeps_exact_rate_until_acknowledgement(): void
    {
        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner,
            'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS', 'sales', (string) Str::uuid(), (string) Str::uuid(), session()->getId());
        $form = Livewire::withQueryParams(['assistant' => $result['token']])->test(InvoiceForm::class)
            ->call('editAiTranscript')
            ->set('aiTranscriptText', 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 USD')
            ->set('aiTranscriptReplaceConfirmed', true)
            ->call('prepareAiTranscript');
        $prepared = $form->__get('effects')['returns'][0];
        $form->call('applyPreparedAiTranscript', $prepared)
            ->assertSet('aiTranscriptQuestions.0.path', 'exchange_rate')
            ->assertSee('wire:model="aiTranscriptAnswers.0"', false)
            ->assertSet('currency_code', 'ILS')
            ->set('aiTranscriptAnswers.0', '3.6500000000')
            ->call('prepareAiTranscriptClarification')
            ->assertSet('currency_code', 'ILS');
        $this->assertSame($prepared, $form->__get('effects')['returns'][0]);
        $form->call('applyPreparedAiTranscript', $prepared)
            ->assertRedirect(route('invoices.create', ['assistant' => $prepared]));
        Livewire::withQueryParams(['assistant' => $prepared])->test(InvoiceForm::class)
            ->assertSet('currency_code', 'USD')->assertSet('exchange_rate', '3.6500000000')
            ->assertSet('lines.0.quantity', '5')->assertSet('lines.0.unit_price', '80');
        $this->noBusinessWrites();
    }

    public function test_original_intent_session_and_current_policy_remain_authoritative(): void
    {
        [$token, $state] = $this->editing();
        $service = app(AiTranscriptRevisions::class);
        try {
            $service->prepare($this->company, $this->owner, $token, 'session', $state['form_key'],
                $state['revision'], $state['data']['payload'], 'buy from "Supplier": 2 "carton" "SOAP-3L" at 24 ILS');
            $this->fail('Reprocessing a Sale cannot silently turn it into a Purchase.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('intent_mismatch', $e->reason);
        }
        $prepared = $service->prepare($this->company, $this->owner, $token, 'session', $state['form_key'],
            $state['revision'], $state['data']['payload'], 'sell to "Ahmed": 7 "carton" "SOAP-3L" at 80 ILS');
        try {
            $service->acknowledge($this->company, $this->owner, $token, 'other-session', $state['form_key'], $prepared, $state['data']['payload']);
            $this->fail('Wrong session must refuse.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        }
        $this->aiPolicy->update(['revision' => $this->aiPolicy->revision + 1]);
        try {
            $service->acknowledge($this->company, $this->owner, $token, 'session', $state['form_key'], $prepared, $state['data']['payload']);
            $this->fail('Changed policy must refuse.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        }
        $this->noBusinessWrites();
    }

    /** @return array{string, array<string, mixed>} */
    private function editing(): array
    {
        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner,
            'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS', 'sales', (string) Str::uuid(), (string) Str::uuid(), 'session');
        app(AiFormEditing::class)->adopt($this->company, $this->owner, $result['token'], 'session', 'sales');

        return [$result['token'], app(AiFormState::class)->read($this->company, $this->owner, $result['token'], 'session')];
    }

    private function noBusinessWrites(): void
    {
        $this->assertSame(0, SalesInvoice::count());
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, DB::table('posting_batches')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        Http::assertNothingSent();
    }
}
