<?php

declare(strict_types=1);

namespace App\Support\Mobile;

use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;

/**
 * Enforces canonical deadlock-free lock order and credential/permission admission:
 * Company -> Membership -> User -> MobileDevice -> MobileAccessToken.
 */
final class NativeAdmissionGuard
{
    /**
     * @param  list<string>  $requiredPermissions
     * @return array{company: Company, user: User, device: MobileDevice, token: MobileAccessToken}
     */
    public function lockAndAuthorize(
        int $companyId,
        User $actor,
        ?MobileAccessToken $token,
        ?MobileDevice $device,
        array $requiredPermissions = []
    ): array {
        if ($token === null) {
            throw new AuthenticationException('Native bearer token is mandatory for mobile transactional operations.');
        }

        if ($device === null) {
            throw new AuthenticationException('Native mobile device is mandatory for mobile transactional operations.');
        }

        // 1. Lock company (tenant root)
        /** @var Company|null $lockedCompany */
        $lockedCompany = Company::whereKey($companyId)->lockForUpdate()->first();
        if ($lockedCompany === null || $lockedCompany->status !== 'active') {
            throw new AuthorizationException('Target company not found or inactive.');
        }

        // 2. Lock membership
        $membership = DB::table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if ($membership === null) {
            throw new AuthorizationException('Actor is not an active member of this company.');
        }

        // 3. Lock principal (user)
        /** @var User|null $lockedUser */
        $lockedUser = User::whereKey($actor->id)->lockForUpdate()->first();
        if ($lockedUser === null) {
            throw new AuthenticationException('User not found.');
        }

        // 4. Lock device
        /** @var MobileDevice|null $lockedDevice */
        $lockedDevice = MobileDevice::where('device_id', $device->device_id)->lockForUpdate()->first();
        if ($lockedDevice === null || $lockedDevice->isRevoked() || (int) $lockedDevice->user_id !== (int) $lockedUser->id) {
            throw new AuthenticationException('Device has been revoked or does not match user.');
        }

        // 5. Lock bearer token and revalidate credentials
        /** @var MobileAccessToken|null $lockedToken */
        $lockedToken = MobileAccessToken::where('id', $token->id)->lockForUpdate()->first();
        if ($lockedToken === null || ! $lockedToken->isValid()) {
            throw new AuthenticationException('The Bearer token has been revoked or expired.');
        }

        if ((int) $lockedToken->user_id !== (int) $lockedUser->id) {
            throw new AuthenticationException('Bearer token owner does not match authenticated user.');
        }

        if ($lockedToken->device_id !== $lockedDevice->device_id) {
            throw new AuthenticationException('Bearer token is bound to a different mobile device.');
        }

        if ($lockedToken->company_id !== null && (int) $lockedToken->company_id !== (int) $companyId) {
            throw new AuthorizationException('Token is bound to a different company.');
        }

        $expectedEpoch = 'epoch-'.substr(hash('sha256', (string) $lockedUser->password), 0, 16);
        if (! hash_equals($expectedEpoch, (string) $lockedToken->password_epoch)) {
            $lockedToken->update(['revoked_at' => now()]);
            throw new AuthenticationException('User credentials have changed since token establishment.');
        }

        // Revalidate permissions under lock in team context
        setPermissionsTeamId($companyId);
        $lockedUser->unsetRelation('roles')->unsetRelation('permissions');

        foreach ($requiredPermissions as $perm) {
            if (! $lockedUser->hasPermissionTo($perm)) {
                throw new AuthorizationException("Action is unauthorized. Missing required permission [{$perm}].");
            }
        }

        return [
            'company' => $lockedCompany,
            'user' => $lockedUser,
            'device' => $lockedDevice,
            'token' => $lockedToken,
        ];
    }
}
