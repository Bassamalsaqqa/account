<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AssistantAccess;
use App\Application\AI\AssistantUnavailable;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GatewayTransport
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function send(string $operation, array $input, ?string $wav = null): array
    {
        if (! app(AssistantAccess::class)->available() || ($operation === 'transcription' && config('ai_assistant.speech_enabled') !== true)) {
            throw new AssistantUnavailable('disabled');
        }
        $url = (string) config('ai_assistant.gateway.url');
        $host = (string) config('ai_assistant.gateway.approved_host');
        $ip = (string) config('ai_assistant.gateway.public_ipv4');
        $key = (string) config('ai_assistant.gateway.key');
        $parts = parse_url($url);
        if (! in_array($operation, ['intent', 'transcription'], true) || ! is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== $host
            || ! preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}\z/', $host)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)
            || strlen($key) < 16 || strlen($key) > 4096 || preg_match('/[\r\n]/', $key)) {
            throw new AssistantUnavailable('provider_configuration');
        }
        try {
            $input['limits'] = [
                'max_cost_micros' => (int) config('ai_assistant.request_cost_ceiling_micros'),
                'max_input_tokens' => (int) config('ai_assistant.max_input_tokens'),
                'max_output_tokens' => (int) config('ai_assistant.max_output_tokens'),
                'max_audio_seconds' => (int) config('ai_assistant.max_audio_seconds'),
            ];
            $request = Http::withToken($key)->acceptJson()->timeout(min(30, max(1, (int) config('ai_assistant.timeout_seconds'))))
                ->connectTimeout(5)->withoutRedirecting()->withOptions([
                    'verify' => true, 'stream' => true, 'read_timeout' => 5, 'proxy' => '',
                    'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$ip], CURLOPT_PROXY => ''],
                ]);
            if ($wav !== null) {
                // Guzzle multipart fields must be scalar/stream values, not a nested array.
                $input['limits'] = json_encode($input['limits'], JSON_THROW_ON_ERROR);
                $request = $request->attach('audio', $wav, 'recording.wav', ['Content-Type' => 'audio/wav']);
            }
            $response = $request->post(rtrim($url, '/').'/v1/'.$operation, $input);
            $stream = $response->toPsrResponse()->getBody();
            try {
                $body = '';
                while (! $stream->eof() && strlen($body) <= 131072) {
                    $body .= $stream->read(8192);
                }
            } finally {
                $stream->close();
            }
            if (! $response->successful() || strlen($body) > 131072) {
                throw new AssistantUnavailable('provider_failure');
            }
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_is_list($data)) {
                throw new AssistantUnavailable('provider_invalid');
            }

            return $data;
        } catch (AssistantUnavailable $e) {
            throw $e;
        } catch (Throwable) {
            // Never propagate provider URLs, auth headers, transcripts or response bodies.
            throw new AssistantUnavailable('provider_failure');
        }
    }
}
