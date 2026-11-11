<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DeviceController extends Controller
{
    /**
     * POST /api/mobile/v1/devices/{deviceId}/revoke
     */
    public function revoke(Request $request, string $deviceId): JsonResponse
    {
        // 1. Validate deviceId route parameter
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $deviceId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'The deviceId must be a valid UUID.',
                status: 422
            );
        }

        // 2. Validate request body (reason: 3..255 characters, additionalProperties: false)
        $raw = (string) $request->getContent();
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'INVALID_JSON',
                message: 'Invalid JSON payload format.',
                status: 422
            );
        }

        if (array_keys($data) !== ['reason'] || ! is_string($data['reason'])) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'The request must contain only the [reason] field.',
                status: 422
            );
        }

        $reason = trim($data['reason']);
        if (strlen($reason) < 3 || strlen($reason) > 255) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'The reason must be between 3 and 255 characters.',
                status: 422
            );
        }

        /** @var MobileAccessToken $token */
        $token = $request->attributes->get('mobile_token');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        // 3. Authorization check
        // An actor can revoke their own device. Revoking another device requires manager or admin role.
        if (strtolower($token->device_id) !== strtolower($deviceId)) {
            $isPrivileged = $user->hasRole('Owner') || $user->hasRole('Administrator') || $user->hasRole('Admin') || $user->hasRole('Manager')
                || $user->hasPermissionTo('settings.company.manage');

            if (! $isPrivileged) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'FORBIDDEN',
                    message: 'Unauthorized to revoke device.',
                    status: 403
                );
            }
        }

        // 4. Find or create device record and revoke
        /** @var MobileDevice $device */
        $device = MobileDevice::firstOrCreate(
            ['device_id' => $deviceId],
            ['device_name' => 'Device '.$deviceId]
        );

        $device->revoke($reason, $user);

        return response()->json([
            'device_id' => $deviceId,
            'status' => 'revoked',
            'revoked_at' => $device->revoked_at?->format('Y-m-d\TH:i:s\Z') ?? now()->format('Y-m-d\TH:i:s\Z'),
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
