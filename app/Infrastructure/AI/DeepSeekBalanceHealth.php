<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Application\AI\AiDiagnostics;
use App\Application\AI\AiProviderDeadline;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\ProviderCredential;
use Illuminate\Http\Client\ConnectionException;
use Psr\Http\Message\StreamInterface;
use Throwable;

final class DeepSeekBalanceHealth
{
    public const string ENDPOINT = 'https://api.deepseek.com/user/balance';

    public const int MAX_RESPONSE_BYTES = DeepSeekHttpBoundary::BALANCE_MAX_BYTES;

    public const int CONNECT_TIMEOUT_SECONDS = DeepSeekHttpBoundary::CONNECT_TIMEOUT_SECONDS;

    public const int REQUEST_TIMEOUT_SECONDS = DeepSeekHttpBoundary::BALANCE_REQUEST_TIMEOUT_SECONDS;

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
            $deadline = app(AiProviderDeadline::class);
            /** @var StreamInterface|null $sink */
            $sink = null;
            $client = DeepSeekHttpBoundary::createBalanceRequest(
                $secret,
                self::REQUEST_TIMEOUT_SECONDS,
                $deadline,
                $sink,
            );
            try {
                $response = $client->get(self::ENDPOINT);
                $bytes = DeepSeekHttpBoundary::readBoundedBody($response, self::MAX_RESPONSE_BYTES, $deadline);
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
                $sink->close();
            }
        });
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
