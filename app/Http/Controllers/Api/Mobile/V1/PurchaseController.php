<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        if (! $user->hasPermissionTo('purchasing.purchase.view')) {
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
            ->with(['vendor' => fn ($q) => $q->where('company_id', $company->id), 'lines']);

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

        $records = $query->orderBy('id', 'desc')
            ->limit($limit + 1)
            ->get();

        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
        }

        $bills = $records->map(function (Purchase $p): array {
            $grandTotal = BigDecimal::of((string) $p->grand_total_currency);
            $paid = BigDecimal::zero();
            $remaining = $grandTotal->minus($paid);

            $hasTrackedLines = $p->lines->contains(fn ($l) => $l->stock_movement_id !== null);

            return [
                'id' => (string) $p->public_id,
                'bill_number' => (string) ($p->vendor_invoice_number ?? $p->public_id),
                'supplier_id' => $p->vendor ? (string) $p->vendor->public_id : null,
                'supplier_name' => $p->vendor ? (string) $p->vendor->displayName() : 'مورد عام',
                'date' => $p->purchase_date->format('Y-m-d'),
                'total_ils' => (string) $grandTotal->toScale(2, RoundingMode::HALF_UP),
                'paid_ils' => (string) $paid->toScale(2, RoundingMode::HALF_UP),
                'remaining_payable_ils' => (string) $remaining->toScale(2, RoundingMode::HALF_UP),
                'status' => (string) $p->status,
                'is_received' => $hasTrackedLines,
                'lines_count' => $p->lines->count(),
            ];
        })->values()->all();

        return response()->json([
            'company_id' => $company->public_id,
            'count' => count($bills),
            'has_more' => $hasMore,
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

        if (! $user->hasPermissionTo('purchasing.purchase.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required purchasing.purchase.view permission.',
                status: 403
            );
        }

        $purchase = Purchase::where('company_id', $company->id)
            ->where('public_id', $purchaseId)
            ->with(['vendor', 'lines.product', 'warehouse'])
            ->first();

        if ($purchase === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested purchase bill was not found.',
                status: 404
            );
        }

        $grandTotal = BigDecimal::of((string) $purchase->grand_total_currency);

        $linesPayload = $purchase->lines->map(function ($line): array {
            $isReceived = $line->stock_movement_id !== null;
            $qty = BigDecimal::of((string) $line->quantity);
            $cost = BigDecimal::of((string) $line->unit_cost);

            return [
                'id' => (string) $line->public_id,
                'product_id' => $line->product ? (string) $line->product->public_id : null,
                'product_name' => $line->product_name_ar ?? ($line->product ? (string) $line->product->displayName() : (string) $line->item_description),
                'quantity' => (string) $qty->toScale(4, RoundingMode::HALF_UP),
                'unit_cost_ils' => (string) $cost->toScale(2, RoundingMode::HALF_UP),
                'total_cost_ils' => (string) $qty->multipliedBy($cost)->toScale(2, RoundingMode::HALF_UP),
                'received_quantity' => $isReceived ? (string) $qty : '0.0000',
                'is_received' => $isReceived,
            ];
        })->values()->all();

        return response()->json([
            'id' => (string) $purchase->public_id,
            'bill_number' => (string) ($purchase->vendor_invoice_number ?? $purchase->public_id),
            'supplier_id' => $purchase->vendor ? (string) $purchase->vendor->public_id : null,
            'supplier_name' => $purchase->vendor ? (string) $purchase->vendor->displayName() : 'مورد عام',
            'date' => $purchase->purchase_date->format('Y-m-d'),
            'total_ils' => (string) $grandTotal->toScale(2, RoundingMode::HALF_UP),
            'status' => (string) $purchase->status,
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
        CreatePurchaseDraftAction $createDraftAction,
        PostPurchaseAction $postPurchaseAction,
        PostVendorPaymentAction $postPaymentAction
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

        if (! $user->hasPermissionTo('purchasing.purchase.create') || ! $user->hasPermissionTo('purchasing.purchase.post')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required purchase permissions.',
                status: 403
            );
        }

        $supplierPublicId = $request->input('supplier_id');
        if (! is_string($supplierPublicId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $supplierPublicId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Valid supplier ULID is required.',
                status: 422
            );
        }

        $vendor = Vendor::where('company_id', $company->id)
            ->where('public_id', $supplierPublicId)
            ->first();

        if ($vendor === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Valid supplier is required.',
                status: 422
            );
        }

        $warehouse = Warehouse::where('company_id', $company->id)->where('active', true)->first();
        if ($warehouse === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'No active warehouse configured for company.',
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
        $resolvedBillNumber = is_string($billNumber) && trim($billNumber) !== ''
            ? trim($billNumber)
            : 'BILL-'.substr(sha1($idempotencyKey), 0, 10);

        try {
            $posted = DB::transaction(function () use ($company, $user, $vendor, $warehouse, $linesInput, $resolvedBillNumber, $idempotencyKey, $request, $createDraftAction, $postPurchaseAction, $postPaymentAction): Purchase {
                app(CompanyContext::class)->setCompany($company, $user);

                $draftLines = [];
                foreach ($linesInput as $l) {
                    $prodId = $l['product_id'] ?? null;
                    if (! is_string($prodId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $prodId) !== 1) {
                        continue;
                    }

                    $prod = Product::where('company_id', $company->id)
                        ->where('public_id', $prodId)
                        ->first();

                    if ($prod !== null) {
                        $draftLines[] = [
                            'product_id' => $prod->id,
                            'product_unit_id' => $prod->base_unit_id,
                            'quantity' => (string) ($l['quantity'] ?? '1'),
                            'unit_cost' => (string) ($l['unit_cost_ils'] ?? $prod->default_purchase_cost_base ?? '0'),
                        ];
                    }
                }

                if (empty($draftLines)) {
                    throw new \InvalidArgumentException('No valid product lines found in purchase request.');
                }

                $draftData = [
                    'vendor_id' => $vendor->id,
                    'warehouse_id' => $warehouse->id,
                    'currency_code' => $company->base_currency_code,
                    'exchange_rate' => '1.0000000000',
                    'purchase_date' => now()->format('Y-m-d'),
                    'vendor_invoice_number' => $resolvedBillNumber,
                    'lines' => $draftLines,
                ];

                $draft = $createDraftAction->execute($company, $user, $draftData);
                $postedPurchase = $postPurchaseAction->execute($draft, $user);

                // If paid_now is provided and positive, record immediate payment disbursement
                $paidNow = $request->input('paid_now');
                if ($paidNow !== null && BigDecimal::of((string) $paidNow)->isPositive()) {
                    $cashAccount = MoneyAccount::where('company_id', $company->id)
                        ->where('account_type', MoneyAccount::TYPE_CASH)
                        ->where('is_active', true)
                        ->first();

                    if ($cashAccount !== null) {
                        $paymentData = [
                            'vendor_id' => $vendor->id,
                            'money_account_id' => $cashAccount->id,
                            'currency_code' => $postedPurchase->currency_code,
                            'exchange_rate' => '1.0000000000',
                            'amount' => (string) BigDecimal::of((string) $paidNow),
                            'payment_date' => now()->format('Y-m-d'),
                            'payment_method' => 'cash',
                            'notes' => 'سداد نقدي عند شراء فاتورة #'.$postedPurchase->vendor_invoice_number,
                            'idempotency_key' => $idempotencyKey.'-pay',
                            'allocations' => [
                                [
                                    'purchase_id' => $postedPurchase->id,
                                    'allocated_amount' => (string) BigDecimal::of((string) $paidNow),
                                ],
                            ],
                        ];
                        $postPaymentAction->execute($company, $user, $paymentData);
                    }
                }

                return $postedPurchase;
            });
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Failed to record purchase bill: '.$e->getMessage(),
                status: 422
            );
        }

        return response()->json([
            'id' => (string) $posted->public_id,
            'bill_number' => (string) ($posted->vendor_invoice_number ?? $posted->public_id),
            'status' => (string) $posted->status,
            'total_ils' => (string) BigDecimal::of((string) $posted->grand_total_currency)->toScale(2, RoundingMode::HALF_UP),
            'posted_at' => $posted->posted_at ? $posted->posted_at->format('Y-m-d\TH:i:s\Z') : now()->format('Y-m-d\TH:i:s\Z'),
        ], 201, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/purchases/{purchaseId}/grn
     * Canonical physical goods receipt note (GRN) linked to purchase draft/posting
     */
    public function receiveGoods(
        Request $request,
        string $companyId,
        string $purchaseId,
        PostPurchaseAction $postPurchaseAction
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for GRN receipt.',
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

        if (! $user->hasPermissionTo('purchasing.purchase.post') && ! $user->hasPermissionTo('inventory.stock.adjust')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required stock receipt permissions.',
                status: 403
            );
        }

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

        $productPublicId = $request->input('product_id');
        if ($productPublicId !== null) {
            if (! is_string($productPublicId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $productPublicId) !== 1) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Invalid product ID format.',
                    status: 422
                );
            }
            $matchedLine = $purchase->lines->first(fn ($l) => $l->product && (string) $l->product->public_id === (string) $productPublicId);
            if ($matchedLine === null) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Requested product does not belong to this purchase bill.',
                    status: 422
                );
            }
        }

        if ($purchase->isPosted()) {
            $firstLine = $purchase->lines->first();
            $targetProduct = $firstLine?->product;

            return response()->json([
                'purchase_id' => (string) $purchase->public_id,
                'product_id' => $targetProduct ? (string) $targetProduct->public_id : null,
                'product_name' => $targetProduct ? (string) $targetProduct->displayName() : 'بضاعة عامة',
                'quantity_received' => (string) ($firstLine ? $firstLine->quantity : '0'),
                'is_received' => true,
            ], 200, [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            ]);
        }

        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $postedPurchase = $postPurchaseAction->execute($purchase, $user);
            $firstLine = $postedPurchase->lines->first();
            $targetProduct = $firstLine?->product;

            return response()->json([
                'purchase_id' => (string) $postedPurchase->public_id,
                'product_id' => $targetProduct ? (string) $targetProduct->public_id : null,
                'product_name' => $targetProduct ? (string) $targetProduct->displayName() : 'بضاعة عامة',
                'quantity_received' => (string) ($firstLine ? $firstLine->quantity : '0'),
                'is_received' => true,
            ], 200, [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            ]);
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

        $lines = $request->input('lines', []);
        $previewLines = [];
        $totalReturnCost = BigDecimal::zero();

        if (is_array($lines)) {
            foreach ($lines as $lineData) {
                $productId = $lineData['product_id'] ?? null;
                $quantity = BigDecimal::of((string) ($lineData['quantity'] ?? '1'));

                $origLine = $purchase->lines->first(function ($l) use ($productId) {
                    return $l->product && (string) $l->product->public_id === (string) $productId;
                });

                if ($origLine !== null) {
                    $unitCost = BigDecimal::of((string) $origLine->unit_cost);
                    $lineTotal = $quantity->multipliedBy($unitCost);
                    $totalReturnCost = $totalReturnCost->plus($lineTotal);

                    $previewLines[] = [
                        'product_id' => $productId,
                        'product_name' => $origLine->product ? (string) $origLine->product->displayName() : 'بند مشتريات',
                        'quantity' => (string) $quantity,
                        'unit_cost_ils' => (string) $unitCost->toScale(2, RoundingMode::HALF_UP),
                        'line_total_ils' => (string) $lineTotal->toScale(2, RoundingMode::HALF_UP),
                    ];
                }
            }
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
        CreatePurchaseReturnDraftAction $createReturnDraftAction,
        PostPurchaseReturnAction $postReturnAction
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

        if (! $user->hasPermissionTo('purchasing.return.manage') || ! $user->hasPermissionTo('purchasing.cost.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required purchasing.return.manage permission.',
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

        $lines = $request->input('lines', []);
        if (! is_array($lines) || empty($lines)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Lines array is required.',
                status: 422
            );
        }

        try {
            $posted = DB::transaction(function () use ($company, $user, $purchase, $lines, $createReturnDraftAction, $postReturnAction) {
                app(CompanyContext::class)->setCompany($company, $user);

                $draftLines = [];
                foreach ($lines as $lineData) {
                    $productId = $lineData['product_id'] ?? null;
                    $origLine = $purchase->lines->first(function ($l) use ($productId) {
                        return $l->product && (string) $l->product->public_id === (string) $productId;
                    });
                    if ($origLine !== null) {
                        $draftLines[] = [
                            'purchase_line_id' => (int) $origLine->id,
                            'quantity' => (string) ($lineData['quantity'] ?? '1'),
                        ];
                    }
                }

                if (empty($draftLines)) {
                    throw new \InvalidArgumentException('No valid purchase lines matched for return.');
                }

                $draftData = [
                    'purchase_id' => (int) $purchase->id,
                    'return_date' => now()->format('Y-m-d'),
                    'reason' => 'مرتجع مشتريات عبر تطبيق الجوال',
                    'lines' => $draftLines,
                ];

                $draft = $createReturnDraftAction->execute($company, $user, $draftData);

                return $postReturnAction->execute($draft, $user);
            });
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Purchase return failed: '.$e->getMessage(),
                status: 422
            );
        }

        return response()->json([
            'id' => (string) $posted->public_id,
            'status' => (string) $posted->status,
            'total_amount' => (string) BigDecimal::of((string) $posted->grand_total_currency)->toScale(2, RoundingMode::HALF_UP),
            'currency' => (string) $posted->currency_code,
            'posted_at' => $posted->posted_at ? $posted->posted_at->format('Y-m-d\TH:i:s\Z') : now()->format('Y-m-d\TH:i:s\Z'),
        ], 201, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }
}
