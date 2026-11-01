<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Models\PlatformOperatorGrant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PlatformAccess
{
    public const array PERMISSIONS = ['company.read', 'company.provision', 'audit.read', 'subscription.manage', 'company.support', 'company.impersonate'];

    /** Re-read grant and identity on every action; callers lock grants inside write transactions. */
    public function authorize(string $permission, bool $lock = false): PlatformOperatorGrant
    {
        $request = request();
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }

        return $this->authorizeOperator($request, $actor, $permission, $lock);
    }

    /** The explicit operator remains bound to the real authenticated session proof. */
    public function authorizeOperator(Request $request, User $actor, string $permission, bool $lock = false): PlatformOperatorGrant
    {
        if (! in_array($permission, self::PERMISSIONS, true)
            || ($lock && DB::transactionLevel() < 1)) {
            throw new AuthorizationException;
        }

        $query = PlatformOperatorGrant::query()->where('user_id', $actor->id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $grant = $query->first();
        $identity = User::query()->whereKey($actor->id);
        if ($lock) {
            $identity->lockForUpdate();
        }
        $fresh = $identity->first();
        if ($grant === null || ! $grant->active || $fresh === null
            || ! in_array($permission, $grant->permissions ?? [], true)) {
            throw new AuthorizationException;
        }

        if (! app(PlatformSessionProof::class)->valid($request, $fresh, $grant)) {
            throw new PlatformReauthenticationRequired;
        }

        return $grant;
    }
}
