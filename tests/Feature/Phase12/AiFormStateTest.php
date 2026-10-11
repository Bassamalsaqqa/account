<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Application\AI\AiFormState;
use App\Application\AI\AssistantUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AiFormStateTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
    }

    public function test_state_is_encrypted_and_session_form_and_revision_bound(): void
    {
        $store = app(AiFormState::class);
        $form = (string) Str::uuid();
        $token = $store->create($this->company, $this->owner, $form, 'synthetic-session', 'sales',
            ['payload' => ['notes' => 'private synthetic form fields'], 'connections' => []]);
        $cipher = Cache::get('ai-form-state:'.hash('sha256', $token));
        $this->assertIsString($cipher);
        $this->assertStringNotContainsString('private synthetic form fields', $cipher);
        $state = $store->read($this->company, $this->owner, $token, 'synthetic-session', $form);
        $this->assertSame(0, $state['revision']);
        foreach ([['other-session', $form], ['synthetic-session', (string) Str::uuid()]] as [$session, $wrongForm]) {
            try {
                $store->read($this->company, $this->owner, $token, $session, $wrongForm);
                $this->fail('Different session or tab must not adopt form memory.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('proposal_stale', $e->reason);
            }
        }
    }

    public function test_stale_voice_response_and_undo_cannot_overwrite_a_later_manual_revision(): void
    {
        $store = app(AiFormState::class);
        $form = (string) Str::uuid();
        $token = $store->create($this->company, $this->owner, $form, 'session', 'sales',
            ['payload' => ['notes' => 'original'], 'connections' => []]);
        $this->assertSame(1, $store->replace($this->company, $this->owner, $token, 'session', $form, 0,
            ['payload' => ['notes' => 'manual edit'], 'connections' => []]));
        try {
            $store->replace($this->company, $this->owner, $token, 'session', $form, 0,
                ['payload' => ['notes' => 'late voice'], 'connections' => []]);
            $this->fail('Late response must not overwrite current form.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('form_changed', $e->reason);
        }
        $this->assertSame('manual edit', $store->read($this->company, $this->owner, $token, 'session')['data']['payload']['notes']);
    }

    public function test_policy_revision_change_expiry_and_cancel_invalidate_memory(): void
    {
        $store = app(AiFormState::class);
        $form = (string) Str::uuid();
        $token = $store->create($this->company, $this->owner, $form, 'session', 'sales', ['connections' => []]);
        $store->forget($this->company, $this->owner, $token, 'session');
        try {
            $store->read($this->company, $this->owner, $token, 'session');
            $this->fail('Canceled memory must be unavailable.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        }
        $token = $store->create($this->company, $this->owner, $form, 'session', 'sales', ['connections' => []]);
        $this->aiPolicy->update(['revision' => 2]);
        try {
            $store->read($this->company, $this->owner, $token, 'session');
            $this->fail('Changed policy must invalidate memory.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        }
        $token = $store->create($this->company, $this->owner, $form, 'session', 'sales', ['connections' => []]);
        $this->travel(601)->seconds();
        try {
            $store->read($this->company, $this->owner, $token, 'session');
            $this->fail('Expired memory must refuse.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('proposal_stale', $e->reason);
        } finally {
            $this->travelBack();
        }
    }
}
