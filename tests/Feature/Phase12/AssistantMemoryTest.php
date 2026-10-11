<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Application\AI\AiFormState;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class AssistantMemoryTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
        $this->withSession(['active_company_id' => $this->company->id]);
        $this->withCookie(config('session.cookie'), session()->getId());
    }

    public function test_leaving_a_form_discards_only_owned_session_memory_and_is_idempotent(): void
    {
        $token = $this->memory(session()->getId());
        $this->post(route('assistant.memory.discard'), ['token' => $token])->assertNoContent();
        $this->assertFalse(Cache::has($this->key($token)));
        $this->post(route('assistant.memory.discard'), ['token' => $token])->assertNoContent();
        $this->assertSame(0, DB::table('sales_invoices')->count());
        $this->assertSame(0, DB::table('purchases')->count());
        Http::assertNothingSent();
    }

    public function test_foreign_actor_and_session_tokens_are_indistinguishable_but_not_deleted(): void
    {
        $token = $this->memory(session()->getId());
        $cipher = Cache::get($this->key($token));
        $otherSession = $this->memory('different-synthetic-session');
        $otherCipher = Cache::get($this->key($otherSession));
        $this->actingAs(User::factory()->create());
        $this->post(route('assistant.memory.discard'), ['token' => $token])->assertNoContent();
        $this->assertSame($cipher, Cache::get($this->key($token)));
        $this->actingAs($this->owner);
        $this->post(route('assistant.memory.discard'), ['token' => $otherSession])->assertNoContent();
        $this->assertSame($otherCipher, Cache::get($this->key($otherSession)));
        $this->post(route('assistant.memory.discard'), ['token' => (string) Str::uuid()])->assertNoContent();
        Http::assertNothingSent();
    }

    public function test_revocation_and_disabled_ai_do_not_prevent_sensitive_memory_cleanup(): void
    {
        $token = $this->memory(session()->getId());
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->owner->id)
            ->update(['status' => 'inactive']);
        config(['ai_assistant.enabled' => false]);
        $this->post(route('assistant.memory.discard'), ['token' => $token])->assertNoContent();
        $this->assertNull(Cache::get($this->key($token)));
        Http::assertNothingSent();
    }

    public function test_invalid_tokens_are_rejected_and_guests_cannot_discard_state(): void
    {
        $this->postJson(route('assistant.memory.discard'), ['token' => 'not-a-capability'])->assertUnprocessable();
        auth()->logout();
        $this->postJson(route('assistant.memory.discard'), ['token' => (string) Str::uuid()])->assertUnauthorized();
        Http::assertNothingSent();
    }

    private function memory(string $session): string
    {
        return app(AiFormState::class)->create($this->company, $this->owner, (string) Str::uuid(),
            $session, 'sales', ['connections' => [], 'payload' => ['notes' => 'Private synthetic note']]);
    }

    private function key(string $token): string
    {
        return 'ai-form-state:'.hash('sha256', $token);
    }
}
