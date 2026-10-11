<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SessionApiController extends ApiController
{
    /**
     * GET /api/v1/session
     * Inspect details of the current authenticated session.
     */
    public function show(Request $request): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);
        /** @var User $actor */
        $actor = auth()->user();

        $context = app(CompanyContext::class);
        $company = $context->hasCompany() ? $context->company() : null;

        $session = $request->session();
        $startedAt = $session->get('phase11_session_started_at');

        return response()->json([
            'user' => [
                'id' => $actor->public_id,
                'name' => $actor->name,
                'email' => $actor->email,
            ],
            'company' => $company !== null ? [
                'id' => $company->id,
                'public_id' => $company->public_id,
                'name_ar' => $company->name_ar,
                'name_en' => $company->name_en,
                'base_currency_code' => $company->base_currency_code,
            ] : null,
            'session' => [
                'driver' => (string) config('session.driver'),
                'idle_timeout_seconds' => ((int) config('session.lifetime', 120)) * 60,
                'max_age_seconds' => (int) config('commands.session_max_age_seconds', 28800),
                'started_at' => $startedAt !== null ? (int) $startedAt : null,
            ],
        ], 200, $this->securityHeaders($traceId));
    }

    /**
     * GET /api/v1/sessions
     * Safe listing of active sessions belonging strictly to the current actor.
     */
    public function index(Request $request): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);

        // Capability refusal: database session driver required
        if (config('session.driver') !== 'database') {
            return $this->problem(
                title: 'Service Unavailable',
                status: 503,
                detail: 'Device session enumeration requires the database session driver.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.6.4'
            );
        }

        // Same database connection requirement
        $sessionConnName = config('session.connection') ?? DB::getDefaultConnection();
        if ($sessionConnName !== DB::getDefaultConnection() || DB::connection(config('session.connection')) !== DB::connection()) {
            return $this->problem(
                title: 'Service Unavailable',
                status: 503,
                detail: 'Session database connection must match default connection for session management.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.6.4'
            );
        }

        // Require fresh password confirmation
        if (! $this->isPasswordConfirmed($request)) {
            return $this->problem(
                title: 'Password Confirmation Required',
                status: 423,
                detail: 'Fresh password confirmation is required for session management.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.3'
            );
        }

        /** @var User $actor */
        $actor = auth()->user();
        $currentSessionId = $request->session()->getId();

        $table = (string) config('session.table', 'sessions');

        $records = DB::connection($sessionConnName)->table($table)
            ->where('user_id', $actor->id)
            ->orderByDesc('last_activity')
            ->get();

        $sessions = $records->map(function ($record) use ($actor, $currentSessionId) {
            $handle = $this->generateOpaqueHandle((string) $record->id, (int) $actor->id);

            return [
                'handle' => $handle,
                'is_current' => ($record->id === $currentSessionId),
                'last_activity' => (int) $record->last_activity,
                'user_agent' => $record->user_agent,
            ];
        })->values()->all();

        return response()->json([
            'sessions' => $sessions,
        ], 200, $this->securityHeaders($traceId));
    }

    /**
     * DELETE /api/v1/sessions/{opaqueHandle}
     * Revoke a specific session using an opaque HMAC handle, locking the target row before deletion.
     */
    public function destroy(Request $request, string $opaqueHandle): JsonResponse
    {
        $traceId = $this->resolveTraceId($request);

        // Capability refusal: database session driver required
        if (config('session.driver') !== 'database') {
            return $this->problem(
                title: 'Service Unavailable',
                status: 503,
                detail: 'Device session revocation requires the database session driver.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.6.4'
            );
        }

        // Same database connection requirement (fail closed on separate connection)
        $sessionConnName = config('session.connection') ?? DB::getDefaultConnection();
        if ($sessionConnName !== DB::getDefaultConnection() || DB::connection(config('session.connection')) !== DB::connection()) {
            return $this->problem(
                title: 'Service Unavailable',
                status: 503,
                detail: 'Session database connection must match default connection for atomic deletion.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.6.4'
            );
        }

        // Require fresh password confirmation
        if (! $this->isPasswordConfirmed($request)) {
            return $this->problem(
                title: 'Password Confirmation Required',
                status: 423,
                detail: 'Fresh password confirmation is required for session revocation.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.3'
            );
        }

        /** @var User $actor */
        $actor = auth()->user();

        $table = (string) config('session.table', 'sessions');

        // Enumerate ONLY current actor sessions from database
        $records = DB::connection($sessionConnName)->table($table)
            ->where('user_id', $actor->id)
            ->get();

        $targetRecord = null;
        foreach ($records as $record) {
            $expectedHandle = $this->generateOpaqueHandle((string) $record->id, (int) $actor->id);
            if (hash_equals($expectedHandle, $opaqueHandle)) {
                $targetRecord = $record;
                break;
            }
        }

        if ($targetRecord === null) {
            return $this->problem(
                title: 'Not Found',
                status: 404,
                detail: 'The requested session was not found or belongs to another user.',
                traceId: $traceId,
                type: 'https://tools.ietf.org/html/rfc7231#section-6.5.4'
            );
        }

        $isCurrent = ($targetRecord->id === $request->session()->getId());

        // Lock target owned row in DB transaction before delete; no Company locks or permission changes
        DB::transaction(function () use ($sessionConnName, $table, $targetRecord) {
            DB::connection($sessionConnName)->table($table)
                ->where('id', $targetRecord->id)
                ->lockForUpdate()
                ->first();

            DB::connection($sessionConnName)->table($table)
                ->where('id', $targetRecord->id)
                ->delete();
        });

        if ($isCurrent) {
            $request->session()->invalidate();
            auth()->logout();
        }

        return response()->json([
            'message' => $isCurrent ? 'Current session revoked and logged out.' : 'Session revoked successfully.',
            'revoked_current' => $isCurrent,
        ], 200, $this->securityHeaders($traceId));
    }

    /**
     * Verify whether password was freshly confirmed within the configured window.
     */
    private function isPasswordConfirmed(Request $request): bool
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');
        if (! is_int($confirmedAt)) {
            return false;
        }

        $timeout = (int) config('auth.password_timeout', 10800);

        $now = now()->timestamp;

        return $confirmedAt <= $now && ($now - $confirmedAt) <= $timeout;
    }

    /**
     * Generate an HMAC opaque handle binding user ID and session ID to prevent enumeration.
     */
    private function generateOpaqueHandle(string $sessionId, int $userId): string
    {
        $key = (string) config('app.key').':session:'.$userId;

        return hash_hmac('sha256', $sessionId, $key);
    }
}
