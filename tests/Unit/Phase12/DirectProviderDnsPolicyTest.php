<?php

declare(strict_types=1);

namespace Tests\Unit\Phase12;

use App\Application\AI\AiProviderDeadline;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCapabilityRegistry;
use App\Application\AI\ProviderCredential;
use App\Infrastructure\AI\DirectProviderTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class DirectProviderDnsPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_assistant.enabled' => true, 'ai_assistant.provider' => 'direct', 'ai_assistant.provider_approved' => true]);
        Http::preventStrayRequests();
    }

    public function test_mocked_network_address_rotation_requires_no_application_pin_change(): void
    {
        // Network double only: this does not certify actual DNS, TLS or Hostinger connectivity.
        $peer = '8.8.8.8';
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen, &$peer) {
            $seen[] = $options;
            $this->assertSame('https://api.deepseek.com/chat/completions', $request->url());
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertSame('', $options['curl'][CURLOPT_PROXY]);
            $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $options['curl']);
            $this->assertArrayNotHasKey(CURLOPT_CONNECT_TO, $options['curl']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertGreaterThan(0, $options['timeout']);
            $this->assertLessThanOrEqual(25, $options['timeout']);

            return Http::response(['mock_peer' => $peer]);
        });
        $this->assertSame('8.8.8.8', $this->send()['mock_peer']);
        $peer = '1.1.1.1';
        $this->assertSame('1.1.1.1', $this->send()['mock_peer']);
        $this->assertNull(config('ai_assistant.provider_ipv4'));
        $this->assertCount(2, $seen);
        Http::assertSentCount(2);
    }

    public function test_private_scheme_port_host_and_path_overrides_never_control_the_destination(): void
    {
        Http::fake(fn () => Http::response(['safe' => true]));
        foreach (['http://127.0.0.1', 'https://localhost:8443', 'https://10.0.0.1/private',
            'https://[::1]', 'file:///etc/passwd', 'https://user:pass@evil.test'] as $url) {
            $route = $this->route();
            $route['base_url'] = $url;
            $route['host'] = 'evil.test';
            $route['path'] = '/private';
            $route['proxy'] = 'http://127.0.0.1:8080';
            $this->assertSame(['safe' => true], app(DirectProviderTransport::class)->send($route, $this->credential(), []));
        }
        Http::assertSentCount(6);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.deepseek.com/chat/completions');
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('https://api.deepseek.com/chat/completions', $request->url());
        }
    }

    public function test_unsupported_catalog_identifiers_cannot_become_url_fetches(): void
    {
        foreach (['http://localhost', '127.0.0.1', 'https://10.0.0.1', 'unknown'] as $provider) {
            try {
                app(DirectProviderTransport::class)->send([...$this->route(), 'provider' => $provider], $this->credential(), []);
                $this->fail('Only registered provider identities are permitted.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('provider_configuration', $e->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_redirects_are_refused_without_followup_or_secret_leakage(): void
    {
        foreach ([301, 302, 307, 308] as $status) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response('synthetic-secret-body', $status, ['Location' => 'http://127.0.0.1/private'])]);
            try {
                $this->send();
                $this->fail('A redirect is not a successful provider response.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('provider_rejected', $e->reason);
                $this->assertStringNotContainsString('synthetic-secret', $e->getMessage());
            }
            Http::assertSentCount(1);
        }
    }

    public function test_mocked_tls_and_timeout_failures_are_sanitized_and_not_retried(): void
    {
        foreach (['TLS certificate verification failed', 'DNS/connect timeout'] as $failure) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $attempts = 0;
            Http::fake(function () use ($failure, &$attempts) {
                $attempts++;
                throw new ConnectionException($failure.' synthetic-dns-secret');
            });
            try {
                $this->send();
                $this->fail('Network failure must remain unavailable.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('provider_outage', $e->reason);
                $this->assertStringNotContainsString($failure, $e->getMessage());
                $this->assertStringNotContainsString('synthetic-dns-secret', $e->getMessage());
            }
            $this->assertSame(1, $attempts);
        }
    }

    public function test_environment_proxy_cannot_override_explicit_direct_transport_options(): void
    {
        $previous = getenv('HTTPS_PROXY');
        putenv('HTTPS_PROXY=http://127.0.0.1:8080');
        try {
            $seen = 0;
            Http::fake(function (Request $request, array $options) use (&$seen) {
                $seen++;
                $this->assertSame('', $options['proxy']);
                $this->assertSame('', $options['curl'][CURLOPT_PROXY]);
                $this->assertSame(5, $options['curl'][CURLOPT_LOW_SPEED_TIME]);

                return Http::response(['safe' => true]);
            });
            $this->assertSame(['safe' => true], $this->send());
            $this->assertSame(1, $seen);
            Http::assertSentCount(1);
        } finally {
            putenv($previous === false ? 'HTTPS_PROXY' : 'HTTPS_PROXY='.$previous);
        }
    }

    public function test_native_response_sink_aborts_oversized_decoded_writes_before_buffer_growth(): void
    {
        // Exercise the actual sink used by CurlHandler; HTTP fakes do not emulate native writes.
        $sink = (new ReflectionMethod(DirectProviderTransport::class, 'responseSink'))
            ->invoke(app(DirectProviderTransport::class), new AiProviderDeadline);
        $this->assertInstanceOf(StreamInterface::class, $sink);
        try {
            $this->assertSame(65536, $sink->write(str_repeat('x', 65536)));
            $this->assertSame(65536, $sink->write(str_repeat('x', 65536)));
            try {
                $sink->write('x');
                $this->fail('Chunked/decompressed bodies must be bounded during transfer.');
            } catch (AssistantUnavailable $e) {
                $this->assertSame('provider_invalid', $e->reason);
            }
            $this->assertSame(131072, $sink->getSize());
        } finally {
            $sink->close();
        }
    }

    public function test_native_response_sink_preserves_shared_deadline_on_new_chunks(): void
    {
        $deadline = new AiProviderDeadline;
        $deadline->remaining();
        $sink = (new ReflectionMethod(DirectProviderTransport::class, 'responseSink'))->invoke(app(DirectProviderTransport::class), $deadline);
        $this->assertInstanceOf(StreamInterface::class, $sink);
        (new ReflectionProperty($deadline, 'started'))->setValue($deadline, hrtime(true) - 30000000000);
        try {
            $sink->write('x');
            $this->fail('The response transfer must not reset its deadline.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_outage', $e->reason);
            $this->assertSame(0, $sink->getSize());
        } finally {
            $sink->close();
        }
    }

    private function route(): array
    {
        return app(ProviderCapabilityRegistry::class)->resolve('deepseek', 'deepseek-flash', 'global', 'text_structured');
    }

    private function credential(): ProviderCredential
    {
        return new ProviderCredential('synthetic-dns-secret-no-real-key');
    }

    private function send(): array
    {
        return app(DirectProviderTransport::class)->send($this->route(), $this->credential(), []);
    }
}
