<?php

declare(strict_types=1);

namespace Tests\Unit\Phase12;

use App\Application\AI\AiProviderDeadline;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCapabilityRegistry;
use App\Application\AI\ProviderCredential;
use App\Infrastructure\AI\DirectProviderTransport;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\TestCase;

class AiProviderDeadlineTest extends TestCase
{
    public function test_foreground_allowance_is_bounded_and_not_reset_between_provider_attempts(): void
    {
        config(['ai_assistant.enabled' => true, 'ai_assistant.provider' => 'direct', 'ai_assistant.provider_approved' => true,
            'ai_assistant.timeout_seconds' => 20]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['synthetic' => true])]);
        $catalog = app(ProviderCapabilityRegistry::class);
        $credential = new ProviderCredential('synthetic-deadline-credential');
        app(DirectProviderTransport::class)->send(
            $catalog->resolve('deepseek', 'deepseek-flash', 'global', 'text_structured'), $credential, []);
        $deadline = app(AiProviderDeadline::class);
        $this->assertSame($deadline, app(AiProviderDeadline::class));
        // Advance only the private monotonic start point: no sleeping or paid calls.
        (new ReflectionProperty($deadline, 'started'))->setValue($deadline, hrtime(true) - 30000000000);
        try {
            app(DirectProviderTransport::class)->send(
                $catalog->resolve('openai', 'gpt-4o-mini', 'global', 'text_structured'), $credential, []);
            $this->fail('A fallback must not acquire a fresh foreground allowance.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_outage', $e->reason);
        }
        Http::assertSentCount(1);
    }

    public function test_configuration_cannot_expand_the_hard_allowance_or_make_it_unbounded(): void
    {
        config(['ai_assistant.timeout_seconds' => 999]);
        $remaining = (new AiProviderDeadline)->remaining();
        $this->assertGreaterThanOrEqual(1, $remaining);
        $this->assertLessThanOrEqual(25, $remaining);
        $phpLimit = (int) ini_get('max_execution_time');
        if ($phpLimit > 0) {
            $this->assertLessThanOrEqual(max(1, $phpLimit - 5), $remaining);
        }
        config(['ai_assistant.timeout_seconds' => 0]);
        $this->assertSame(1, (new AiProviderDeadline)->remaining());
    }

    public function test_curated_hostname_dispatch_needs_no_destination_pin(): void
    {
        config(['ai_assistant.enabled' => true, 'ai_assistant.provider' => 'direct', 'ai_assistant.provider_approved' => true]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['synthetic' => true])]);
        $this->assertSame(['synthetic' => true], app(DirectProviderTransport::class)->send(app(ProviderCapabilityRegistry::class)->resolve(
            'deepseek', 'deepseek-flash', 'global', 'text_structured'), new ProviderCredential('synthetic-native-dns-credential'), []));
        Http::assertSentCount(1);
    }

    public function test_none_and_legacy_gateway_modes_cannot_dispatch_a_direct_provider(): void
    {
        config(['ai_assistant.enabled' => true, 'ai_assistant.provider_approved' => true]);
        Http::preventStrayRequests();
        foreach (['none', 'gateway'] as $mode) {
            config(['ai_assistant.provider' => $mode]);
            try {
                app(DirectProviderTransport::class)->send(app(ProviderCapabilityRegistry::class)->resolve(
                    'deepseek', 'deepseek-flash', 'global', 'text_structured'), new ProviderCredential('synthetic-disabled-mode-credential'), []);
                $this->fail('Direct transport must honor the global provider mode.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('disabled', $e->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_a_new_request_scope_has_its_own_allowance(): void
    {
        $first = app(AiProviderDeadline::class);
        (new ReflectionProperty($first, 'started'))->setValue($first, hrtime(true) - 30000000000);
        app()->forgetScopedInstances();
        $second = app(AiProviderDeadline::class);
        $this->assertNotSame($first, $second);
        $this->assertGreaterThan(0, $second->remaining());
    }
}
