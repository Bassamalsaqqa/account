<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class Phase11ApiGuard
{
    private const DEFAULT_SESSION_MAX_AGE_SECONDS = 28800; // 8 hours

    public function handle(Request $request, Closure $next): Response
    {
        $traceId = (string) ($request->attributes->get('trace_id') ?? '');

        // 1. Database Session Driver Enforcement for Verifiable Revocation
        if (config('session.driver') !== 'database') {
            return $this->problem(
                title: 'Service Unavailable',
                status: 503,
                detail: 'The API requires the database session driver for verifiable revocation.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.6.4'
            );
        }

        // 2. Same Database Connection Object Requirement (Fail Closed on Separate Connection)
        $sessionConnName = config('session.connection') ?? DB::getDefaultConnection();
        if ($sessionConnName !== DB::getDefaultConnection() || DB::connection(config('session.connection')) !== DB::connection()) {
            return $this->problem(
                title: 'Service Unavailable',
                status: 503,
                detail: 'The session database connection must match the canonical database connection.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.6.4'
            );
        }

        // 3. Authentication Check
        if (! auth()->check()) {
            return $this->problem(
                title: 'Unauthorized',
                status: 401,
                detail: 'Authentication is required to access this endpoint.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7235#section-3.1'
            );
        }

        // 4. Prohibit viaRemember (remembered cookies are never accepted as active API sessions)
        if (auth()->viaRemember()) {
            $request->session()->invalidate();
            auth()->logout();

            return $this->problem(
                title: 'Unauthorized',
                status: 401,
                detail: 'Remembered credentials cannot be utilized for API access; fresh login required.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7235#section-3.1'
            );
        }

        /** @var User $actor */
        $actor = auth()->user();
        $session = $request->session();
        $sessionId = (string) $session->getId();
        $now = now()->timestamp;

        // 5. Login Marker Age Verification (Missing, Future, or Expired Marker Refused)
        $marker = $session->get('phase11_session_started_at');
        $maxAge = (int) config('commands.session_max_age_seconds', self::DEFAULT_SESSION_MAX_AGE_SECONDS);

        if ($marker === null || ! is_int($marker) || $marker > $now || ($now - $marker) > $maxAge) {
            $session->invalidate();
            auth()->logout();

            return $this->problem(
                title: 'Unauthorized',
                status: 401,
                detail: 'Session marker missing, future, or maximum lifetime exceeded. Reauthentication required.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7235#section-3.1'
            );
        }

        // 6. Database Session Record Existence and Idle Timeout Verification
        $table = (string) config('session.table', 'sessions');

        $sessionRow = DB::connection($sessionConnName)->table($table)
            ->where('id', $sessionId)
            ->where('user_id', $actor->id)
            ->first();

        if ($sessionRow === null) {
            $session->invalidate();
            auth()->logout();

            return $this->problem(
                title: 'Unauthorized',
                status: 401,
                detail: 'Session has been revoked or expired.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7235#section-3.1'
            );
        }

        $idleTimeout = ((int) config('session.lifetime', 120)) * 60;
        if (($now - (int) $sessionRow->last_activity) > $idleTimeout) {
            $session->invalidate();
            auth()->logout();

            return $this->problem(
                title: 'Unauthorized',
                status: 401,
                detail: 'Session idle timeout exceeded.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7235#section-3.1'
            );
        }

        // 7. Tenancy & Route Company Context Validation
        $routeCompanyId = $request->route('companyId');
        $context = app(CompanyContext::class);

        if ($routeCompanyId !== null) {
            // Must match EXPLICIT selected session active_company_id, no default fallback
            $sessionCompanyId = session('active_company_id');
            if ($sessionCompanyId === null) {
                return $this->problem(
                    title: 'Forbidden',
                    status: 403,
                    detail: 'No active company explicitly selected in session.',
                    traceId: $traceId,
                    type: 'https://tools.ietf.org/html/rfc7231#section-6.5.3'
                );
            }

            if (! $context->hasCompany() || (string) $context->companyId() !== (string) $sessionCompanyId) {
                return $this->problem(
                    title: 'Forbidden',
                    status: 403,
                    detail: 'Session company context mismatch.',
                    traceId: $traceId,
                    type: 'https://tools.ietf.org/html/rfc7231#section-6.5.3'
                );
            }

            $activeCompany = $context->company();
            if ($activeCompany->status !== 'active') {
                return $this->problem(
                    title: 'Forbidden',
                    status: 403,
                    detail: 'The active company is not active.',
                    traceId: $traceId,
                    type: 'https://tools.ietf.org/html/rfc7231#section-6.5.3'
                );
            }

            // Invariant: NEVER change active company based on route or payload
            $matches = ((string) $activeCompany->id === (string) $routeCompanyId)
                || ((string) $activeCompany->public_id === (string) $routeCompanyId);

            if (! $matches) {
                return $this->problem(
                    title: 'Forbidden',
                    status: 403,
                    detail: 'Route company does not match session-selected company context.',
                    traceId: $traceId,
                    type: 'https://tools.ietf.org/html/rfc7231#section-6.5.3'
                );
            }

            // Verify active membership in active company (CompanyUser.status is authoritative; User model has no status column)
            $membership = CompanyUser::where('company_id', $activeCompany->id)
                ->where('user_id', $actor->id)
                ->first();

            if ($membership === null || $membership->status !== 'active') {
                return $this->problem(
                    title: 'Forbidden',
                    status: 403,
                    detail: 'User does not possess an active membership in the selected company.',
                    traceId: $traceId,
                    type: 'https://tools.ietf.org/html/rfc7231#section-6.5.3'
                );
            }
        }

        // 8. Authenticated Actor + Company Rate Limiting (default 60/min)
        $companyPart = $context->hasCompany() ? (string) $context->companyId() : 'none';
        $actorKey = "phase11:auth_actor:{$actor->id}:comp:{$companyPart}";
        $actorMax = (int) config('commands.rate_limits.authenticated_per_minute', 60);

        if (RateLimiter::tooManyAttempts($actorKey, $actorMax)) {
            $retryAfter = RateLimiter::availableIn($actorKey);

            return $this->problem(
                title: 'Too Many Requests',
                status: 429,
                detail: "Rate limit exceeded. Please retry after {$retryAfter} seconds.",
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc6585#section-4',
                extraHeaders: ['Retry-After' => (string) $retryAfter]
            );
        }

        RateLimiter::hit($actorKey, 60);

        // 9. Attach Trusted Internal Verification Proof for CommandSessionGuard
        $request->attributes->set('phase11_api_session', [
            'session_id' => $sessionId,
            'actor_id' => $actor->id,
            'authentication_origin' => 'api_v1_session',
            'verified_at' => $now,
        ]);

        return $next($request);
    }

    /**
     * Build RFC 7807 compliant problem JSON response.
     *
     * @param  array<string, string>  $extraHeaders
     */
    private function problem(
        string $title,
        int $status,
        string $detail,
        string $traceId,
        string $type = 'about:blank',
        array $extraHeaders = []
    ): JsonResponse {
        $headers = array_merge([
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Trace-Id' => $traceId,
        ], $extraHeaders);

        return response()->json([
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'trace_id' => $traceId,
        ], $status, $headers);
    }
}
