<?php

declare(strict_types=1);

namespace Tests\Feature\DeepSeekOnly;

use App\Application\AI\AssistantUnavailable;
use App\Application\AI\TransactionAssistant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RetiredAiEndpointsTest extends TestCase
{
    public function test_legacy_endpoints_are_closed_even_when_all_platform_flags_are_enabled(): void
    {
        config([
            'ai_assistant.enabled' => true,
            'ai_assistant.provider_approved' => true,
            'ai_assistant.provider' => 'direct',
            'ai_assistant.speech_enabled' => true,
        ]);
        Http::preventStrayRequests();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        foreach (['assistant/audio', 'sales-assistant/audio', 'settings/ai/credentials'] as $path) {
            $this->postJson('/'.$path, ['audio' => 'synthetic', 'credential' => 'synthetic'])
                ->assertNotFound()->assertExactJson(['message' => 'Not found.'])
                ->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertSame(0, $queries);
        Http::assertNothingSent();
        foreach (['assistant.audio', 'sales-assistant.audio', 'settings.ai.credentials'] as $name) {
            $this->assertFalse(Route::has($name));
        }
    }

    public function test_stale_commercial_audio_token_cannot_trigger_transaction_prepare(): void
    {
        Http::preventStrayRequests();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        try {
            app(TransactionAssistant::class)->prepare(new Company, new User, 'Synthetic sale', 'sales',
                'synthetic-form', 'synthetic-request', 'synthetic-session', audioToken: 'retired-audio-token');
            $this->fail('A retired audio handoff must not be accepted.');
        } catch (AssistantUnavailable $error) {
            $this->assertSame('input_invalid', $error->reason);
        }
        $this->assertSame(0, $queries);
        Http::assertNothingSent();
    }
}
