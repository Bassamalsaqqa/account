<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Application\AI\AiFormCorrections;
use App\Application\AI\AiFormEditing;
use App\Application\AI\AiFormState;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\TransactionAssistant;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Models\AssistantRequest;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AiFormCorrectionsTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
    }

    public function test_arabic_follow_up_uses_current_human_fields_and_undo_preserves_them_without_saving(): void
    {
        [$token, $state] = $this->editing();
        $payload = $state['data']['payload'];
        $payload['notes'] = 'Human note';
        $payload['lines'][0]['unit_price'] = '81';
        $result = $this->apply($token, $state, $payload, 'غير الكمية من خمس لسبع');
        $this->assertSame('7', $result['payload']['lines'][0]['quantity']);
        $this->assertSame('81', $result['payload']['lines'][0]['unit_price']);
        $this->assertSame('Human note', $result['payload']['notes']);
        $this->assertSame(['lines.0.quantity'], $result['changed_fields']);
        $this->assertTrue($result['can_undo']);
        $undo = app(AiFormCorrections::class)->undo($this->company, $this->owner, $token, 'session',
            $state['form_key'], $result['revision'], $result['payload']);
        $this->assertSame($payload, $undo['payload']);
        $this->assertFalse($undo['can_undo']);
        $this->assertSame(0, SalesInvoice::count());
        $this->assertSame(0, DB::table('posting_batches')->count());
        Http::assertNothingSent();
    }

    public function test_manual_edit_invalidates_undo_instead_of_being_overwritten(): void
    {
        [$token, $state] = $this->editing();
        $result = $this->apply($token, $state, $state['data']['payload'], 'change quantity to 7');
        $manual = $result['payload'];
        $manual['lines'][0]['quantity'] = '9';
        $revision = app(AiFormEditing::class)->synchronize($this->company, $this->owner, $token, 'session',
            $state['form_key'], $result['revision'], $manual);
        try {
            app(AiFormCorrections::class)->undo($this->company, $this->owner, $token, 'session',
                $state['form_key'], $revision, $manual);
            $this->fail('Undo must refuse after manual editing.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('form_changed', $e->reason);
        }
        $fresh = app(AiFormState::class)->read($this->company, $this->owner, $token, 'session');
        $this->assertSame('9', $fresh['data']['payload']['lines'][0]['quantity']);
        $this->assertArrayNotHasKey('undo', $fresh['data']);
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_manual_edit_during_provider_inference_refuses_the_late_patch(): void
    {
        [$token, $state] = $this->editing();
        $text = 'Please change quantity to 7';
        Http::fake(function () use ($token, $state, $text) {
            $manual = $state['data']['payload'];
            $manual['lines'][0]['quantity'] = '9';
            app(AiFormEditing::class)->synchronize($this->company, $this->owner, $token, 'session',
                $state['form_key'], $state['revision'], $manual);

            return Http::response($this->response($text, 'quantity', '7'));
        });
        try {
            $this->apply($token, $state, $state['data']['payload'], $text);
            $this->fail('Late patch must refuse.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('form_changed', $e->reason);
        }
        $fresh = app(AiFormState::class)->read($this->company, $this->owner, $token, 'session');
        $this->assertSame('9', $fresh['data']['payload']['lines'][0]['quantity']);
        Http::assertSentCount(1);
        $request = AssistantRequest::where('purpose', 'patch')->firstOrFail();
        $this->assertSame('failed', $request->status);
        $this->assertSame(28, $request->reserved_cost_micros);
        $this->assertNull($request->reported_cost_micros);
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_wrong_session_or_tab_and_disabled_corrections_dispatch_nothing(): void
    {
        [$token, $state] = $this->editing();
        foreach (['wrong-session', 'session'] as $session) {
            if ($session === 'session') {
                $this->aiPolicy->update(['follow_up_enabled' => false]);
            }
            try {
                app(AiFormCorrections::class)->apply($this->company, $this->owner, $token, $session,
                    $state['form_key'], $state['revision'], $state['data']['payload'],
                    'Please change quantity to 7', (string) Str::uuid());
                $this->fail('Unavailable correction must refuse.');
            } catch (AssistantUnavailable) {
                $this->assertSame(0, AssistantRequest::where('purpose', 'patch')->count());
            }
        }
        Http::assertNothingSent();
    }

    public function test_fabricated_value_and_model_database_ids_fail_without_fallback(): void
    {
        [$token, $state] = $this->editing();
        $text = 'Please change quantity to 7';
        $reply = $this->response($text, 'quantity', '7000');
        Http::fake(['*' => Http::response($reply)]);
        try {
            $this->apply($token, $state, $state['data']['payload'], $text);
            $this->fail('Unsupported value must refuse.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->reason);
        }
        Http::assertSentCount(1);
        $this->assertSame('5', app(AiFormState::class)->read($this->company, $this->owner, $token, 'session')['data']['payload']['lines'][0]['quantity']);
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_livewire_correction_highlights_and_undoes_on_the_existing_form(): void
    {
        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner,
            'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS', 'sales', (string) Str::uuid(), (string) Str::uuid(), session()->getId());
        $form = Livewire::withQueryParams(['assistant' => $result['token']])->test(InvoiceForm::class)
            ->set('notes', 'Keep my manual note')
            ->set('aiCorrectionText', 'change quantity to 7')
            ->call('correctAiInput')
            ->assertSet('lines.0.quantity', '5');
        $prepared = $form->effects['returns'][0];
        $form->call('applyPreparedAiInput', $prepared)
            ->assertSet('lines.0.quantity', '7')
            ->assertSet('notes', 'Keep my manual note')
            ->assertSet('aiChangedFields', ['lines.0.quantity'])
            ->assertSet('aiCanUndo', true)
            ->assertSee('Undo last correction')
            ->call('undoAiInput')
            ->assertSet('lines.0.quantity', '5')
            ->assertSet('notes', 'Keep my manual note')
            ->assertSet('aiCanUndo', false);
        $this->assertSame(0, SalesInvoice::count());
        Http::assertNothingSent();
    }

    public function test_prepared_correction_is_unsaved_bound_single_use_and_rechecked_before_commit(): void
    {
        [$token, $state] = $this->editing();
        $service = app(AiFormCorrections::class);
        $prepared = $service->prepare($this->company, $this->owner, $token, 'session',
            $state['form_key'], $state['revision'], $state['data']['payload'], 'change quantity to 7', (string) Str::uuid());
        $this->assertSame('5', app(AiFormState::class)->read($this->company, $this->owner, $token, 'session')['data']['payload']['lines'][0]['quantity']);
        $this->assertSame('pending', AssistantRequest::where('purpose', 'patch')->sole()->status);
        foreach ([['other-session', $state['form_key']], ['session', (string) Str::uuid()]] as [$session, $formKey]) {
            try {
                $service->commit($this->company, $this->owner, $token, $session, $formKey, $prepared, $state['data']['payload']);
                $this->fail('Prepared patch must remain session and tab bound.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('proposal_stale', $e->reason);
            }
        }
        $result = $service->commit($this->company, $this->owner, $token, 'session', $state['form_key'], $prepared, $state['data']['payload']);
        $this->assertSame('7', $result['payload']['lines'][0]['quantity']);
        $this->assertSame('form_edited', AssistantRequest::where('purpose', 'patch')->sole()->status);
        try {
            $service->commit($this->company, $this->owner, $token, 'session', $state['form_key'], $prepared, $state['data']['payload']);
            $this->fail('A consumed patch must never replay.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        }
        $this->assertSame(0, SalesInvoice::count());
        $this->assertSame(0, DB::table('posting_batches')->count());
        Http::assertNothingSent();
    }

    public function test_unsynchronized_browser_edit_after_prepare_refuses_commit_without_overwriting_the_form(): void
    {
        [$token, $state] = $this->editing();
        $service = app(AiFormCorrections::class);
        $prepared = $service->prepare($this->company, $this->owner, $token, 'session',
            $state['form_key'], $state['revision'], $state['data']['payload'], 'change quantity to 7', (string) Str::uuid());
        $human = $state['data']['payload'];
        $human['lines'][0]['quantity'] = '9';
        try {
            $service->commit($this->company, $this->owner, $token, 'session', $state['form_key'], $prepared, $human);
            $this->fail('Latest browser payload must match the prepared revision.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('form_changed', $e->reason);
        }
        $this->assertSame('9', $human['lines'][0]['quantity']);
        $this->assertSame('5', app(AiFormState::class)->read($this->company, $this->owner, $token, 'session')['data']['payload']['lines'][0]['quantity']);
        $service->discardPrepared($this->company, $this->owner, $prepared, 'session', $state['form_key']);
        $this->assertSame('canceled', AssistantRequest::where('purpose', 'patch')->sole()->status);
        $this->assertSame(0, SalesInvoice::count());
        Http::assertNothingSent();
    }

    public function test_policy_revocation_after_prepare_refuses_the_edit(): void
    {
        [$token, $state] = $this->editing();
        $service = app(AiFormCorrections::class);
        $prepared = $service->prepare($this->company, $this->owner, $token, 'session',
            $state['form_key'], $state['revision'], $state['data']['payload'], 'change quantity to 7', (string) Str::uuid());
        $this->aiPolicy->update(['follow_up_enabled' => false, 'revision' => $this->aiPolicy->revision + 1]);
        try {
            $service->commit($this->company, $this->owner, $token, 'session', $state['form_key'], $prepared, $state['data']['payload']);
            $this->fail('Revoked follow-up authority must be checked at acknowledgement.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        }
        $this->assertSame(0, SalesInvoice::count());
        $this->assertSame('pending', AssistantRequest::where('purpose', 'patch')->sole()->status);
        Http::assertNothingSent();
    }

    public function test_prepared_patch_expiry_does_not_extend_original_form_memory(): void
    {
        [$token, $state] = $this->editing();
        $this->travel((int) config('ai_assistant.proposal_ttl_seconds') - 10)->seconds();
        $service = app(AiFormCorrections::class);
        $prepared = $service->prepare($this->company, $this->owner, $token, 'session',
            $state['form_key'], $state['revision'], $state['data']['payload'], 'change quantity to 7', (string) Str::uuid());
        $this->travel(11)->seconds();
        try {
            $service->commit($this->company, $this->owner, $token, 'session', $state['form_key'], $prepared, $state['data']['payload']);
            $this->fail('A later prepared patch cannot revive an expired form.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        }
        $this->assertSame(0, SalesInvoice::count());
        Http::assertNothingSent();
    }

    public function test_unsynchronized_manual_fields_in_the_same_request_are_preserved_during_preparation(): void
    {
        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner,
            'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS', 'sales', (string) Str::uuid(), (string) Str::uuid(), session()->getId());
        $form = Livewire::withQueryParams(['assistant' => $result['token']])->test(InvoiceForm::class)
            ->update([['method' => 'correctAiInput', 'params' => []]], [
                'notes' => 'Human note typed before submit', 'aiCorrectionText' => 'change quantity to 7',
            ])->assertSet('lines.0.quantity', '5')->assertSet('notes', 'Human note typed before submit');
        $prepared = $form->effects['returns'][0];
        $form->call('applyPreparedAiInput', $prepared)->assertSet('lines.0.quantity', '7')
            ->assertSet('notes', 'Human note typed before submit');
        $this->assertSame(0, SalesInvoice::count());
        Http::assertNothingSent();
    }

    /** @return array{string, array<string, mixed>} */
    private function editing(): array
    {
        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner,
            'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS', 'sales', (string) Str::uuid(), (string) Str::uuid(), 'session');
        app(AiFormEditing::class)->adopt($this->company, $this->owner, $result['token'], 'session', 'sales');

        return [$result['token'], app(AiFormState::class)->read($this->company, $this->owner, $result['token'], 'session')];
    }

    /** @param array<string, mixed> $state
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function apply(string $token, array $state, array $payload, string $text): array
    {
        return app(AiFormCorrections::class)->apply($this->company, $this->owner, $token, 'session',
            $state['form_key'], $state['revision'], $payload, $text, (string) Str::uuid());
    }

    /** @return array<string, mixed> */
    private function response(string $quote, string $field, string $value): array
    {
        $proposal = ['version' => 1, 'operations' => [['op' => 'set', 'line' => null, 'field' => $field,
            'value' => $value, 'product' => null, 'unit' => null, 'amount' => null, 'quote' => $quote]]];

        return ['choices' => [['message' => ['content' => json_encode($proposal, JSON_THROW_ON_ERROR)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 80]];
    }
}
