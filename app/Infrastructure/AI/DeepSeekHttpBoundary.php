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
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;
use Throwable;

/**
 * Shared outbound HTTP boundary strictly bound to https://api.deepseek.com/.
 * Enforces native DNS, TLS verification, disabled redirects/proxy/retries,
 * no rewind of paid bodies, connect/deadline/low-speed timeouts,
 * request-local bounded streaming sinks, and sanitized HTTP error mapping.
 */
final class DeepSeekHttpBoundary
{
    public const string BASE_URL = 'https://api.deepseek.com/';

    public const int CHAT_MAX_BYTES = 131072;

    public const int BALANCE_MAX_BYTES = 65536;

    public const int CONNECT_TIMEOUT_SECONDS = 5;

    public const int BALANCE_REQUEST_TIMEOUT_SECONDS = 10;

    public const int READ_TIMEOUT_SECONDS = 5;

    public const int LOW_SPEED_LIMIT_BYTES = 1;

    public const int LOW_SPEED_TIME_SECONDS = 5;

    /**
     * Creates a shared bounded PendingRequest strictly targeting https://api.deepseek.com/.
     * Request-local sink and curl handler enforce streaming write bounds.
     *
     * @param  StreamInterface|null  $sink  Populated with the request-local sink stream for closure/cleanup.
     *
     * @param-out StreamInterface  $sink
     */
    public static function createRequest(
        #[SensitiveParameter] string $secret,
        int $maxBytes,
        ?int $timeout = null,
        ?AiProviderDeadline $deadline = null,
        ?StreamInterface &$sink = null,
    ): PendingRequest {
        $deadline ??= app(AiProviderDeadline::class);
        $remaining = $deadline->remaining();
        $requestTimeout = $timeout !== null ? min($timeout, max(1, $remaining)) : $remaining;
        $connectTimeout = min(self::CONNECT_TIMEOUT_SECONDS, $requestTimeout);

        $buffer = Utils::streamFor('');
        $received = 0;
        $sink = FnStream::decorate($buffer, ['write' => function (string $bytes) use ($buffer, &$received, $deadline, $maxBytes): int {
            $deadline->remaining();
            if (strlen($bytes) > $maxBytes - $received) {
                throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
            }
            $received += strlen($bytes);

            return $buffer->write($bytes);
        }]);

        $curl = new CurlHandler;
        $sinkRef = $sink;

        return Http::baseUrl(self::BASE_URL)
            ->withToken($secret)
            ->acceptJson()
            ->setHandler(function (RequestInterface $request, array $options) use ($curl, $sinkRef) {
                $options['sink'] = $sinkRef;

                return $curl($request->withBody(new NoSeekStream($request->getBody())), $options);
            })
            ->timeout($requestTimeout)
            ->connectTimeout($connectTimeout)
            ->withoutRedirecting()
            ->withOptions([
                'verify' => true,
                'stream' => true,
                'proxy' => '',
                'expect' => false,
                'read_timeout' => self::READ_TIMEOUT_SECONDS,
                'curl' => [
                    CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT_BYTES,
                    CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME_SECONDS,
                ],
            ]);
    }

    /**
     * Creates a bounded request factory for chat completions via Laravel AI SDK.
     * Attaches afterResponse middleware enforcing status, byte limits, object JSON structure, and cleanup.
     */
    public static function createChatRequest(
        #[SensitiveParameter] string $secret,
        ?int $timeout = null,
        ?AiProviderDeadline $deadline = null,
    ): PendingRequest {
        $deadline ??= app(AiProviderDeadline::class);
        $sink = null;
        $client = self::createRequest($secret, self::CHAT_MAX_BYTES, $timeout, $deadline, $sink);

        return $client->afterResponse(function (Response $response) use ($deadline, $sink): Response {
            $stream = $response->toPsrResponse()->getBody();
            try {
                $bytes = self::readBoundedBody($response, self::CHAT_MAX_BYTES, $deadline);
                $decoded = json_decode($bytes, true, 24, JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || array_is_list($decoded)) {
                    throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
                }

                return new Response($response->toPsrResponse()->withBody(Utils::streamFor($bytes)));
            } catch (AssistantUnavailable $e) {
                throw $e;
            } catch (Throwable) {
                throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
            } finally {
                $stream->close();
                $sink->close();
            }
        });
    }

    /**
     * Creates a bounded request for the free balance health endpoint.
     *
     * @param  StreamInterface|null  $sink  Populated with the request-local sink stream for closure/cleanup.
     *
     * @param-out StreamInterface  $sink
     */
    public static function createBalanceRequest(
        #[SensitiveParameter] string $secret,
        ?int $timeout = self::BALANCE_REQUEST_TIMEOUT_SECONDS,
        ?AiProviderDeadline $deadline = null,
        ?StreamInterface &$sink = null,
    ): PendingRequest {
        return self::createRequest($secret, self::BALANCE_MAX_BYTES, $timeout, $deadline, $sink);
    }

    /**
     * Asserts that the HTTP response is 2xx, or throws a sanitized AssistantUnavailable exception.
     */
    public static function assertSuccessfulResponse(Response $response): void
    {
        if (! $response->successful()) {
            $status = $response->status();
            $reason = AssistantUnavailable::mapHttpStatusToReason($status);

            throw new AssistantUnavailable($reason, $status, 'provider_http');
        }
    }

    /**
     * Reads a response stream in bounded chunks up to $maxBytes.
     * Enforces deadline, catches stalled empty reads, and rejects oversized responses before JSON decode.
     */
    public static function readBoundedBody(
        Response $response,
        int $maxBytes,
        ?AiProviderDeadline $deadline = null,
    ): string {
        self::assertSuccessfulResponse($response);

        $deadline ??= app(AiProviderDeadline::class);
        $stream = $response->toPsrResponse()->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $bytes = '';
        while (strlen($bytes) <= $maxBytes) {
            if ($stream->eof()) {
                break;
            }
            $deadline->remaining();
            $chunk = $stream->read(min(8192, $maxBytes + 1 - strlen($bytes)));
            if ($chunk === '') {
                throw new AssistantUnavailable('provider_timeout', failureStage: 'provider_transport');
            }
            $bytes .= $chunk;
        }

        if (strlen($bytes) > $maxBytes) {
            throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
        }

        $deadline->remaining();
        $extra = $stream->read(1);
        if ($extra !== '') {
            throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
        }

        return $bytes;
    }
}
