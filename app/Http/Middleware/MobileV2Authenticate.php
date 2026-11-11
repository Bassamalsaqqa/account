<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Platform\SupportViewContext;
use App\Application\Platform\SupportViewSession;
use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

final class MobileV2Authenticate
{
    /**
     * Reviewed non-financial capabilities permitted on Mobile V2 probes.
     * All financial operations and unknown capabilities fail closed.
     */
    public const array ALLOWED_NON_FINANCIAL_CAPABILITIES = [
        'product.viewAny',
        'identity.verify',
        'identity.probe',
    ];

    /**
     * Handle authentication, identity qualification, and scope verification for Mobile V2 requests.
     */
    public function handle(Request $request, Closure $next, ?string $requiredPermission = null): Response
    {
        $context = app(CompanyContext::class);
        $context->clear();

        try {
            return $this->handleAuthenticated($request, $next, $requiredPermission);
        } finally {
            $context->clear();
            app(SupportViewContext::class)->clear();
            if (auth()->check()) {
                auth()->logout();
            }
            $request->attributes->remove('mobile_user');
            $request->attributes->remove('mobile_token');
            $request->attributes->remove('mobile_device');
            $request->attributes->remove('mobile_company');
            $request->setUserResolver(fn () => null);
        }
    }

    private function handleAuthenticated(Request $request, Closure $next, ?string $requiredPermission = null): Response
    {
        // 1. Sessionless: Deny active support impersonation / delegation context explicitly
        $hasSupportSession = ($request->hasSession() && $request->session()->has(SupportViewSession::KEY))
            || ($request->cookies->has(SupportViewSession::KEY))
            || (app()->bound('session') && app('session')->isStarted() && app('session')->has(SupportViewSession::KEY))
            || (app()->bound('session.store') && app('session.store')->has(SupportViewSession::KEY));

        if (app(SupportViewContext::class)->active() || $hasSupportSession) {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Support impersonation is unauthorized on Mobile V2.',
                status: 403
            );
        }

