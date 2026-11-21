<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\MobileDevice;
use App\Models\User;
use App\Support\Mobile\Receipt\NativeCommandReceiptManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ReceiptStatusController extends Controller
{
    /**
     * GET /api/mobile/v1/companies/{companyId}/receipts/{idempotencyKey}/status
     * GET /api/mobile/v1/companies/{companyId}/receipts/{idempotencyKey}
     *
     * Scoped status check of previously submitted native economic commands.
     */
    public function show(
        Request $request,
        string $companyId,
        string $idempotencyKey,
        NativeCommandReceiptManager $receiptManager
    ): JsonResponse {
        /** @var Company|null $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User|null $user */
        $user = $request->attributes->get('mobile_user');
        /** @var MobileDevice|null $device */
        $device = $request->attributes->get('mobile_device');

        if ($company === null || $user === null || $device === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required or token expired.',
                status: 401
            );
        }

        try {
            $status = $receiptManager->lookup($company, $user, $device, $idempotencyKey);
        } catch (AuthorizationException $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: $e->getMessage(),
                status: 403
            );
        } catch (InvalidArgumentException $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: $e->getMessage(),
                status: 404
            );
        }

        $payload = array_merge($status, [
            'outcome' => 'replay',
            'response_snapshot' => $status['result'],
        ]);

        return response()->json($payload, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }
}
