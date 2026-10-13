<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AiDiagnostics;
use App\Application\AI\AiProviderDeadline;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCredential;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Throwable;

final class DeepSeekBalanceHealth
{
    public const string ENDPOINT = 'https://api.deepseek.com/user/balance';

    public const int MAX_RESPONSE_BYTES = 65536;

    public const int CONNECT_TIMEOUT_SECONDS = 5;

    public const int REQUEST_TIMEOUT_SECONDS = 10;

    /**
     * Executes a zero-model HTTPS GET /user/balance health check.
     * Request-local credential is used without being retained or logged.
     * Returns key_valid_funds_available or no_available_credit.
     *
     * @return 'key_valid_funds_available'|'no_available_credit'
     */
    public function check(ProviderCredential $credential): string
    {
        return $credential->use(function (string $secret): string {
            try {
                $deadline = app(AiProviderDeadline::class);
                $remaining = $deadline->remaining();
                $timeout = min(self::REQUEST_TIMEOUT_SECONDS, max(1, $remaining));
                $connectTimeout = min(self::CONNECT_TIMEOUT_SECONDS, $timeout);

                $buffer = Utils::streamFor('');
                $received = 0;
                $sink = FnStream::decorate($buffer, ['write' => function (string $bytes) use ($buffer, &$received, $deadline): int {
                    $deadline->remaining();
                    if (strlen($bytes) > self::MAX_RESPONSE_BYTES - $received) {
                        throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
                    }
                    $received += strlen($bytes);

                    return $buffer->write($bytes);
                }]);
                $curl = new CurlHandler;
                $response = Http::withToken($secret)
                    ->setHandler(function (RequestInterface $request, array $options) use ($curl, $sink) {
                        $options['sink'] = $sink;

                        return $curl($request, $options);
                    })
                    ->acceptJson()
                    ->connectTimeout($connectTimeout)
                    ->timeout($timeout)
                    ->withoutRedirecting()
                    ->withOptions([
                        'verify' => true,
                        'stream' => true,
                        'proxy' => '',
                        'expect' => false,
                        'read_timeout' => 5,
                        'curl' => [CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 5],
                    ])
                    ->get(self::ENDPOINT);

                if (! $response->successful()) {
                    $status = $response->status();
                    $reason = AssistantUnavailable::mapHttpStatusToReason($status);
                    $failure = new AssistantUnavailable($reason, $status, 'provider_http');
                    throw $failure;
                }

                $bytes = $this->readBoundedBody($response);
                $data = $this->decodeAndValidate($bytes);

                return $data['is_available'] ? 'key_valid_funds_available' : 'no_available_credit';
            } catch (AssistantUnavailable $e) {
                AiDiagnostics::record($e, $e->failureStage ?? 'provider_response');
                throw $e;
            } catch (ConnectionException) {
                $failure = new AssistantUnavailable('provider_timeout', failureStage: 'provider_transport');
                AiDiagnostics::record($failure, 'provider_transport');
                throw $failure;
            } catch (Throwable) {
                $failure = new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
                AiDiagnostics::record($failure, 'provider_response');
                throw $failure;
            } finally {
                if (isset($response)) {
                    $response->toPsrResponse()->getBody()->close();
                }
                if (isset($sink)) {
                    $sink->close();
                }
            }
        });
    }

    /**
     * Reads response stream in bounded chunks up to MAX_RESPONSE_BYTES.
     * Rejects oversized responses BEFORE unbounded JSON decode.
     */
    private function readBoundedBody(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $bytes = '';
        while (strlen($bytes) <= self::MAX_RESPONSE_BYTES) {
            if ($stream->eof()) {
                break;
            }
            app(AiProviderDeadline::class)->remaining();
            $chunk = $stream->read(min(8192, self::MAX_RESPONSE_BYTES + 1 - strlen($bytes)));
            if ($chunk === '') {
                throw new AssistantUnavailable('provider_timeout', failureStage: 'provider_transport');
            }
            $bytes .= $chunk;
        }

        if (strlen($bytes) > self::MAX_RESPONSE_BYTES) {
            throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
        }

        app(AiProviderDeadline::class)->remaining();
        $extra = $stream->read(1);
        if ($extra !== '') {
            throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
        }

        return $bytes;
    }

    /**
     * Validates is_available bool + bounded balance_infos currency/decimal strings list.
     * Never exposes exact balance values to staff or shared logs.
     *
     * @return array{is_available: bool, balance_infos: list<array<string, mixed>>}
     */
    private function decodeAndValidate(string $bytes): array
    {
        try {
            $shape = json_decode($bytes, false, 16, JSON_THROW_ON_ERROR);
            if (! $shape instanceof \stdClass || ! is_array($shape->balance_infos ?? null)) {
                throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
            }
            $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
        }

        if (! array_key_exists('is_available', $data) || ! is_bool($data['is_available'])) {
            throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
        }

        if (! array_key_exists('balance_infos', $data) || ! is_array($data['balance_infos']) || ! array_is_list($data['balance_infos'])) {
            throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
        }

        $infos = $data['balance_infos'];
        if (count($infos) > 10) {
            throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
        }

        foreach ($infos as $info) {
            if (! is_array($info) || array_is_list($info)) {
                throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
            }

            if (! isset($info['currency']) || ! is_string($info['currency']) || ! preg_match('/\A[A-Z]{3}\z/D', $info['currency'])) {
                throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
            }

            foreach (['total_balance', 'granted_balance', 'topped_up_balance'] as $field) {
                if (! isset($info[$field]) || ! is_string($info[$field]) || strlen($info[$field]) > 128 || ! preg_match('/\A-?\d+(?:\.\d+)?\z/D', $info[$field])) {
                    throw new AssistantUnavailable('provider_invalid', failureStage: 'provider_response');
                }
            }
        }

        return $data;
    }
}
