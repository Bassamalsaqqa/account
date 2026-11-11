<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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

final class MobileAuthenticate
{
    /**
     * Handle authentication and scope verification for Mobile V1 requests.
     */
    public function handle(Request $request, Closure $next, ?string $requiredPermission = null): Response
    {
        $context = app(CompanyContext::class);
        $context->clear();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            return $this->handleAuthenticated($request, $next, $requiredPermission);
        } finally {
            $context->clear();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function handleAuthenticated(Request $request, Closure $next, ?string $requiredPermission = null): Response
    {
        // 1. Extract Bearer token from Authorization header
        $authHeader = (string) $request->header('Authorization');
        if (! str_starts_with($authHeader, 'Bearer ')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required or token expired.',
                status: 401
            );
        }

        $rawToken = trim(substr($authHeader, 7));
        if ($rawToken === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required or token expired.',
                status: 401
            );
        }

        // 2. Hash and look up token
        $tokenHash = hash('sha256', $rawToken);
        /** @var MobileAccessToken|null $token */
        $token = MobileAccessToken::where('token_hash', $tokenHash)->first();

        if ($token === null || ! $token->isValid()) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'The Bearer token has been revoked or expired.',
                status: 401,
                details: ['reason' => 'TOKEN_INVALID_OR_EXPIRED']
            );
        }

        // 3. Verify device status
        /** @var MobileDevice|null $device */
        $device = MobileDevice::where('device_id', $token->device_id)->first();
        if ($device === null || $device->isRevoked() || (int) $device->user_id !== (int) $token->user_id) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'The Bearer token has been revoked or expired.',
                status: 401,
                details: [
                    'reason' => 'ACTOR_REVOKED',
                    'revoked_at' => $device?->revoked_at?->format('Y-m-d\TH:i:s\Z') ?? now()->format('Y-m-d\TH:i:s\Z'),
                ]
            );
        }

        // 4. Verify User and password revision epoch
        /** @var User|null $user */
        $user = User::find($token->user_id);
        if ($user === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'The Bearer token has been revoked or expired.',
                status: 401,
                details: ['reason' => 'USER_NOT_FOUND']
            );
        }

        $currentEpoch = 'epoch-'.substr(hash('sha256', (string) $user->password), 0, 16);
        if (! hash_equals($currentEpoch, $token->password_epoch)) {
            $token->update(['revoked_at' => now()]);

            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'The Bearer token has been revoked or expired.',
                status: 401,
                details: ['reason' => 'PASSWORD_REVISED']
            );
        }

        // 5. Scope-Before-Return: tenant and company context verification
        $companyId = $request->route('companyId');
        if ($companyId !== null && is_string($companyId)) {
            /** @var Company|null $company */
            $company = Company::where('public_id', $companyId)->first();

            if ($company === null || $company->status !== 'active') {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'FORBIDDEN_TENANT_MISMATCH',
                    message: 'User does not possess active authorized membership in the requested company context.',
                    status: 403,
                    details: ['requested_company_id' => $companyId]
                );
            }

            // Verify active membership (company selection cannot bypass membership)
            $isMember = $user->activeCompanies()
                ->where('companies.id', $company->id)
                ->exists();

            if (! $isMember) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'FORBIDDEN_TENANT_MISMATCH',
                    message: 'User does not possess active authorized membership in the requested company context.',
                    status: 403,
                    details: ['requested_company_id' => $companyId]
                );
            }

            // Sessionless CompanyContext activation
            app(CompanyContext::class)->setCompany($company, $user);

            // Revalidate required permission server-side
            if ($requiredPermission !== null) {
                if (! $this->userHasPermission($user, $company, $requiredPermission)) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'FORBIDDEN',
                        message: "Action is unauthorized. Missing required permission [{$requiredPermission}].",
                        status: 403
                    );
                }
            }

            $request->attributes->set('mobile_company', $company);
        }

        $key = 'mobile:actor:'.$user->id.':company:'.($companyId ?? 'none');
        if (RateLimiter::tooManyAttempts($key, (int) config('mobile.rate_limits.authenticated_per_minute', 120))) {
            return MobileApiBoundary::buildErrorResponse(code: 'TOO_MANY_REQUESTS', message: 'Rate limit exceeded.', status: 429);
        }
        RateLimiter::hit($key, 60);
        // 6. Update token last used timestamp
        $token->update(['last_used_at' => now()]);

        // 7. Attach verified context
        $request->attributes->set('mobile_user', $user);
        $request->attributes->set('mobile_token', $token);
        $request->attributes->set('mobile_device', $device);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    /**
     * Check if user has permission in company.
     */
    private function userHasPermission(User $user, Company $company, string $permission): bool
    {
        return $permission === 'product.viewAny'
            ? app(ProductPolicy::class)->viewAny($user)
            : $user->hasPermissionTo($permission);
    }
}
