<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AiProviderDeadline;
use App\Application\AI\AssistantUnavailable;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Gateway\DeepSeek\DeepSeekGateway;
use Laravel\Ai\Providers\Provider;
use Psr\Http\Message\RequestInterface;

/** SDK request/parser integration with the application's existing outbound boundary. */
final class BoundedDeepSeekGateway extends DeepSeekGateway
{
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $deadline = app(AiProviderDeadline::class);
        $buffer = Utils::streamFor('');
        $received = 0;
        $sink = FnStream::decorate($buffer, ['write' => function (string $bytes) use ($buffer, &$received, $deadline): int {
            $deadline->remaining();
            if (strlen($bytes) > 131072 - $received) {
                throw new AssistantUnavailable('provider_invalid');
            }
            $received += strlen($bytes);

            return $buffer->write($bytes);
        }]);
        $curl = new CurlHandler;

        // No caller-controlled URL, redirects, proxy, retry or rewind. Native DNS and TLS verification.
        // Host-level egress restrictions remain required; a hostname allowlist is not IP isolation.
        return Http::baseUrl('https://api.deepseek.com/')
            ->withToken($provider->providerCredentials()['key'])->acceptJson()
            ->setHandler(function (RequestInterface $request, array $options) use ($curl, $sink) {
                $options['sink'] = $sink;

                return $curl($request->withBody(new NoSeekStream($request->getBody())), $options);
            })
            ->timeout($deadline->remaining())->connectTimeout(5)->withoutRedirecting()
            ->withOptions(['verify' => true, 'stream' => true, 'read_timeout' => 5, 'proxy' => '', 'expect' => false,
                'curl' => [CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 5]])
            ->afterResponse(function (Response $response) use ($deadline, $sink): Response {
                $stream = $response->toPsrResponse()->getBody();
                try {
                    if (! $response->successful()) {
                        throw new AssistantUnavailable(AssistantUnavailable::mapHttpStatusToReason($response->status()), $response->status(), 'provider_http');
                    }
                    $bytes = '';
                    while (! $stream->eof() && strlen($bytes) <= 131072) {
                        $deadline->remaining();
                        $bytes .= $stream->read(8192);
                    }
                    if (strlen($bytes) > 131072) {
                        throw new AssistantUnavailable('provider_invalid');
                    }
                    $decoded = json_decode($bytes, true, 24, JSON_THROW_ON_ERROR);
                    if (! is_array($decoded) || array_is_list($decoded)) {
                        throw new AssistantUnavailable('provider_invalid');
                    }

                    return new Response($response->toPsrResponse()->withBody(Utils::streamFor($bytes)));
                } finally {
                    $stream->close();
                    $sink->close();
                }
            });
    }
}
