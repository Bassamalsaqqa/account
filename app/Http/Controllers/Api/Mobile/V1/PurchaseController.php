<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Purchasing\ExecutePhysicalPurchaseReturnAction;
use App\Actions\Purchasing\PurchaseReturnInput;
use App\Actions\Purchasing\RecordPurchaseGoodsReceiptAction;
use App\Actions\Purchasing\RecordStagedPurchaseBillAction;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Purchasing\DTO\GoodsReceiptLineCommand;
use App\Domain\Purchasing\DTO\RecordGoodsReceiptCommand;
use App\Domain\Purchasing\Exceptions\OverReceiptLimitException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\CompanyPurchaseSetting;
use App\Models\GoodsReceiptNoteLine;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchasePayableAsOf;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

final class PurchaseController extends Controller
{
    /**
     * GET /api/mobile/v1/companies/{companyId}/purchases
     */
    public function index(Request $request, string $companyId): JsonResponse
    {
        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $companyId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'Company not found.',
                status: 404
            );
        }

        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('purchasing.purchase.view') || ! $user->hasPermissionTo('purchasing.cost.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required purchasing.purchase.view permission.',
                status: 403
            );
        }

        $limit = min((int) ($request->query('limit', 30)), 100);
        if ($limit < 1) {
            $limit = 30;
        }

        $query = Purchase::where('company_id', $company->id)
            ->with(['vendor' => fn ($q) => $q->where('company_id', $company->id), 'lines.product']);

        $search = $request->query('search');
        if ($search !== null && is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($q) use ($term): void {
                $q->where('vendor_invoice_number', 'like', "%{$term}%")
                    ->orWhere('public_id', 'like', "%{$term}%")
                    ->orWhereHas('vendor', function ($vq) use ($term): void {
                        $vq->where('name_ar', 'like', "%{$term}%")
                            ->orWhere('name_en', 'like', "%{$term}%");
                    });
            });
        }

        if ($request->filled('supplier_id')) {
            $query->whereHas('vendor', fn ($q) => $q->where('company_id', $company->id)->where('public_id', (string) $request->query('supplier_id')));
        }
        if ($request->boolean('allocatable')) {
            $query->where('status', Purchase::STATUS_POSTED)->whereNotNull('posting_batch_id')
                ->where('purchase_date', '<=', now()->toDateString());
        }
        if ($request->filled('before_id')) {
            $query->where('public_id', '<', (string) $request->query('before_id'));
        }

        $records = $query->orderBy('public_id', 'desc')
            ->limit($limit + 1)
            ->get();

        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
        }

        $receivedByLine = GoodsReceiptNoteLine::where('company_id', $company->id)
            ->whereIn('purchase_line_id', $records->flatMap(fn ($p) => $p->lines->pluck('id')))
            ->selectRaw('purchase_line_id, SUM(quantity_base) AS received')
            ->groupBy('purchase_line_id')->pluck('received', 'purchase_line_id');
        $companyTimezone = (string) ($company->timezone ?: 'UTC');
        $businessDate = Carbon::now($companyTimezone);
        $positions = app(PurchasePayableAsOf::class)->components($records->filter(fn ($p) => $p->isPosted() && $p->purchase_date->format('Y-m-d') <= $businessDate->toDateString()), $businessDate);
        $bills = $records->map(function (Purchase $p) use ($positions, $receivedByLine): array {
            $grandTotal = BigDecimal::of((string) $p->grand_total_currency);
            $paid = ($positions[(int) $p->id]['paid'] ?? BigDecimal::zero());
            $remaining = ($positions[(int) $p->id]['outstanding'] ?? BigDecimal::zero());

            if ($p->is_staged) {
                $allReceived = true;
                if ($p->lines->isEmpty()) {
                    $allReceived = false;
                } else {
                    foreach ($p->lines as $line) {
                        $totRec = $receivedByLine->get($line->id, '0');
                        $lineRem = BigDecimal::of((string) $line->quantity_base)
                            ->minus(BigDecimal::of((string) $totRec));
                        if ($lineRem->isPositive()) {
                            $allReceived = false;
                            break;
                        }
                    }
                }
                $isReceived = $allReceived;
            } else {
                $isReceived = $p->lines->contains(fn ($l) => $l->stock_movement_id !== null);
            }

            $itemsSummary = $p->lines->map(function ($l) {
                return $l->product_name_ar ?? ($l->product ? (string) $l->product->displayName() : (string) $l->item_description);
            })->filter()->values()->take(3)->implode('، ');

            return [
                'id' => (string) $p->public_id,
                'bill_number' => (string) ($p->vendor_invoice_number ?? $p->purchase_number ?? $p->public_id),
                'supplier_id' => $p->vendor ? (string) $p->vendor->public_id : null,
                'supplier_name' => $p->vendor ? (string) $p->vendor->displayName() : 'مورد عام',
                'date' => $p->purchase_date->format('Y-m-d'),
                'currency' => (string) $p->currency_code,
                'total_exact' => (string) $grandTotal,
                'paid_exact' => (string) $paid,
                'remaining_exact' => (string) $remaining,
                'returned_exact' => (string) ($positions[(int) $p->id]['returned'] ?? BigDecimal::zero()),
                'total' => (string) $grandTotal->toScale(2, RoundingMode::HALF_UP),
                'total_ils' => (string) $grandTotal->toScale(2, RoundingMode::HALF_UP),
                'paid' => (string) $paid->toScale(2, RoundingMode::HALF_UP),
                'paid_ils' => (string) $paid->toScale(2, RoundingMode::HALF_UP),
                'remaining_payable_ils' => (string) $remaining->toScale(2, RoundingMode::HALF_UP),
                'status' => (string) $p->status,
                'is_received' => $isReceived,
                'items_summary' => $itemsSummary,
                'lines_count' => $p->lines->count(),
            ];
        })->values()->all();

        if ($request->boolean('allocatable')) {
            $bills = array_values(array_filter($bills, fn (array $row): bool => BigDecimal::of($row['remaining_exact'])->isPositive()));
        }

        return response()->json([
            'company_id' => $company->public_id,
            'count' => count($bills),
            'has_more' => $hasMore,
            'next_before_id' => $hasMore ? (string) $records->last()?->public_id : null,
            'data' => $bills,
            'purchases' => $bills,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * GET /api/mobile/v1/companies/{companyId}/purchases/{purchaseId}
     */
    public function show(Request $request, string $companyId, string $purchaseId): JsonResponse
    {
        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $companyId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'Company not found.',
                status: 404
            );
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $purchaseId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested purchase bill was not found.',
                status: 404
            );
        }

        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('purchasing.purchase.view') || ! $user->hasPermissionTo('purchasing.cost.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required purchasing.purchase.view permission.',
                status: 403
            );
        }

        $purchase = Purchase::where('company_id', $company->id)
            ->where('public_id', $purchaseId)
            ->with(['vendor', 'lines.product', 'lines.productUnit.unit', 'warehouse'])
            ->first();

        if ($purchase === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested purchase bill was not found.',
                status: 404
            );
        }

        $companyTimezone = (string) ($company->timezone ?: 'UTC');
        $businessDate = Carbon::now($companyTimezone);
        $position = $purchase->isPosted() && $purchase->purchase_date->format('Y-m-d') <= $businessDate->toDateString() ? app(PurchasePayableAsOf::class)->components([$purchase], $businessDate)[(int) $purchase->id] : [];
        $grandTotal = BigDecimal::of((string) $purchase->grand_total_currency);
        $allComplete = true;
        $anyReceived = false;

        $receivedByLine = GoodsReceiptNoteLine::where('company_id', $company->id)
            ->whereIn('purchase_line_id', $purchase->lines->pluck('id'))
            ->selectRaw('purchase_line_id, SUM(quantity_base) AS received')
            ->groupBy('purchase_line_id')->pluck('received', 'purchase_line_id');
        $returnedByLine = PurchaseReturnAllocation::query()
            ->join('purchase_return_lines as line', 'line.id', '=', 'purchase_return_allocations.purchase_return_line_id')
            ->where('purchase_return_allocations.company_id', $company->id)
            ->whereIn('line.purchase_line_id', $purchase->lines->pluck('id'))
            ->whereHas('purchaseReturn', fn ($q) => $q->where('warehouse_id', $purchase->warehouse_id)
                ->where('status', PurchaseReturn::STATUS_POSTED)
                ->where(fn ($wq) => $wq->whereNull('origin_warehouse_id')->orWhere('origin_warehouse_id', $purchase->warehouse_id)))
            ->selectRaw('line.purchase_line_id, SUM(purchase_return_allocations.quantity_base) AS returned')
            ->groupBy('line.purchase_line_id')->pluck('returned', 'line.purchase_line_id');

        $linesPayload = $purchase->lines->map(function (PurchaseLine $line) use ($purchase, $receivedByLine, $returnedByLine, &$allComplete, &$anyReceived): array {
            $qty = BigDecimal::of((string) $line->quantity_base);
            $cost = BigDecimal::of((string) $line->unit_cost);
            $unitName = $line->productUnit?->unit?->displayName() ?? 'وحدة';

            if ($purchase->is_staged) {
                $totRec = $receivedByLine->get($line->id, '0');
                $prevReceivedBd = BigDecimal::of((string) $totRec);

                $preReturnedRaw = $returnedByLine->get($line->id, '0');
                $preReturnedBd = BigDecimal::of((string) $preReturnedRaw);

                $remBd = $qty->minus($prevReceivedBd)->minus($preReturnedBd);
                if ($remBd->isNegative()) {
                    $remBd = BigDecimal::zero();
                }

                if ($prevReceivedBd->isPositive()) {
                    $anyReceived = true;
                }
                if ($remBd->isPositive()) {
                    $allComplete = false;
                }

                $isReceived = $remBd->isZero();

                return [
                    'id' => (string) $line->public_id,
                    'product_id' => $line->product ? (string) $line->product->public_id : null,
                    'product_name' => $line->product_name_ar ?? ($line->product ? (string) $line->product->displayName() : (string) $line->item_description),
                    'ordered_quantity' => (string) $qty->toScale(6, RoundingMode::HALF_UP),
                    'previously_received_quantity' => (string) $prevReceivedBd->toScale(6, RoundingMode::HALF_UP),
                    'remaining_quantity' => (string) $remBd->toScale(6, RoundingMode::HALF_UP),
                    'is_received' => $isReceived,
                    'unit_cost_ils' => (string) $cost->toScale(2, RoundingMode::HALF_UP),
                    'total_cost_ils' => (string) $qty->multipliedBy($cost)->toScale(2, RoundingMode::HALF_UP),
                    'unit_name' => (string) $unitName,
                ];
            } else {
                $isReceived = $line->stock_movement_id !== null;
                $anyReceived = true;

                return [
                    'id' => (string) $line->public_id,
                    'product_id' => $line->product ? (string) $line->product->public_id : null,
                    'product_name' => $line->product_name_ar ?? ($line->product ? (string) $line->product->displayName() : (string) $line->item_description),
                    'ordered_quantity' => (string) $qty->toScale(6, RoundingMode::HALF_UP),
                    'previously_received_quantity' => (string) $qty->toScale(6, RoundingMode::HALF_UP),
                    'remaining_quantity' => '0.000000',
                    'is_received' => true,
                    'unit_cost_ils' => (string) $cost->toScale(2, RoundingMode::HALF_UP),
                    'total_cost_ils' => (string) $qty->multipliedBy($cost)->toScale(2, RoundingMode::HALF_UP),
                    'unit_name' => (string) $unitName,
                ];
            }
        })->values()->all();

        if ($purchase->is_staged) {
            $receivingStatus = $allComplete ? 'received' : ($anyReceived ? 'partial' : 'pending');
        } else {
            $receivingStatus = 'received';
        }

        return response()->json([
            'id' => (string) $purchase->public_id,
            'bill_number' => (string) ($purchase->vendor_invoice_number ?? $purchase->purchase_number ?? $purchase->public_id),
            'supplier_id' => $purchase->vendor ? (string) $purchase->vendor->public_id : null,
            'supplier_name' => $purchase->vendor ? (string) $purchase->vendor->displayName() : 'مورد عام',
            'date' => $purchase->purchase_date->format('Y-m-d'),
            'currency' => (string) $purchase->currency_code,
            'total_exact' => (string) $grandTotal,
            'paid_exact' => (string) ($position['paid'] ?? BigDecimal::zero()),
            'remaining_exact' => (string) ($position['outstanding'] ?? BigDecimal::zero()),
            'returned_exact' => (string) ($position['returned'] ?? BigDecimal::zero()),
            'total_ils' => (string) $grandTotal->toScale(2, RoundingMode::HALF_UP),
            'status' => (string) $purchase->status,
            'receiving_status' => $receivingStatus,
            'warehouse' => $purchase->warehouse ? (string) $purchase->warehouse->displayName() : 'المستودع الرئيسي',
            'lines' => $linesPayload,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/purchases
     */
    public function store(
        Request $request,
        string $companyId,
        RecordStagedPurchaseBillAction $recordStagedBillAction
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for purchase bill creation.',
                status: 422
            );
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $companyId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'Company not found.',
                status: 404
            );
        }

        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');
        /** @var MobileAccessToken|null $token */
        $token = $request->attributes->get('mobile_token');
        /** @var MobileDevice|null $device */
        $device = $request->attributes->get('mobile_device');

        $supplierPublicId = $request->input('supplier_id');
        if (! is_string($supplierPublicId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $supplierPublicId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Valid supplier ULID is required.',
                status: 422
            );
        }

        $linesInput = $request->input('lines', []);
        if (! is_array($linesInput) || empty($linesInput)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Purchase bill requires at least one line item.',
                status: 422
            );
        }

        $billNumber = $request->input('bill_number');
        $paidNow = $request->input('paid_now');
        $explicitAccountId = $request->input('account_id') ?? $request->input('money_account_id');

        try {
            $result = $recordStagedBillAction->execute(
                company: $company,
                actor: $user,
                supplierPublicId: $supplierPublicId,
                billNumber: is_string($billNumber) ? $billNumber : null,
                linesInput: $linesInput,
                idempotencyKey: trim($idempotencyKey),
                paidNow: is_numeric((string) $paidNow) ? (string) $paidNow : null,
                explicitAccountId: is_string($explicitAccountId) ? $explicitAccountId : null,
                mobileToken: $token,
                mobileDevice: $device
            );

            $status = $result['is_replay'] ? 200 : 201;
            $headers = [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Idempotency-Key' => trim($idempotencyKey),
            ];
            if ($result['is_replay']) {
                $headers['X-Idempotent-Replay'] = 'true';
            }

            return response()->json($result['snapshot'], $status, $headers);
        } catch (AuthenticationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'UNAUTHENTICATED', message: $e->getMessage(), status: 401);
        } catch (AuthorizationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'FORBIDDEN', message: $e->getMessage(), status: 403);
        } catch (IdempotencyConflictException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'IDEMPOTENCY_KEY_CONFLICT', message: $e->getMessage(), status: 409);
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Failed to record purchase bill: '.$e->getMessage(),
                status: 422
            );
        }
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/purchases/{purchaseId}/grn
     * Canonical physical goods receipt note (GRN) with paired internal stock transfer.
     */
    public function receiveGoods(
        Request $request,
        string $companyId,
        string $purchaseId,
        RecordPurchaseGoodsReceiptAction $recordGrnAction
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for GRN stock receipts.',
                status: 422
            );
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $companyId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'Company not found.',
                status: 404
            );
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $purchaseId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested purchase bill was not found.',
                status: 404
            );
        }

        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');
        /** @var MobileAccessToken|null $token */
        $token = $request->attributes->get('mobile_token');
        /** @var MobileDevice|null $device */
        $device = $request->attributes->get('mobile_device');

        if (! $user->hasPermissionTo('inventory.stock.transfer') || ! $user->hasPermissionTo('purchasing.purchase.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required stock receipt permissions.',
                status: 403
            );
        }

        /** @var Purchase|null $purchase */
        $purchase = Purchase::where('company_id', $company->id)
            ->where('public_id', $purchaseId)
            ->with(['lines.product', 'lines.lots', 'warehouse', 'vendor'])
            ->first();

        if ($purchase === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested purchase bill was not found.',
                status: 404
            );
        }

        if (! $purchase->isPosted()) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Purchase bill must be posted before goods can be received.',
                status: 422
            );
        }

        // Resolve Destination Warehouse
        $destWhPublicId = $request->input('destination_warehouse_id');
        if (is_string($destWhPublicId) && trim($destWhPublicId) !== '') {
            $destWarehouse = Warehouse::where('company_id', $company->id)
                ->where('public_id', trim($destWhPublicId))
                ->where('is_transit', false)
                ->where('active', true)
                ->first();

            if ($destWarehouse === null) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Destination warehouse not found or is invalid.',
                    status: 422
                );
            }
        } else {
            // Fall back to approved default receiving warehouse configuration
            $settings = CompanyPurchaseSetting::where('company_id', $company->id)->first();
            $defaultWhId = $settings?->default_receiving_warehouse_id;

            if ($defaultWhId !== null) {
                $destWarehouse = Warehouse::where('company_id', $company->id)
                    ->where('id', $defaultWhId)
                    ->where('is_transit', false)
                    ->where('active', true)
                    ->first();
            } else {
                $destWarehouse = Warehouse::where('company_id', $company->id)
                    ->where('is_default', true)
                    ->where('is_transit', false)
                    ->where('active', true)
                    ->first();
            }

            if ($destWarehouse === null) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'No approved physical receiving warehouse configured for company.',
                    status: 422
                );
            }
        }

        $linesInput = $request->input('received_lines') ?? $request->input('lines');
        if (! is_array($linesInput) || empty($linesInput)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Goods receipt requires at least one line item.',
                status: 422
            );
        }

        // Parse line commands
        $lineCommands = [];
        foreach ($linesInput as $l) {
            $linePublicId = $l['purchase_line_id'] ?? null;
            if (! is_string($linePublicId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $linePublicId) !== 1) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Valid purchase line ULID is required.',
                    status: 422
                );
            }

            $purchaseLine = $purchase->lines->first(fn ($pl) => (string) $pl->public_id === (string) $linePublicId);
            if ($purchaseLine === null) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: "Line [{$linePublicId}] does not belong to this purchase bill.",
                    status: 422
                );
            }

            $rawQty = $l['quantity'] ?? null;
            if ($rawQty === null || ! is_numeric((string) $rawQty)) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: "Line [{$linePublicId}] requires a valid numeric quantity.",
                    status: 422
                );
            }

            $qtyBd = BigDecimal::of((string) $rawQty);
            if ($qtyBd->isLessThanOrEqualTo(0)) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: "Line [{$linePublicId}] quantity must be strictly positive.",
                    status: 422
                );
            }

            $lineCommands[] = new GoodsReceiptLineCommand(
                purchaseLineId: (int) $purchaseLine->id,
                purchaseLinePublicId: (string) $purchaseLine->public_id,
                quantity: $qtyBd,
                lotNumber: isset($l['lot_number']) && is_string($l['lot_number']) ? trim($l['lot_number']) : null,
                expiryDate: isset($l['expiry_date']) && is_string($l['expiry_date']) ? trim($l['expiry_date']) : null
            );
        }

        $receiptDate = $request->input('receipt_date');
        $resolvedDate = is_string($receiptDate) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $receiptDate) === 1
            ? $receiptDate
            : now()->format('Y-m-d');

        $notes = $request->input('notes');

        $command = new RecordGoodsReceiptCommand(
            companyId: (int) $company->id,
            purchaseId: (int) $purchase->id,
            destinationWarehouseId: (int) $destWarehouse->id,
            receiptDate: $resolvedDate,
            lines: $lineCommands,
            idempotencyKey: trim($idempotencyKey),
            createdBy: (int) $user->id,
            notes: is_string($notes) ? trim($notes) : null
        );

        try {
            app(CompanyContext::class)->setCompany($company, $user);

            $grn = $recordGrnAction->execute($company, $purchase, $user, $command, $token, $device);

            $isReplay = ! $grn->wasRecentlyCreated;
            $statusCode = $isReplay ? 200 : 201;

            $headers = [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Idempotency-Key' => $command->idempotencyKey,
            ];
            if ($isReplay) {
                $headers['X-Idempotent-Replay'] = 'true';
            }

            return response()->json($grn->response_snapshot, $statusCode, $headers);
        } catch (AuthenticationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'UNAUTHENTICATED', message: $e->getMessage(), status: 401);
        } catch (AuthorizationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'FORBIDDEN', message: $e->getMessage(), status: 403);
        } catch (IdempotencyConflictException $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'IDEMPOTENCY_KEY_CONFLICT',
                message: $e->getMessage(),
                status: 409
            );
        } catch (OverReceiptLimitException $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'OVER_RECEIPT_LIMIT_EXCEEDED',
                message: $e->getMessage(),
                status: 422
            );
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'GRN stock receipt failed: '.$e->getMessage(),
                status: 422
            );
        }
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/purchases/returns/preview
     */
    public function previewReturn(Request $request, string $companyId): JsonResponse
    {
        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $companyId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'Company not found.',
                status: 404
            );
        }

        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('purchasing.return.view') && ! $user->hasPermissionTo('purchasing.purchase.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required purchasing.return.view permission.',
                status: 403
            );
        }

        $purchasePublicId = $request->input('purchase_id');
        if (! is_string($purchasePublicId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $purchasePublicId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested purchase bill was not found.',
                status: 404
            );
        }

        $purchase = Purchase::where('company_id', $company->id)
            ->where('public_id', $purchasePublicId)
            ->with(['lines.product'])
            ->first();

        if ($purchase === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested purchase bill was not found.',
                status: 404
            );
        }

        $previewLines = [];
        $totalReturnCost = BigDecimal::zero();
        $seen = [];
        try {
            $lines = PurchaseReturnInput::normalize($request->input('lines'));
            foreach ($lines as $lineData) {
                $origLine = PurchaseReturnInput::resolveLine($purchase, $lineData);
                if (isset($seen[$origLine->id])) {
                    throw new \InvalidArgumentException('Repeated original purchase line.');
                }
                $seen[$origLine->id] = true;
                $quantity = BigDecimal::of($lineData['quantity']);
                $unitCost = BigDecimal::of((string) $origLine->unit_cost);
                $lineTotal = $quantity->multipliedBy($unitCost);
                $totalReturnCost = $totalReturnCost->plus($lineTotal);

                $previewLines[] = [
                    'purchase_line_id' => (string) $origLine->public_id,
                    'product_id' => $origLine->product ? (string) $origLine->product->public_id : (string) $lineData['product_id'],
                    'product_name' => $origLine->product ? (string) $origLine->product->displayName() : 'بند مشتريات',
                    'quantity' => (string) $quantity,
                    'unit_cost_ils' => (string) $unitCost->toScale(2, RoundingMode::HALF_UP),
                    'line_total_ils' => (string) $lineTotal->toScale(2, RoundingMode::HALF_UP),
                ];
            }
        } catch (\InvalidArgumentException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'VALIDATION_FAILED', message: $e->getMessage(), status: 422);
        }

        return response()->json([
            'purchase_id' => (string) $purchase->public_id,
            'total_debit_ils' => (string) $totalReturnCost->toScale(2, RoundingMode::HALF_UP),
            'currency' => (string) $purchase->currency_code,
            'lines' => $previewLines,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/purchases/returns
     */
    public function storeReturn(
        Request $request,
        string $companyId,
        ExecutePhysicalPurchaseReturnAction $executeReturnAction
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for purchase returns.',
                status: 422
            );
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $companyId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'Company not found.',
                status: 404
            );
        }

        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');
        /** @var MobileAccessToken|null $token */
        $token = $request->attributes->get('mobile_token');
        /** @var MobileDevice|null $device */
        $device = $request->attributes->get('mobile_device');

        $purchasePublicId = $request->input('purchase_id');
        if (! is_string($purchasePublicId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $purchasePublicId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested purchase bill was not found.',
                status: 404
            );
        }

        $purchase = Purchase::where('company_id', $company->id)
            ->where('public_id', $purchasePublicId)
            ->with(['lines.product', 'lines.lots', 'warehouse'])
            ->first();

        if ($purchase === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested purchase bill was not found.',
                status: 404
            );
        }

        $lines = $request->input('lines', []);
        if (! is_array($lines) || empty($lines)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Lines array is required.',
                status: 422
            );
        }

        $specifiedWarehousePublicId = $request->input('warehouse_id');
        $specifiedGrnPublicId = $request->input('goods_receipt_note_id');
        $reqWarehouse = null;
        $reason = $request->input('reason');
        try {
            if ($specifiedWarehousePublicId !== null) {
                if (! is_string($specifiedWarehousePublicId) || ! Str::isUlid($specifiedWarehousePublicId)) {
                    throw new \InvalidArgumentException('Invalid warehouse identifier.');
                }
                $reqWarehouse = Warehouse::where('company_id', $company->id)->where('public_id', $specifiedWarehousePublicId)->first();
                if ($reqWarehouse === null) {
                    throw new \InvalidArgumentException('Unknown warehouse.');
                }
            }
            if ($specifiedGrnPublicId !== null && (! is_string($specifiedGrnPublicId) || ! Str::isUlid($specifiedGrnPublicId))) {
                throw new \InvalidArgumentException('Invalid goods receipt identifier.');
            }
            if ($reason !== null && ! is_string($reason)) {
                throw new \InvalidArgumentException('Invalid return reason.');
            }
            $result = $executeReturnAction->execute(
                company: $company,
                actor: $user,
                purchase: $purchase,
                lines: $lines,
                idempotencyKey: trim($idempotencyKey),
                reqWarehouse: $reqWarehouse,
                specifiedGrnPublicId: is_string($specifiedGrnPublicId) ? $specifiedGrnPublicId : null,
                token: $token,
                device: $device,
                reason: is_string($reason) ? $reason : null
            );

            $headers = [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Idempotency-Key' => trim($idempotencyKey),
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
            return MobileApiBoundary::buildErrorResponse(
                code: 'IDEMPOTENCY_KEY_CONFLICT',
                message: $e->getMessage(),
                status: 409
            );
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Purchase return failed: '.$e->getMessage(),
                status: 422
            );
        }
    }
}
