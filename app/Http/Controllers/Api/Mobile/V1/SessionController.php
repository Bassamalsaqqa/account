<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Money\CurrencyMinorUnitsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SessionController extends Controller
{
    /**
     * GET /api/mobile/v1/session
     * Return currently authenticated user session, role, device, fresh permissions, and company info.
     */
    public function current(Request $request, CurrencyMinorUnitsResolver $minorUnitsResolver): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('mobile_user');
        /** @var MobileDevice|null $device */
        $device = $request->attributes->get('mobile_device');
        /** @var Company|null $company */
        $company = $request->attributes->get('mobile_company');

        if ($user === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required.',
                status: 401
            );
        }

        if ($company !== null) {
            setPermissionsTeamId($company->id);
        }

        $roles = $user->roles()->pluck('name')->all();
        $permissions = $user->getAllPermissions()->pluck('name')->all();

        return response()->json([
            'user' => [
                'id' => (string) $user->public_id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'roles' => $roles,
                'permissions' => $permissions,
            ],
            'device' => $device ? [
                'device_id' => (string) $device->device_id,
                'device_name' => (string) $device->device_name,
                'platform' => (string) ($device->platform ?? 'android'),
            ] : null,
            'active_company_id' => $company ? (string) $company->public_id : null,
            'active_company' => $company ? [
                'id' => (string) $company->public_id,
                'name' => (string) $company->displayName(),
                'base_currency' => (string) $company->base_currency_code,
                'base_currency_minor_units' => $minorUnitsResolver->resolve((string) $company->base_currency_code, $company),
            ] : null,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * GET /api/mobile/v1/sessions
     * List all registered devices for the current user.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('mobile_user');
        /** @var MobileDevice|null $currentDevice */
        $currentDevice = $request->attributes->get('mobile_device');

        if ($user === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required.',
                status: 401
            );
        }

        $devices = MobileDevice::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->orderBy('id', 'desc')
            ->get()
            ->map(fn (MobileDevice $d) => [
                'device_id' => (string) $d->device_id,
                'device_name' => (string) $d->device_name,
                'platform' => (string) ($d->platform ?? 'android'),
                'app_version' => $d->app_version,
                'is_current' => $currentDevice && (string) $currentDevice->device_id === (string) $d->device_id,
                'created_at' => $d->created_at?->format('Y-m-d\TH:i:s\Z'),
            ])
            ->values()
            ->all();

        return response()->json([
            'devices' => $devices,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * DELETE /api/mobile/v1/sessions/{deviceId}
     * Revoke specific device session.
     */
    public function destroy(Request $request, string $deviceId): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('mobile_user');

        if ($user === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required.',
                status: 401
            );
        }

        $device = MobileDevice::where('device_id', $deviceId)
            ->where('user_id', $user->id)
            ->first();

        if ($device === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested device session was not found.',
                status: 404
            );
        }

        $device->update([
            'revoked_at' => now(),
            'revoked_reason' => 'Revoked by user via session management',
            'revoked_by_user_id' => $user->id,
        ]);

        MobileAccessToken::where('device_id', $device->device_id)
            ->update(['revoked_at' => now()]);

        return response()->json([
            'revoked' => true,
            'device_id' => $deviceId,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }
}
