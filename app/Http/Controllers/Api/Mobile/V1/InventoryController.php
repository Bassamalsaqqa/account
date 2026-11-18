<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Inventory\AdjustStockAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                'resulting_stock' => (string) BigDecimal::of((string) ($m->quantity_after_base ?? '0'))->toScale(4, RoundingMode::HALF_UP),
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
    public function adjust(Request $request, string $companyId, AdjustStockAction $adjustStockAction): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('inventory.stock.adjust')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required inventory.stock.adjust permission.',
                status: 403
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

        $product = Product::where('company_id', $company->id)
            ->where('public_id', $productId)
            ->first();

        if ($product === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested product was not found.',
                status: 404
            );
        }

        if (! $product->track_stock) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Non-stock and service items cannot have inventory adjustments.',
                status: 422
            );
        }

        $deltaInput = $request->input('delta');
        if ($deltaInput === null || ! is_scalar($deltaInput)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Adjustment delta is required.',
                status: 422
            );
        }

        $delta = BigDecimal::of((string) $deltaInput);
        if ($delta->isZero()) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Adjustment delta cannot be zero.',
                status: 422
            );
        }

        $reason = (string) ($request->input('reason', 'تعديل مخزني يدوي من تطبيق الجوال'));
        if (trim($reason) === '') {
            $reason = 'تعديل مخزني يدوي من تطبيق الجوال';
        }

        $warehouse = Warehouse::where('company_id', $company->id)->where('active', true)->first();
        if ($warehouse === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'No active warehouse configured for company.',
                status: 422
            );
        }

        $type = $delta->isPositive()
            ? StockMovement::TYPE_ADJUSTMENT_INCREASE
            : StockMovement::TYPE_ADJUSTMENT_DECREASE;

        $absQty = Quantity::of((string) $delta->abs()->toScale(6, RoundingMode::HALF_UP));

        $rawKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        $idempotencyKey = is_string($rawKey) ? trim($rawKey) : '';
        if ($idempotencyKey === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for this operation.',
                status: 422
            );
        }

        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $result = $adjustStockAction->execute(
                $company,
                $product,
                $warehouse,
                $type,
                $absQty,
                $reason,
                $user,
                $idempotencyKey,
                (string) ($product->default_purchase_cost_base ?? '0')
            );
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Adjustment failed: '.$e->getMessage(),
                status: 422
            );
        }

        $movement = $result['movement'];

        return response()->json([
            'id' => (string) $movement->public_id,
            'product_id' => (string) $product->public_id,
            'movement_type' => (string) $movement->movement_type,
            'delta' => (string) $delta,
            'resulting_stock' => (string) BigDecimal::of((string) ($movement->quantity_after_base ?? '0'))->toScale(4, RoundingMode::HALF_UP),
            'posted_at' => $movement->movement_date->format('Y-m-d\TH:i:s\Z'),
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
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
