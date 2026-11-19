<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Inventory\AdjustStockAction;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Mobile\Receipt\NativeCommandReceiptManager;
use App\Support\Mobile\Receipt\NativeWriteContextResolver;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

final class InventoryController extends Controller
{
    /**
     * GET /api/mobile/v1/companies/{companyId}/products/{productId}/movements
     */
    public function movements(Request $request, string $companyId, string $productId): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('inventory.stock.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required inventory.stock.view permission.',
                status: 403
            );
        }

        $product = Product::where('company_id', $company->id)
            ->where(function ($q) use ($productId): void {
                $q->where('public_id', $productId)
                    ->orWhere('id', $productId);
            })
            ->first();

        if ($product === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested product was not found.',
                status: 404
            );
        }

        $limit = min((int) ($request->query('limit', 50)), 100);
        $records = StockMovement::where('company_id', $company->id)
            ->where('product_id', $product->id)
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        $movements = $records->map(function (StockMovement $m): array {
            $delta = BigDecimal::of((string) $m->quantity_delta_base);

            return [
                'id' => (string) $m->public_id,
                'movement_type' => (string) $m->movement_type,
                'delta' => (string) $delta->toScale(4, RoundingMode::HALF_UP),
                'date' => $m->movement_date->format('Y-m-d'),
                'reason' => (string) ($m->notes ?? $m->movement_type),
                'resulting_stock' => (string) BigDecimal::of((string) ($m->quantity_after_product_company ?? $m->quantity_after_base ?? '0'))->toScale(4, RoundingMode::HALF_UP),
            ];
        })->values()->all();

        return response()->json([
            'product_id' => (string) $product->public_id,
            'movements' => $movements,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/inventory/adjustments
     * Audited stock adjustment (+/- delta) with reason and operator logging; blocks negative stock.
     */
    public function adjust(
        Request $request,
        string $companyId,
        AdjustStockAction $adjustStockAction,
        NativeCommandReceiptManager $receiptManager,
        NativeWriteContextResolver $contextResolver
    ): JsonResponse {
        $rawKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        $idempotencyKey = is_string($rawKey) ? trim($rawKey) : '';
        if ($idempotencyKey === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for this operation.',
                status: 422
            );
        }

        /** @var Company|null $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User|null $user */
        $user = $request->attributes->get('mobile_user');
        /** @var MobileAccessToken|null $token */
        $token = $request->attributes->get('mobile_token');
        /** @var MobileDevice|null $device */
        $device = $request->attributes->get('mobile_device');

        if ($company === null || $user === null || $token === null || $device === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required or token expired.',
                status: 401
            );
        }

        $productId = $request->input('product_id');
        if ($productId === null || ! is_string($productId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $productId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested product was not found.',
                status: 404
            );
        }

        $rawDelta = $request->input('delta');
        try {
            $deltaStr = NativeWriteContextResolver::validateDecimalString(
                $rawDelta,
                'delta',
                6,
                false
            );
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: $e->getMessage(),
                status: 422
            );
        }

        $deltaBc = BigDecimal::of($deltaStr);
        if ($deltaBc->isZero()) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Adjustment delta cannot be zero.',
                status: 422
            );
        }

        $rawReason = $request->input('reason');
        if (! is_string($rawReason) || trim($rawReason) === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Adjustment reason is required.',
                status: 422
            );
        }
        $reason = trim($rawReason);

        $warehousePublicId = $request->input('warehouse_id');
        if ($warehousePublicId !== null && (! is_string($warehousePublicId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $warehousePublicId) !== 1)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Warehouse ID must be a valid ULID.',
                status: 422
            );
        }

        $rawUnitCost = $request->input('unit_cost');
        $unitCost = null;
        if ($rawUnitCost !== null) {
            if (! $deltaBc->isPositive()) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Explicit unit cost can only be specified on adjustment increase.',
                    status: 422
                );
            }
            try {
                $unitCost = NativeWriteContextResolver::validateDecimalString(
                    $rawUnitCost,
                    'unit_cost',
                    6,
                    false
                );
                if (BigDecimal::of($unitCost)->isNegative()) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: 'Explicit unit cost cannot be negative.',
                        status: 422
                    );
                }
            } catch (Throwable $e) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: $e->getMessage(),
                    status: 422
                );
            }
        }

        $movementDate = null;
        if ($request->has('movement_date')) {
            try {
                $movementDate = NativeWriteContextResolver::validateDate($request->input('movement_date'), 'movement_date');
            } catch (Throwable $e) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: $e->getMessage(),
                    status: 422
                );
            }
        }

        $unitPublicId = $request->input('unit_id');
        if ($unitPublicId !== null && (! is_string($unitPublicId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $unitPublicId) !== 1)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Unit ID must be a valid ULID.',
                status: 422
            );
        }

        $lotPublicId = $request->input('lot_id');
        if ($lotPublicId !== null && (! is_string($lotPublicId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $lotPublicId) !== 1)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Lot ID must be a valid ULID.',
                status: 422
            );
        }
        $rawLotNumber = $request->input('lot_number');
        $lotNumber = is_string($rawLotNumber) && trim($rawLotNumber) !== '' ? trim($rawLotNumber) : null;
        $expiryDate = null;
        if ($request->has('expiry_date')) {
            try {
                $expiryDate = NativeWriteContextResolver::validateDate($request->input('expiry_date'), 'expiry_date');
            } catch (Throwable $e) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: $e->getMessage(),
                    status: 422
                );
            }
        }

        $canonicalPayload = [
            'actor_id' => (int) $user->id,
            'device_id' => (string) $device->device_id,
            'product_id' => $productId,
            'warehouse_id' => is_string($warehousePublicId) ? trim($warehousePublicId) : null,
            'delta' => (string) $deltaBc->toScale(6, RoundingMode::HALF_UP),
            'reason' => $reason,
            'unit_cost' => $unitCost !== null ? (string) BigDecimal::of($unitCost)->toScale(6, RoundingMode::HALF_UP) : null,
            'unit_id' => is_string($unitPublicId) ? trim($unitPublicId) : null,
            'movement_date' => $movementDate,
            'lot_id' => is_string($lotPublicId) ? trim($lotPublicId) : null,
            'lot_number' => $lotNumber,
            'expiry_date' => $expiryDate,
        ];

        $requiredPermissions = ['inventory.stock.adjust'];
        if ($unitCost !== null) {
            $requiredPermissions[] = 'inventory.cost.view';
        }

        try {
            $result = $receiptManager->execute(
                companyId: (int) $company->id,
                actor: $user,
                token: $token,
                device: $device,
                commandType: 'inventory.adjustment',
                idempotencyKey: $idempotencyKey,
                canonicalPayload: $canonicalPayload,
                requiredPermissions: $requiredPermissions,
                executor: function (Company $lockedCompany, User $lockedActor, MobileDevice $lockedDevice, MobileAccessToken $lockedToken) use (
                    $productId,
                    $deltaBc,
                    $reason,
                    $warehousePublicId,
                    $unitCost,
                    $unitPublicId,
                    $lotPublicId,
                    $lotNumber,
                    $expiryDate,
                    $movementDate,
                    $idempotencyKey,
                    $adjustStockAction,
                    $contextResolver
                ): array {
                    /** @var Product|null $product */
                    $product = Product::where('company_id', $lockedCompany->id)
                        ->where('public_id', $productId)
                        ->lockForUpdate()
                        ->first();

                    if ($product === null) {
                        throw new InvalidArgumentException('The requested product was not found.');
                    }

                    if (! $product->track_stock) {
                        throw new InvalidArgumentException('Non-stock and service items cannot have inventory adjustments.');
                    }

                    $warehouse = $contextResolver->resolveWarehouse(
                        $lockedCompany,
                        is_string($warehousePublicId) ? $warehousePublicId : null
                    );

                    $resolvedUnitId = null;
                    if (is_string($unitPublicId)) {
                        /** @var Unit|null $unit */
                        $unit = Unit::where('company_id', $lockedCompany->id)
                            ->where('public_id', $unitPublicId)
                            ->lockForUpdate()
                            ->first();
                        if ($unit === null) {
                            throw new InvalidArgumentException("Specified unit [{$unitPublicId}] not found.");
                        }

                        $pu = ProductUnit::where('company_id', $lockedCompany->id)
                            ->where('product_id', $product->id)
                            ->where('unit_id', $unit->id)
                            ->where('active', true)
                            ->lockForUpdate()
                            ->first();

                        if ($pu === null) {
                            throw new InvalidArgumentException("Unit [{$unitPublicId}] is not configured or active for this product.");
                        }

                        $resolvedUnitId = (int) $unit->id;
                    }

                    $resolvedLotId = null;
                    if (is_string($lotPublicId)) {
                        /** @var InventoryLot|null $lot */
                        $lot = InventoryLot::where('company_id', $lockedCompany->id)
                            ->where('product_id', $product->id)
                            ->where('public_id', $lotPublicId)
                            ->lockForUpdate()
                            ->first();
                        if ($lot === null) {
                            throw new InvalidArgumentException("Lot [{$lotPublicId}] not found for this product.");
                        }
                        $resolvedLotId = (int) $lot->id;
                    }

                    $type = $deltaBc->isPositive()
                        ? StockMovement::TYPE_ADJUSTMENT_INCREASE
                        : StockMovement::TYPE_ADJUSTMENT_DECREASE;

                    $absQty = Quantity::of((string) $deltaBc->abs()->toScale(6, RoundingMode::HALF_UP));

                    app(CompanyContext::class)->setCompany($lockedCompany, $lockedActor);

                    $actionResult = $adjustStockAction->execute(
                        company: $lockedCompany,
                        product: $product,
                        warehouse: $warehouse,
                        type: $type,
                        quantity: $absQty,
                        reason: $reason,
                        user: $lockedActor,
                        idempotencyKey: $idempotencyKey,
                        unitCostBase: $unitCost,
                        unitId: $resolvedUnitId,
                        lotId: $resolvedLotId,
                        lotNumber: $lotNumber,
                        expiryDate: $expiryDate,
                        movementDate: $movementDate
                    );

                    /** @var StockMovement $movement */
                    $movement = $actionResult['movement'];

                    $snapshot = [
                        'id' => (string) $movement->public_id,
                        'product_id' => (string) $product->public_id,
                        'movement_type' => (string) $movement->movement_type,
                        'delta' => (string) $deltaBc->toScale(6, RoundingMode::HALF_UP),
                        'resulting_stock' => (string) BigDecimal::of((string) ($movement->quantity_after_product_company ?? $movement->quantity_after_base ?? '0'))->toScale(6, RoundingMode::HALF_UP),
                        'posted_at' => $movement->movement_date->format('Y-m-d\TH:i:s\Z'),
                        'idempotency_key' => $idempotencyKey,
                    ];

                    return [
                        'snapshot' => $snapshot,
                        'entity_type' => 'stock_movement',
                        'entity_id' => (int) $movement->id,
                        'entity_public_id' => (string) $movement->public_id,
                    ];
                }
            );

            $headers = [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Idempotency-Key' => $idempotencyKey,
            ];
            if ($result['outcome'] === 'replay') {
                $headers['X-Idempotent-Replay'] = 'true';
            }

            return response()->json($result['snapshot'], $result['status'], $headers);
        } catch (AuthenticationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'UNAUTHENTICATED', message: $e->getMessage(), status: 401);
        } catch (AuthorizationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'FORBIDDEN', message: $e->getMessage(), status: 403);
        } catch (IdempotencyConflictException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'IDEMPOTENCY_KEY_CONFLICT', message: $e->getMessage(), status: 409);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                return MobileApiBoundary::buildErrorResponse(code: 'NOT_FOUND', message: $e->getMessage(), status: 404);
            }

            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Adjustment failed: '.$e->getMessage(),
                status: 422
            );
        }
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/inventory/transfers/preview
     * Preview warehouse-to-warehouse stock transfer effects without mutating balances.
     */
    public function previewTransfer(Request $request, string $companyId): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('inventory.stock.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required inventory.stock.view permission.',
                status: 403
            );
        }

        $warehouses = Warehouse::where('company_id', $company->id)
            ->where('active', true)
            ->get()
            ->map(fn (Warehouse $w) => [
                'id' => (string) $w->public_id,
                'name' => (string) $w->displayName(),
                'code' => $w->code,
            ])
            ->values()
            ->all();

        return response()->json([
            'company_id' => $company->public_id,
            'available_warehouses' => $warehouses,
            'preview_status' => 'ready',
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }
}
