<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V2;

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Http\Middleware\MobileV2ApiBoundary;
use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Support\Mobile\Contract\Revision3\Exceptions\IntentProfileValidationException;
use App\Support\Mobile\Contract\Revision3\OnlineSaleWireResponseProjection;
use App\Support\Mobile\Offline\OfflineSaleConflict;
use App\Support\Mobile\Offline\OfflineSaleEvent;
use App\Support\Mobile\Offline\OfflineSaleLeaseService;
use App\Support\Mobile\Receipt\Exceptions\MobileEventDuplicatePayloadException;
use App\Support\Mobile\Receipt\MobileEventReceiptManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class OfflineSaleController
{
    public function authorizeLease(Request $request, string $companyId, MobileEventReceiptManager $manager): JsonResponse
    {
        if (! OfflineSaleLeaseService::enabled()) {
            return MobileV2ApiBoundary::buildErrorResponse('NOT_FOUND', status: 404);
        }

        $company = $request->attributes->get('mobile_company');
        $token = $request->attributes->get('mobile_token');
        if (! $company instanceof Company || ! $token instanceof MobileAccessToken) {
            return MobileV2ApiBoundary::buildErrorResponse('UNAUTHENTICATED', status: 401);
        }

        $body = json_decode($request->getContent(), true);
        if (! is_array($body) || array_keys($body) !== ['catalog_snapshot_version'] || ! is_string($body['catalog_snapshot_version'])) {
            return MobileV2ApiBoundary::buildErrorResponse('VALIDATION_FAILED', status: 422);
        }

        try {
            return response()->json($manager->issueOfflineLease((int) $company->id, $token->token_hash, $body['catalog_snapshot_version']));
        } catch (OfflineSaleConflict $e) {
            return $this->conflict($e->reason, $e->getMessage());
        } catch (InvalidArgumentException) {
            return MobileV2ApiBoundary::buildErrorResponse('VALIDATION_FAILED', status: 422);
        }
    }

    public function store(Request $request, string $companyId, MobileEventReceiptManager $manager): JsonResponse
    {
        if (! OfflineSaleLeaseService::enabled()) {
            return MobileV2ApiBoundary::buildErrorResponse('NOT_FOUND', status: 404);
        }

        $company = $request->attributes->get('mobile_company');
        $token = $request->attributes->get('mobile_token');
        if (! $company instanceof Company || ! $token instanceof MobileAccessToken) {
            return MobileV2ApiBoundary::buildErrorResponse('UNAUTHENTICATED', status: 401);
        }

        try {
            $sale = OfflineSaleEvent::fromWire($request->getContent());
            $clientEventId = (string) $sale->event['client_event_id'];
            $bytes = $sale->canonicalBytes();
        } catch (OfflineSaleConflict $e) {
            return $this->conflict($e->reason, $e->getMessage());
        } catch (IntentProfileValidationException|InvalidArgumentException) {
            return MobileV2ApiBoundary::buildErrorResponse('VALIDATION_FAILED', status: 422);
        }

        try {
            $result = $manager->recordOrReplay((int) $company->id, $clientEventId, $token->token_hash, 2, OfflineSaleEvent::REPRESENTATION, $bytes);
        } catch (OfflineSaleConflict $e) {
            return $this->conflict($e->reason, $e->getMessage());
        } catch (MobileEventDuplicatePayloadException) {
            return $this->conflict('EVENT_PAYLOAD_CHANGED', 'The saved event ID has already been used with a different payload.');
        } catch (InsufficientStockException|InvalidInventoryMovementException) {
            return $this->conflict('STOCK_UNAVAILABLE', 'Current stock cannot fulfill the saved sale.');
        } catch (InvalidArgumentException|ModelNotFoundException) {
            return $this->conflict('SALE_REQUIRES_REVIEW', 'The saved sale cannot be posted with the current setup.');
        }

        $receipt = OnlineSaleWireResponseProjection::projectCommitWireDto($clientEventId, $result['terminal_canonical_result'] ?? []);

        return response()->json([
            'client_event_id' => $clientEventId,
            'status' => $receipt['status'],
            'payload_digest' => 'sha256:'.hash('sha256', $bytes),
            'outcome' => [
                'action' => 'posted_canonically',
                'invoice_id' => $receipt['invoice_id'],
                'invoice_number' => $receipt['invoice_number'],
                'payment_id' => $receipt['payment_id'],
                'grand_total' => $receipt['grand_total'],
            ],
            'processed_at' => $receipt['posted_at'],
        ]);
    }

    private function conflict(string $reason, string $message): JsonResponse
    {
        return MobileV2ApiBoundary::buildErrorResponse('CONFLICT', status: 409, conflictEvidence: [
            'reason' => $reason,
            'resolution' => $message,
        ]);
    }
}
