<?php

declare(strict_types=1);

namespace App\Application\AI;

use Illuminate\Support\Facades\Log;

/** Content-free operational diagnostics. Never accept raw exception messages or response bodies. */
final class AiDiagnostics
{
    public static function record(AssistantUnavailable $failure, string $stage): void
    {
        $reason = $failure->diagnosticReason();
        if (! in_array($reason, ['provider_format', 'provider_authentication', 'provider_credit',
            'provider_parameters', 'provider_rate_limit', 'provider_outage', 'provider_timeout', 'provider_invalid'], true)
            || ! in_array($stage, ['provider_http', 'provider_transport', 'provider_response'], true)) {
            return;
        }
        $status = $failure->httpStatus;
        Log::notice('AI provider request refused.', ['failure_code' => $reason, 'stage' => $stage,
            'http_status' => $status !== null && $status >= 100 && $status <= 599 ? $status : null]);
    }
}
