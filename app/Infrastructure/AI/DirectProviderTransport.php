<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AiProviderDeadline;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCapabilityRegistry;
use App\Application\AI\ProviderCredential;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
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
        if ($route['provider'] === 'deepseek' && $route['capability'] === 'text_structured') {
            return app(DeepSeekSdkAdapter::class)->send($route, $credential, $body);
        }
        if ($route['capability'] === 'audio_transcription' && config('ai_assistant.speech_enabled') !== true) {
            throw new AssistantUnavailable('disabled');
        }
        $deadline = app(AiProviderDeadline::class);
        $deadline->remaining();
        $base = (string) $route['base_url'];
        // Native cURL DNS for curated HTTPS hosts; never accept client URL/host overrides.
        // Hostname allowlisting does not enforce a public resolved IP. Hosting egress is an activation gate.
        if (! extension_loaded('curl') || ! function_exists('curl_exec')) {
            throw new AssistantUnavailable('provider_configuration');
        }
        $multipart = $route['capability'] === 'audio_transcription' && $route['provider'] !== 'qwen';
        $path = $multipart ? '/audio/transcriptions' : '/chat/completions';
        if ($audio !== null && strlen($audio) > (int) config('ai_assistant.max_audio_bytes')) {
            throw new AssistantUnavailable('audio_invalid');
        }
        try {
            return $credential->use(function (string $key) use ($base, $multipart, $path, $body, $audio, $deadline): array {
                $sink = $this->responseSink($deadline);
                $curl = new CurlHandler;
                try {
                    // Keep Laravel's fake/record/authorization middleware intact. The bounded sink is
                    // attached at the native handler, where cURL actually receives response bytes.
                    $request = Http::withToken($key)->setHandler(function (RequestInterface $request, array $options) use ($curl, $sink) {
                        $options['sink'] = $sink;
                        // Refuse native rewind/replay attempts rather than duplicate a billable POST.
                        $request = $request->withBody(new NoSeekStream($request->getBody()));

                        return $curl($request, $options);
                    })->acceptJson()->timeout($deadline->remaining())
                        ->connectTimeout(5)->withoutRedirecting()->withOptions([
                            'verify' => true, 'stream' => true, 'read_timeout' => 5, 'proxy' => '', 'expect' => false,
                            'curl' => [CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 5],
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
                } finally {
                    $sink->close();
                }
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

    private function responseSink(AiProviderDeadline $deadline): StreamInterface
    {
        $buffer = Utils::streamFor('');
        $received = 0;

        // Bound decoded writes during transfer, including responses with no Content-Length.
        return FnStream::decorate($buffer, ['write' => function (string $bytes) use ($buffer, &$received, $deadline): int {
            $deadline->remaining();
            if (strlen($bytes) > 131072 - $received) {
                throw new AssistantUnavailable('provider_invalid');
            }
            $received += strlen($bytes);

            return $buffer->write($bytes);
        }]);
    }
}
