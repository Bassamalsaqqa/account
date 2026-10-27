<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Models\PlatformOperatorGrant;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/** Server-side proof from Fortify's successful challenge, never from enrollment alone. */
final class PlatformMfaProof
{
    public const string KEY = 'platform_mfa_proof';

    private const string PENDING = 'platform_mfa_pending';

    public function challengeSucceeded(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        $request = request();
        $user = $event->user;
        if (! $request->hasSession()) {
            return;
        }

        $this->clear();
        $grant = PlatformOperatorGrant::query()->where('user_id', $user->id)->where('active', true)->first();
        if ($grant === null || $user->two_factor_secret === null || $user->two_factor_confirmed_at === null) {
            return;
        }

        $request->session()->put(self::PENDING, [
            'user_id' => (int) $user->id,
            'grant_revision' => (int) $grant->revision,
            'credentials' => $this->fingerprint($user),
            'at' => now()->timestamp,
        ]);
    }

    public function loggedIn(Login $event): void
    {
        $request = request();
        if (! $request->hasSession() || $event->guard !== 'web') {
            return;
        }

        $pending = $request->session()->pull(self::PENDING);
        $request->session()->forget(self::KEY);
        if (! $event->user instanceof User || ! is_array($pending)
            || ($pending['user_id'] ?? null) !== (int) $event->user->id
            || ! is_int($pending['at'] ?? null)
            || $pending['at'] > now()->timestamp
            || $pending['at'] < now()->subSeconds(5)->timestamp
            || ! hash_equals($this->fingerprint($event->user), (string) ($pending['credentials'] ?? ''))) {
            return;
        }

        // Login and Fortify regenerate session IDs, retaining this private session data.
        $request->session()->put(self::KEY, $pending);
    }

    public function loggedOut(Logout $event): void
    {
        if ($event->guard === 'web') {
            $this->clear();
        }
    }

    public function valid(Request $request, User $user, PlatformOperatorGrant $grant): bool
    {
        if (! $request->hasSession() || $user->two_factor_secret === null || $user->two_factor_confirmed_at === null) {
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

    private function clear(): void
    {
        if (request()->hasSession()) {
            request()->session()->forget([self::KEY, self::PENDING]);
        }
    }

    private function fingerprint(User $user): string
    {
        return hash_hmac('sha256', json_encode([
            $user->getAuthPassword(), $user->two_factor_secret,
            $user->two_factor_confirmed_at, $user->two_factor_recovery_codes,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
