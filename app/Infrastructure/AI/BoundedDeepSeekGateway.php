<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\DeepSeek\DeepSeekGateway;
use Laravel\Ai\Providers\Provider;

/** SDK request/parser integration with the application's existing outbound boundary. */
final class BoundedDeepSeekGateway extends DeepSeekGateway
{
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return DeepSeekHttpBoundary::createChatRequest(
            $provider->providerCredentials()['key'],
            $timeout,
        );
    }
}