        // 2. Extract Bearer token from Authorization header without silent trimming
        $authHeader = (string) $request->header('Authorization');
        if (! str_starts_with($authHeader, 'Bearer ')) {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required or token expired.',
                status: 401
            );
        }

        $rawToken = substr($authHeader, 7);
        if ($rawToken === '' || strlen($rawToken) > 256 || preg_match('/[\s\x00-\x1F\x7F]/', $rawToken)) {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required or token expired.',
                status: 401
            );
        }

        // 3. Explicit route company vs payload company disagreement check BEFORE lookups
        $routeCompanyId = $request->route('companyId') ?? $request->route('company_id');
        if ($routeCompanyId !== null) {
            $rawBody = (string) $request->getContent();
            if ($rawBody !== '') {
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded)) {
                    $hasSnake = array_key_exists('company_id', $decoded);
                    $hasCamel = array_key_exists('companyId', $decoded);

                    if ($hasSnake || $hasCamel) {
                        $snakeVal = $decoded['company_id'] ?? null;
                        $camelVal = $decoded['companyId'] ?? null;

                        if ($hasSnake && (! is_string($snakeVal) || trim($snakeVal) === '')) {
                            return MobileV2ApiBoundary::buildErrorResponse(
                                code: 'VALIDATION_FAILED',
                                message: 'Company identifier in payload must be a non-empty string.',
                                status: 422,
                                details: ['company_id' => ['The company_id must be a valid string.']]
                            );
                        }

                        if ($hasCamel && (! is_string($camelVal) || trim($camelVal) === '')) {
                            return MobileV2ApiBoundary::buildErrorResponse(
                                code: 'VALIDATION_FAILED',
                                message: 'Company identifier in payload must be a non-empty string.',
                                status: 422,
                                details: ['companyId' => ['The companyId must be a valid string.']]
                            );
                        }

                        if ($hasSnake && $hasCamel && ! hash_equals((string) $snakeVal, (string) $camelVal)) {
                            return MobileV2ApiBoundary::buildErrorResponse(
                                code: 'VALIDATION_FAILED',
                                message: 'Conflicting company identifier aliases provided in payload.',
                                status: 422,
                                details: ['company_id' => ['The company_id and companyId aliases do not match.']]
                            );
                        }

                        $payloadCompanyId = (string) ($snakeVal ?? $camelVal);
                        if (! hash_equals((string) $routeCompanyId, $payloadCompanyId)) {
                            return MobileV2ApiBoundary::buildErrorResponse(
                                code: 'VALIDATION_FAILED',
                                message: 'Company identifier in payload does not match route context.',
                                status: 422,
                                details: ['company_id' => ['The company_id does not match the route context.']]
                            );
                        }
                    }
                }
            }
        }

        // 4. Hash and look up token
        $tokenHash = hash('sha256', $rawToken);
        /** @var MobileAccessToken|null $token */
        $token = MobileAccessToken::where('token_hash', $tokenHash)->first();

        if ($token === null || ! $token->isValid()) {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'The Bearer token has been revoked or expired.',
                status: 401,
                details: ['reason' => 'TOKEN_INVALID_OR_EXPIRED']
            );
        }

        // 5. Verify device status and strict ownership
        /** @var MobileDevice|null $device */
        $device = MobileDevice::where('device_id', $token->device_id)->first();
        if ($device === null || $device->isRevoked() || (int) $device->user_id !== (int) $token->user_id) {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'The Bearer token has been revoked or expired.',
                status: 401,
                details: [
                    'reason' => 'ACTOR_REVOKED',
                    'revoked_at' => $device?->revoked_at?->format('Y-m-d\TH:i:s\Z') ?? now()->format('Y-m-d\TH:i:s\Z'),
                ]
            );
        }

        // 6. Verify User and password revision epoch
        /** @var User|null $user */
        $user = User::find($token->user_id);
        if ($user === null) {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'The Bearer token has been revoked or expired.',
                status: 401,
                details: ['reason' => 'USER_NOT_FOUND']
            );
        }

        $currentEpoch = 'epoch-'.substr(hash('sha256', (string) $user->password), 0, 16);
        if (! hash_equals($currentEpoch, $token->password_epoch)) {
            $token->update(['revoked_at' => now()]);

            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'The Bearer token has been revoked or expired.',
                status: 401,
                details: ['reason' => 'PASSWORD_REVISED']
            );
        }

        // 7. Identity conflation / mixed auth check: reject mismatched ambient browser actor
        if (auth()->check() && (int) auth()->id() !== (int) $user->id) {
            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Mixed authentication context detected. Ambient session does not match native bearer token.',
                status: 401
            );
        }

        // Bind verified native actor into auth() so CompanyContext::user() returns verified actor
        auth()->setUser($user);

        // 8. Tenant and company context qualification:
        // No default company fallback; route company must be explicitly provided
        $company = null;
        if ($routeCompanyId !== null && is_string($routeCompanyId)) {
            /** @var Company|null $company */
            $company = Company::where('public_id', $routeCompanyId)->first();

            // Absence or non-active company returns identical 403 without existence oracle
            if ($company === null || $company->status !== 'active') {
                return MobileV2ApiBoundary::buildErrorResponse(
                    code: 'FORBIDDEN_TENANT_MISMATCH',
                    message: 'User does not possess active authorized membership in the requested company context.',
                    status: 403,
                    details: ['requested_company_id' => $routeCompanyId]
                );
            }

            // Verify active membership in company_user (User has no active column; company_user is authoritative)
            $isMember = $user->activeCompanies()
                ->where('companies.id', $company->id)
                ->exists();

            if (! $isMember) {
                return MobileV2ApiBoundary::buildErrorResponse(
                    code: 'FORBIDDEN_TENANT_MISMATCH',
                    message: 'User does not possess active authorized membership in the requested company context.',
                    status: 403,
                    details: ['requested_company_id' => $routeCompanyId]
                );
            }

            // Sessionless CompanyContext activation
            app(CompanyContext::class)->setCompany($company, $user);
            $request->attributes->set('mobile_company', $company);
        }

        // 9. Permission and Financial Authority Enforcement
        if ($requiredPermission !== null) {
            // Financial authority is unresolved on V2: Bearer token proves staff/device identity ONLY, NEVER financial posting permission
            if (self::isFinancialPermission($requiredPermission)) {
                return MobileV2ApiBoundary::buildErrorResponse(
                    code: 'FINANCIAL_AUTHORITY_UNRESOLVED',
                    message: "Financial authority is unresolved on Mobile V2. Bearer token proves staff/device identity only, never financial posting permission for [{$requiredPermission}].",
                    status: 403
                );
            }

            // Only reviewed non-financial capabilities on allowlist are permitted
            if (! in_array($requiredPermission, self::ALLOWED_NON_FINANCIAL_CAPABILITIES, true)) {
                return MobileV2ApiBoundary::buildErrorResponse(
                    code: 'FORBIDDEN',
                    message: "Action is unauthorized. Requested capability [{$requiredPermission}] is not on the reviewed capability allowlist.",
                    status: 403
                );
            }

            if ($company === null) {
                return MobileV2ApiBoundary::buildErrorResponse(
                    code: 'FORBIDDEN',
                    message: "Action is unauthorized. Missing required company context for capability [{$requiredPermission}].",
                    status: 403
                );
            }

            // Actual permission checking: NO role names check, NO bypass, NO owner/superadmin exemption
            if (! $this->userHasPermission($user, $company, $requiredPermission)) {
                return MobileV2ApiBoundary::buildErrorResponse(
                    code: 'FORBIDDEN',
                    message: "Action is unauthorized. Missing required permission [{$requiredPermission}].",
                    status: 403
                );
            }
        }

        // 10. Bounded rate limit per authenticated actor + company
        $actorKey = 'mobile_v2:actor:'.$user->id.':company:'.($routeCompanyId ?? 'none');
        $maxAttempts = (int) config('mobile.rate_limits.authenticated_per_minute', 120);
        if (RateLimiter::tooManyAttempts($actorKey, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($actorKey);

            return MobileV2ApiBoundary::buildErrorResponse(
                code: 'TOO_MANY_REQUESTS',
                message: "Rate limit exceeded. Please retry after {$retryAfter} seconds.",
                status: 429,
                headers: ['Retry-After' => (string) $retryAfter]
            );
        }
        RateLimiter::hit($actorKey, 60);

        // 11. Update token last used timestamp
        $token->update(['last_used_at' => now()]);

        // 12. Attach verified context
        $request->attributes->set('mobile_user', $user);
        $request->attributes->set('mobile_token', $token);
        $request->attributes->set('mobile_device', $device);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    /**
     * Check if user has permission in company without role name bypass, evicting Spatie cache.
     */
    private function userHasPermission(User $user, Company $company, string $permission): bool
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $permission === 'product.viewAny'
            ? app(ProductPolicy::class)->viewAny($user)
            : $user->hasPermissionTo($permission);
    }

    /**
     * Identify financial mutation or posting permissions that must be explicitly denied on V2.
     */
    public static function isFinancialPermission(string $permission): bool
    {
        $financialPrefixes = [
            'sales.',
            'purchasing.',
            'money.',
            'payroll.',
            'customer.payment.',
            'vendor.payment.',
            'inventory.stock.',
            'inventory.cost.',
            'inventory.operation.',
            'offline.',
            'financial.',
        ];

        foreach ($financialPrefixes as $prefix) {
            if (str_starts_with($permission, $prefix)) {
                return true;
            }
        }

        $financialKeywords = ['post', 'reverse', 'pay', 'void', 'allocate', 'adjust'];
        foreach ($financialKeywords as $keyword) {
            if (str_contains($permission, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
