<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;

final class CommandSessionGuard
{
    /**
     * Authorize that the active command runs under a verified, locked database session.
     * Must be invoked within an active DB transaction after Company/SalesActorGuard locks.
     * Requires the session row and user password verification to run on the exact same DB connection.
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public function authorize(User $actor): void
    {
        // 1. Transaction level boundary: DB transaction required
        if (DB::transactionLevel() === 0) {
            throw new AuthenticationException('A database transaction is required to linearize command session locks.');
        }

        // 2. Same Database Connection Object Requirement
        $sessionConn = DB::connection(config('session.connection'));
        $transactionConn = DB::connection();
        if ($sessionConn !== $transactionConn || (config('session.connection') !== null && config('session.connection') !== DB::getDefaultConnection())) {
            throw new AuthenticationException('Session database connection must match the canonical transaction connection.');
        }

        // 3. Proof check: ONLY from internal request attribute, NEVER from payload
        $proof = request()->attributes->get('phase11_api_session');
        if (! is_array($proof) || ($proof['authentication_origin'] ?? null) !== 'api_v1_session') {
            throw new AuthenticationException('API session verification proof is missing or invalid.');
        }

        if ((int) ($proof['actor_id'] ?? 0) !== (int) $actor->id) {
            throw new AuthorizationException('API session proof actor mismatch.');
        }

        $sessionId = (string) ($proof['session_id'] ?? '');
        if ($sessionId === '') {
            throw new AuthenticationException('API session identifier is missing.');
        }

        $table = (string) config('session.table', 'sessions');

        // 4. Verify + lock actual owned DB session row FOR UPDATE on active connection
        $sessionRow = $transactionConn->table($table)
            ->where('id', $sessionId)
            ->where('user_id', $actor->id)
            ->lockForUpdate()
            ->first();

        if ($sessionRow === null) {
            throw new AuthenticationException('Session has been revoked or expired.');
        }

        // 5. Verify login marker age and session last activity
        $marker = session('phase11_session_started_at');
        $now = now()->timestamp;
        $maxAge = (int) config('commands.session_max_age_seconds', 28800);

        if ($marker === null || ! is_int($marker) || $marker > $now || ($now - $marker) > $maxAge) {
            throw new AuthenticationException('Session maximum age exceeded or invalid login marker.');
        }

        $idleTimeout = ((int) config('session.lifetime', 120)) * 60;
        if (($now - (int) $sessionRow->last_activity) > $idleTimeout) {
            throw new AuthenticationException('Session idle timeout exceeded.');
        }

        // 6. Freshly query user password from database vs authenticated actor, locking users row FOR UPDATE
        $freshUser = $transactionConn->table('users')
            ->where('id', $actor->id)
            ->lockForUpdate()
            ->first(['password']);

        if ($freshUser === null || ! hash_equals((string) $freshUser->password, (string) $actor->getAuthPassword())) {
            throw new AuthenticationException('User credentials have changed since session establishment.');
        }
    }
}
