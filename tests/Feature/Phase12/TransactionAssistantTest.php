<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Application\AI\AiFormState;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\DeterministicTransactionParser;
use App\Application\AI\TransactionAssistant;
use App\Models\AssistantRequest;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class TransactionAssistantTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
    }

    public function test_global_deterministic_sale_replays_one_unsaved_handoff_with_zero_text_calls(): void
    {
        $assistant = app(TransactionAssistant::class);
        $form = (string) Str::uuid();
        $key = (string) Str::uuid();
        $text = 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';
        $first = $assistant->prepare($this->company, $this->owner, $text, 'auto', $form, $key, 'session');
        $second = $assistant->prepare($this->company, $this->owner, $text, 'auto', $form, $key, 'session');
        $this->assertSame('handoff', $first['state']);
        $this->assertSame('sales', $first['intent']);
        $this->assertSame($first, $second);
        $this->assertSame(1, AssistantRequest::count());
        $this->assertSame(0, AssistantRequest::firstOrFail()->reserved_cost_micros);
        $this->assertSame(0, SalesInvoice::count());
        Http::assertNothingSent();
    }

    public function test_unsupported_vendor_payment_never_maps_to_a_purchase_writer(): void
    {
        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner, 'vendor payment 80 ILS', 'auto',
            (string) Str::uuid(), (string) Str::uuid(), 'session');
        $this->assertSame('unsupported', $result['state']);
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, SalesInvoice::count());
        Http::assertNothingSent();
    }

    public function test_approved_outage_uses_only_one_named_fallback_with_combined_reservation(): void
    {
        $proposal = $this->providerProposal();
        Http::fake([
            'api.deepseek.com/*' => Http::response([], 503),
            'api.openai.com/*' => Http::response($this->response($proposal)),
        ]);
        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner, $this->text(), 'auto',
            (string) Str::uuid(), (string) Str::uuid(), 'session');
        $this->assertSame('handoff', $result['state']);
        Http::assertSentCount(2);
        $request = AssistantRequest::firstOrFail();
        $this->assertSame(2, $request->attempt_count);
        $this->assertSame(28, $request->reserved_cost_micros);
        $this->assertNull($request->reported_cost_micros);
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_invalid_output_never_retries_and_unknown_cost_reservation_is_retained(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{broken'], 'finish_reason' => 'stop']]])]);
        try {
            app(TransactionAssistant::class)->prepare($this->company, $this->owner, $this->text(), 'auto',
                (string) Str::uuid(), (string) Str::uuid(), 'session');
            $this->fail('Malformed output must refuse.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->reason);
        }
        Http::assertSentCount(1);
        $this->assertSame('failed', AssistantRequest::firstOrFail()->status);
        $this->assertSame(28, AssistantRequest::firstOrFail()->reserved_cost_micros);
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_high_confidence_fabricated_numeric_payload_cannot_route_or_fallback(): void
    {
        $proposal = $this->providerProposal();
        $proposal['lines'][0]['unit_price'] = '8000';
        Http::fake(['*' => Http::response($this->response($proposal))]);
        try {
            app(TransactionAssistant::class)->prepare($this->company, $this->owner, $this->text(), 'auto',
                (string) Str::uuid(), (string) Str::uuid(), 'session');
            $this->fail('Fabricated price must not become a draft.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('evidence_invalid', $e->reason);
        }
        Http::assertSentCount(1);
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_cancel_during_inference_suppresses_late_handoff_without_claiming_unbilled_request(): void
    {
        $assistant = app(TransactionAssistant::class);
        $form = (string) Str::uuid();
        Http::fake(function () use ($assistant, $form) {
            $assistant->cancel($this->company, $this->owner, $form, 'session');

            return Http::response($this->response($this->providerProposal()));
        });
        try {
            $assistant->prepare($this->company, $this->owner, $this->text(), 'auto', $form, (string) Str::uuid(), 'session');
            $this->fail('Canceled inference must not hand off.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('request_canceled', $e->reason);
        }
        Http::assertSentCount(1);
        $request = AssistantRequest::firstOrFail();
        $this->assertSame('canceled', $request->status);
        $this->assertSame('unknown', $request->cost_status);
        $this->assertSame(28, $request->reserved_cost_micros);
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_human_currency_rate_clarification_retains_exact_resolved_line_and_does_not_dispatch_again(): void
    {
        $text = 'Please sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 USD';
        $proposal = app(DeterministicTransactionParser::class)->parse(substr($text, 7));
        $proposal['evidence'] = array_map(fn ($path, $quote) => ['path' => $path, 'quote' => $quote],
            array_keys($proposal['evidence']), array_values($proposal['evidence']));
        Http::fake(['*' => Http::response($this->response($proposal))]);
        $form = (string) Str::uuid();
        $assistant = app(TransactionAssistant::class);
        $first = $assistant->prepare($this->company, $this->owner, $text, 'sales', $form, (string) Str::uuid(), 'session');
        $this->assertSame('clarifying', $first['state']);
        $this->assertSame('exchange_rate', $first['questions'][0]['path']);
        try {
            $assistant->clarify($this->company, $this->owner, $first['token'], 'session', $form, ['exchange_rate' => 3.65]);
            $this->fail('Financial clarifications cannot contain a float.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('choice_invalid', $e->reason);
        }
        $next = $assistant->clarify($this->company, $this->owner, $first['token'], 'session', $form, ['exchange_rate' => '3.6500000000']);
        $this->assertSame('handoff', $next['state']);
        $state = app(AiFormState::class)->read($this->company, $this->owner, $first['token'], 'session', $form);
        $this->assertSame('3.6500000000', $state['data']['resolved']['payload']['exchange_rate']);
        $this->assertSame('5', $state['data']['resolved']['payload']['lines'][0]['quantity']);
        $this->assertSame('human_edit', $state['data']['resolved']['origins']['exchange_rate']);
        Http::assertSentCount(1);
        $this->assertSame(0, SalesInvoice::count());
    }

    public function test_cancel_after_handoff_deletes_memory_and_cannot_replay_it(): void
    {
        $assistant = app(TransactionAssistant::class);
        $form = (string) Str::uuid();
        $key = (string) Str::uuid();
        $text = 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';
        $result = $assistant->prepare($this->company, $this->owner, $text, 'sales', $form, $key, 'session');
        $assistant->cancel($this->company, $this->owner, $form, 'session');
        $this->assertSame('canceled', AssistantRequest::firstOrFail()->status);
        $this->assertNull(Cache::get('ai-form-state:'.hash('sha256', $result['token'])));
        try {
            $assistant->prepare($this->company, $this->owner, $text, 'sales', $form, $key, 'session');
            $this->fail('Canceled result must not replay.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('request_canceled', $e->reason);
        }
        Http::assertNothingSent();
    }

    private function text(): string
    {
        return 'Please sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS';
    }

    private function providerProposal(): array
    {
        $proposal = app(DeterministicTransactionParser::class)->parse(substr($this->text(), 7));
        $proposal['evidence'] = array_map(fn ($path, $quote) => ['path' => $path, 'quote' => $quote],
            array_keys($proposal['evidence']), array_values($proposal['evidence']));

        return $proposal;
    }

    private function response(array $proposal): array
    {
        return ['choices' => [['message' => ['content' => json_encode($proposal, JSON_THROW_ON_ERROR)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 80]];
    }
}
