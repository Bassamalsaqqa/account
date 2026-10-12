<?php

declare(strict_types=1);

namespace App\Application\AI;

use RuntimeException;

final class AssistantUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $reason = 'unavailable',
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct('Sales assistant is unavailable; continue with manual entry.');
    }

    /**
     * Standardized diagnostic reason enum for setup/UI:
     * - provider_format
     * - provider_authentication
     * - provider_credit
     * - provider_parameters
     * - provider_rate_limit
     * - provider_outage
     * - provider_timeout
     * - provider_invalid
     * Compatibility reasons:
     * - provider_rejected
     * - disabled
     * - input_invalid
     * - provider_configuration
     */
    public function diagnosticReason(): string
    {
        if (in_array($this->reason, [
            'provider_format',
            'provider_authentication',
            'provider_credit',
            'provider_parameters',
            'provider_rate_limit',
            'provider_outage',
            'provider_timeout',
            'provider_invalid',
        ], true)) {
            return $this->reason;
        }

        if ($this->httpStatus !== null) {
            return self::mapHttpStatusToReason($this->httpStatus);
        }

        return $this->reason;
    }

    public static function mapHttpStatusToReason(int $status): string
    {
        return match ($status) {
            400 => 'provider_format',
            401 => 'provider_authentication',
            402 => 'provider_credit',
            408 => 'provider_timeout',
            422 => 'provider_parameters',
            429 => 'provider_rate_limit',
            500, 502, 503, 504 => 'provider_outage',
            default => $status >= 500 ? 'provider_outage' : 'provider_invalid',
        };
    }
}
