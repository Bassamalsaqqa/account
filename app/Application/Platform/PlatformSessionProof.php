<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Models\PlatformOperatorGrant;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/** Password-authenticated platform sessions; tenant MFA remains optional and unchanged. */
final class PlatformSessionProof
{
    public const string KEY = 'platform_session_proof';

    public function loggedIn(Login $event): void
    {
        $request = request();
        if (! $request->hasSession() || $event->guard !== 'web') {
            return;
        }
        $request->session()->forget(self::KEY);
        if (! $event->user instanceof User || auth()->guard('web')->viaRemember()) {
            return;
        }
        $grant = PlatformOperatorGrant::query()->where('user_id', $event->user->id)->where('active', true)->first();
        if ($grant === null) {
            return;
        }
        $fresh = User::query()->find($event->user->id);
        // A concurrent password change must not bind an old login to new credentials.
        if ($fresh !== null && hash_equals($fresh->getAuthPassword(), $event->user->getAuthPassword())) {
            $this->record($request->session(), $fresh, $grant);
        }
    }

    /** Used only by real password-confirmation handlers, never by access middleware. */
    public function confirmPassword(User $user, #[SensitiveParameter] ?string $password): bool
    {
        return DB::transaction(function () use ($user, $password): bool {
            // Same lock order as provisioning and host grant/revocation.
            $grant = PlatformOperatorGrant::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($fresh === null || $password === null || ! Hash::check($password, $fresh->getAuthPassword())) {
                return false;
            }
            session()->forget(self::KEY);
            if ($grant !== null && $grant->active) {
                session()->regenerate();
                $this->record(session()->driver(), $fresh, $grant);
            }

            return true;
        });
    }

    public function loggedOut(Logout $event): void
    {
        if ($event->guard === 'web' && request()->hasSession()) {
            request()->session()->forget(self::KEY);
        }
    }

    public function valid(Request $request, User $user, PlatformOperatorGrant $grant): bool
    {
        if (! $request->hasSession()) {
            return false;
        }
        $proof = $request->session()->get(self::KEY);

        return is_array($proof)
            && ($proof['user_id'] ?? null) === (int) $user->id
            && ($proof['grant_revision'] ?? null) === (int) $grant->revision
            && is_int($proof['at'] ?? null)
            && $proof['at'] <= now()->timestamp
            && $proof['at'] > now()->subHour()->timestamp
            && hash_equals($this->fingerprint($user), (string) ($proof['credentials'] ?? ''));
    }

    private function record(Session $session, User $user, PlatformOperatorGrant $grant): void
    {
        $session->put(self::KEY, [
            'user_id' => (int) $user->id,
            'grant_revision' => (int) $grant->revision,
            'credentials' => $this->fingerprint($user),
            'at' => now()->timestamp,
        ]);
    }

    private function fingerprint(User $user): string
    {
        return hash_hmac('sha256', json_encode([
            $user->getAuthPassword(), $user->two_factor_secret,
            $user->two_factor_confirmed_at, $user->two_factor_recovery_codes,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
