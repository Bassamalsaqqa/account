<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AiProviderDeadline;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCapabilityRegistry;
use App\Application\AI\ProviderCredential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class DirectProviderTransport
{
    /**
     * The endpoint is reconstructed from the server-owned catalog, never a client URL.
     *
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function send(array $route, ProviderCredential $credential, array $body, ?string $audio = null): array
    {
        if (config('ai_assistant.enabled') !== true || config('ai_assistant.provider_approved') !== true || config('ai_assistant.provider') !== 'direct') {
            throw new AssistantUnavailable('disabled');
        }
        $route = app(ProviderCapabilityRegistry::class)->resolve(
            (string) ($route['provider'] ?? ''), (string) ($route['model'] ?? ''),
            (string) ($route['region'] ?? ''), (string) ($route['capability'] ?? ''),
        );
        if ($route['capability'] === 'audio_transcription' && config('ai_assistant.speech_enabled') !== true) {
            throw new AssistantUnavailable('disabled');
        }
        $deadline = app(AiProviderDeadline::class);
        $deadline->remaining();
        $base = (string) $route['base_url'];
        $host = (string) parse_url($base, PHP_URL_HOST);
        $pinned = config('ai_assistant.provider_ipv4.'.$route['provider'].'.'.$route['region']);
        $addresses = is_string($pinned) && $pinned !== '' ? [$pinned] : [];
        // Require an operator-reviewed public destination; synchronous DNS has no bounded PHP timeout.
        // TLS still verifies the curated provider hostname; no user-supplied host or IP is accepted.
        if ($addresses === []) {
            throw new AssistantUnavailable('provider_configuration');
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)) {
                throw new AssistantUnavailable('provider_configuration');
            }
        }
        $multipart = $route['capability'] === 'audio_transcription' && $route['provider'] !== 'qwen';
        $path = $multipart ? '/audio/transcriptions' : '/chat/completions';
        if ($audio !== null && strlen($audio) > (int) config('ai_assistant.max_audio_bytes')) {
            throw new AssistantUnavailable('audio_invalid');
        }
        try {
            return $credential->use(function (string $key) use ($base, $host, $addresses, $multipart, $path, $body, $audio, $deadline): array {
                $request = Http::withToken($key)->acceptJson()->timeout($deadline->remaining())
                    ->connectTimeout(5)->withoutRedirecting()->withOptions([
                        'verify' => true, 'stream' => true, 'read_timeout' => 5, 'proxy' => '',
                        'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$addresses[0]], CURLOPT_PROXY => ''],
                    ]);
                if ($multipart) {
                    if ($audio === null) {
                        throw new AssistantUnavailable('audio_invalid');
                    }
                    $request = $request->attach('file', $audio, 'recording.wav', ['Content-Type' => 'audio/wav']);
                }
                $response = $request->post($base.$path, $body);
                $stream = $response->toPsrResponse()->getBody();
                try {
                    $bytes = '';
                    while (! $stream->eof() && strlen($bytes) <= 131072) {
                        $deadline->remaining();
                        $bytes .= $stream->read(8192);
                    }
                } finally {
                    $stream->close();
                }
                if (in_array($response->status(), [408, 429, 500, 502, 503, 504], true)) {
                    throw new AssistantUnavailable('provider_outage');
                }
                if (! $response->successful()) {
                    throw new AssistantUnavailable('provider_rejected');
                }
                if (strlen($bytes) > 131072) {
                    throw new AssistantUnavailable('provider_invalid');
                }
                $decoded = json_decode($bytes, true, 24, JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || array_is_list($decoded)) {
                    throw new AssistantUnavailable('provider_invalid');
                }

                return $decoded;
            });
        } catch (AssistantUnavailable $e) {
            throw $e;
        } catch (ConnectionException) {
            throw new AssistantUnavailable('provider_outage');
        } catch (Throwable) {
            // Never propagate credentials, bodies, transcripts or provider exception messages.
            throw new AssistantUnavailable('provider_invalid');
        }
    }
}
