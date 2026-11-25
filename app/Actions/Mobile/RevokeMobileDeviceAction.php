<?php

declare(strict_types=1);

namespace App\Actions\Mobile;

use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;

final class RevokeMobileDeviceAction
{
    public function execute(MobileAccessToken $authorization, string $targetDeviceId, string $reason): ?MobileDevice
    {
        return DB::transaction(function () use ($authorization, $targetDeviceId, $reason): ?MobileDevice {
            // Login and financial admission take the principal before device and token.
            $user = User::query()->whereKey($authorization->user_id)->lockForUpdate()->first();
            if ($user === null) {
                throw new AuthenticationException;
            }

            $devices = MobileDevice::query()->where('user_id', $user->id)
                ->whereIn('device_id', [$authorization->device_id, $targetDeviceId])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('device_id');
            $requestDevice = $devices->get($authorization->device_id);
            $token = MobileAccessToken::query()->whereKey($authorization->id)
                ->where('token_hash', $authorization->token_hash)->lockForUpdate()->first();
            if ($requestDevice === null || $requestDevice->isRevoked() || $token === null || ! $token->isValid()
                || (int) $token->user_id !== (int) $user->id || $token->device_id !== $requestDevice->device_id
                || ! hash_equals($user->mobilePasswordEpoch(), $token->password_epoch)) {
                throw new AuthenticationException;
            }

            $device = $devices->get($targetDeviceId);
            if ($device === null) {
                return null;
            }

            $revokedAt = $device->revoked_at ?? now();
            if (! $device->isRevoked()) {
                $device->update([
                    'revoked_at' => $revokedAt,
                    'revoked_reason' => $reason,
                    'revoked_by_user_id' => $user->id,
                ]);
            }
            $device->tokens()->whereNull('revoked_at')->update(['revoked_at' => $revokedAt]);

            return $device;
        }, attempts: 3);
    }
}
