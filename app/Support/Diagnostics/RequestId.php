<?php

declare(strict_types=1);

namespace App\Support\Diagnostics;

use App\Application\Platform\SupportViewContext;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\Request;
use Throwable;

/**
 * Server-generated opaque high-entropy request correlation identifier.
 * Never trusts caller-supplied headers and preserves early API boundary contracts.
 */
final class RequestId
{
    public const string HEADER = 'X-Request-Id';

    public const string ATTRIBUTE = '_diag_request_id';

    public const string ACTOR_SNAPSHOT_ATTRIBUTE = '_diag_actor_snapshot';

    public const string PATTERN = '/^req_[0-9a-f]{32}$/D';

    public static function generate(): string
    {
        return 'req_'.bin2hex(random_bytes(16));
    }

    public static function isValid(?string $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    public static function forRequest(?Request $request): string
    {
        if ($request === null) {
            return self::generate();
        }

        $existing = $request->attributes->get(self::ATTRIBUTE);
        if (is_string($existing) && self::isValid($existing)) {
            return $existing;
        }

        $generated = self::generate();
        $request->attributes->set(self::ATTRIBUTE, $generated);

        return $generated;
    }

    public static function current(): ?string
    {
        try {
            if (! function_exists('app') || ! app()->bound('request')) {
                return null;
            }

            return self::forRequest(request());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Preserve existing Phase 11, Phase 12, and Mobile V1/V2 boundary trace/body/header contracts.
     */
    public static function isApiBoundaryRequest(Request $request): bool
    {
        return $request->is(
            'api/v1',
            'api/v1/*',
            'api/mobile/v1',
            'api/mobile/v1/*',
            'api/mobile/v2',
            'api/mobile/v2/*',
            'sales-assistant/audio',
            'assistant/audio',
            'settings/ai/credentials',
        );
    }

    public static function captureActorSnapshot(
        Request $request,
        ?int $actualUserId,
        ?int $effectiveUserId,
        ?int $companyId,
        bool $isImpersonated,
        ?string $supportCorrelationId = null,
    ): void {
        $request->attributes->set(self::ACTOR_SNAPSHOT_ATTRIBUTE, [
            'actual_user_id' => $actualUserId,
            'effective_user_id' => $effectiveUserId,
            'company_id' => $companyId,
            'is_impersonated' => $isImpersonated,
            'support_correlation_id' => $supportCorrelationId,
        ]);
    }

    /**
     * Read captured actor/company snapshot without triggering DB queries on failure paths.
     *
     * @return array{actual_user_id: ?int, effective_user_id: ?int, company_id: ?int, is_impersonated: bool, support_correlation_id: ?string}
     */
    public static function actorSnapshot(?Request $request): array
    {
        if ($request !== null) {
            $captured = $request->attributes->get(self::ACTOR_SNAPSHOT_ATTRIBUTE);
            if (is_array($captured)) {
                return [
                    'actual_user_id' => is_int($captured['actual_user_id'] ?? null) ? $captured['actual_user_id'] : null,
                    'effective_user_id' => is_int($captured['effective_user_id'] ?? null) ? $captured['effective_user_id'] : null,
                    'company_id' => is_int($captured['company_id'] ?? null) ? $captured['company_id'] : null,
                    'is_impersonated' => (bool) ($captured['is_impersonated'] ?? false),
                    'support_correlation_id' => is_string($captured['support_correlation_id'] ?? null) ? $captured['support_correlation_id'] : null,
                ];
            }
        }

        try {
            $support = app()->bound(SupportViewContext::class) ? app(SupportViewContext::class) : null;
            $companyContext = app()->bound(CompanyContext::class) ? app(CompanyContext::class) : null;
            $guard = auth()->guard();
            $guardUser = $guard->hasUser() ? $guard->user() : null;
            $effectiveId = $guardUser !== null ? (int) $guardUser->getAuthIdentifier() : null;
            $companyId = ($companyContext !== null && $companyContext->hasCompany()) ? $companyContext->companyId() : null;

            if ($support !== null && $support->active()) {
                $details = $support->details();

                return [
                    'actual_user_id' => is_int($details['operator_id'] ?? null) ? $details['operator_id'] : $effectiveId,
                    'effective_user_id' => is_int($details['target_user_id'] ?? null) ? $details['target_user_id'] : $effectiveId,
                    'company_id' => is_int($details['company_id'] ?? null) ? $details['company_id'] : $companyId,
                    'is_impersonated' => true,
                    'support_correlation_id' => is_string($details['correlation_id'] ?? null) ? $details['correlation_id'] : null,
                ];
            }

            return [
                'actual_user_id' => $effectiveId,
                'effective_user_id' => $effectiveId,
                'company_id' => $companyId,
                'is_impersonated' => false,
                'support_correlation_id' => null,
            ];
        } catch (Throwable) {
            return [
                'actual_user_id' => null,
                'effective_user_id' => null,
                'company_id' => null,
                'is_impersonated' => false,
                'support_correlation_id' => null,
            ];
        }
    }
}
