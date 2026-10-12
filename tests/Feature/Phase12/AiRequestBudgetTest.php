<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Application\AI\AiRequestBudget;
use App\Application\AI\AssistantUnavailable;
use App\Models\AssistantRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AiRequestBudgetTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
    }

    public function test_single_primary_is_reserved_and_replay_is_not_another_dispatch(): void
    {
        $key = (string) Str::uuid();
        $form = (string) Str::uuid();
        $budget = app(AiRequestBudget::class);
        $first = $budget->reserve($this->company, $this->owner, $key, $form, 'intent', 'sales', hash('sha256', 'note'), [$this->textConnection]);
        $second = $budget->reserve($this->company, $this->owner, $key, $form, 'intent', 'sales', hash('sha256', 'note'), [$this->textConnection]);
        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['request']->id, $second['request']->id);
        $this->assertSame(14, $first['request']->reserved_cost_micros);
        $this->assertSame('unknown', $first['request']->cost_status);
        $this->assertArrayNotHasKey('attempt_plan', $first['request']->toArray());
        $this->assertSame(1, AssistantRequest::count());
        Http::assertNothingSent();
    }

    public function test_only_one_reserved_attempt_is_allowed(): void
    {
        $budget = app(AiRequestBudget::class);
        $request = $budget->reserve($this->company, $this->owner, (string) Str::uuid(), (string) Str::uuid(), 'intent', 'sales', hash('sha256', 'note'), [$this->textConnection])['request'];
        $budget->beginAttempt($this->company, $this->owner, $request, $this->textConnection);
        $this->assertSame(1, $request->fresh()->attempt_count);
        try {
            $budget->beginAttempt($this->company, $this->owner, $request, $this->textConnection);
            $this->fail('A second dispatch must refuse.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('fallback_invalid', $e->reason);
        }
        $this->assertSame(1, $request->fresh()->attempt_count);
        Http::assertNothingSent();
    }

    public function test_zero_platform_budget_and_changed_payload_cannot_reserve_or_dispatch(): void
    {
        $budget = app(AiRequestBudget::class);
        $key = (string) Str::uuid();
        $form = (string) Str::uuid();
        $budget->reserve($this->company, $this->owner, $key, $form, 'intent', 'sales', hash('sha256', 'note'), [$this->textConnection]);
        try {
            $budget->reserve($this->company, $this->owner, $key, $form, 'intent', 'sales', hash('sha256', 'different'), [$this->textConnection]);
            $this->fail('Key conflict must refuse.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('request_conflict', $e->reason);
        }
        config(['ai_assistant.request_cost_ceiling_micros' => 0]);
        try {
            $budget->reserve($this->company, $this->owner, (string) Str::uuid(), $form, 'intent', 'sales', hash('sha256', 'note'), [$this->textConnection]);
            $this->fail('Unapproved platform budget must refuse.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('budget_configuration', $e->reason);
        }
        $this->assertSame(1, AssistantRequest::count());
        Http::assertNothingSent();
    }

    public function test_deterministic_path_has_zero_text_cost_and_unknown_provider_cost_is_never_refunded(): void
    {
        $budget = app(AiRequestBudget::class);
        $local = $budget->reserve($this->company, $this->owner, (string) Str::uuid(), (string) Str::uuid(), 'intent', 'sales', hash('sha256', 'template'))['request'];
        $this->assertSame(0, $local->reserved_cost_micros);
        $this->assertSame('no_text_call', $local->cost_status);
        $cloud = $budget->reserve($this->company, $this->owner, (string) Str::uuid(), (string) Str::uuid(), 'intent', 'sales', hash('sha256', 'model'), [$this->textConnection])['request'];
        $budget->recordUsage($this->company, $this->owner, $cloud, ['input_units' => 100, 'output_units' => 80]);
        $cloud->refresh();
        $this->assertSame(14, $cloud->reserved_cost_micros);
        $this->assertNull($cloud->reported_cost_micros);
        $this->assertSame('unknown', $cloud->cost_status);
        $this->assertSame(100, $cloud->input_units);
        Http::assertNothingSent();
    }
}
