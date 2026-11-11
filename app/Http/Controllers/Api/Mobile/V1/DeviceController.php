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
use Illuminate\Support\Facades\DB;

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

        // Only the durable owner may revoke a registered device. Unknown/foreign UUIDs
        // are indistinguishable; no company-free administrative revocation capability.
        $device = DB::transaction(function () use ($deviceId, $user, $reason) {
            $device = MobileDevice::where('device_id', strtolower($deviceId))
                ->where('user_id', $user->id)->lockForUpdate()->first();
            if ($device !== null) {
                $device->revoke($reason, $user);
            }

            return $device;
        });
        if ($device === null) {
            return MobileApiBoundary::buildErrorResponse(code: 'FORBIDDEN', message: 'Unauthorized to revoke device.', status: 403);
        }

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
