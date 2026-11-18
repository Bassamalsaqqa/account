<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SalesReturnController extends Controller
{
    /**
     * POST /api/mobile/v1/companies/{companyId}/sales/returns/preview
     * Preview return restock impact and credit note/cash refund breakdown.
     */
    public function preview(Request $request, string $companyId): JsonResponse
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

        if (! $user->hasPermissionTo('sales.return.view') && ! $user->hasPermissionTo('sales.invoice.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required sales.return.view permission.',
                status: 403
            );
        }

        $invoicePublicId = $request->input('sales_invoice_id');
        if (! is_string($invoicePublicId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $invoicePublicId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The original sales invoice was not found.',
                status: 404
            );
        }

        $invoice = SalesInvoice::where('company_id', $company->id)
            ->where('public_id', $invoicePublicId)
            ->with(['lines.product', 'warehouse'])
            ->first();

        if ($invoice === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The original sales invoice was not found.',
                status: 404
            );
        }

        $requestedLines = $request->input('lines', []);
        if (! is_array($requestedLines) || empty($requestedLines)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Lines must be a non-empty array of items to return.',
                status: 422
            );
        }

        $restockItems = [];
        $totalReturnAmount = BigDecimal::zero();
        $restockWarehouseId = $invoice->warehouse ? (string) $invoice->warehouse->public_id : (string) $invoice->warehouse_id;

        foreach ($requestedLines as $reqLine) {
            $productId = $reqLine['product_id'] ?? null;
            $quantity = BigDecimal::of((string) ($reqLine['quantity'] ?? '1'));

            // Find matching original invoice line
            $origLine = $invoice->lines->first(function ($l) use ($productId) {
                return $l->product && (string) $l->product->public_id === (string) $productId;
            });

            if ($origLine !== null) {
                $unitPrice = BigDecimal::of((string) $origLine->unit_price);
                $lineTotal = $quantity->multipliedBy($unitPrice);
                $totalReturnAmount = $totalReturnAmount->plus($lineTotal);

                $restockItems[] = [
                    'product_id' => $productId,
                    'product_name' => $origLine->product ? (string) $origLine->product->displayName() : (string) $origLine->item_description,
                    'quantity_returned' => (string) $quantity,
                    'unit_price' => (string) $unitPrice->toScale(2, RoundingMode::HALF_UP),
                    'line_total' => (string) $lineTotal->toScale(2, RoundingMode::HALF_UP),
                    'restock_warehouse_id' => $restockWarehouseId,
                ];
            }
        }

        return response()->json([
            'sales_invoice_id' => (string) $invoice->public_id,
            'invoice_number' => (string) ($invoice->invoice_number ?? $invoice->public_id),
            'currency' => (string) $invoice->currency_code,
            'total_return_amount' => (string) $totalReturnAmount->toScale(2, RoundingMode::HALF_UP),
            'lines' => $restockItems,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/sales/returns
     * Execute sales return draft and post atomically.
     */
    public function store(
        Request $request,
        string $companyId,
        CreateSalesReturnDraftAction $createDraftAction,
        PostSalesReturnAction $postReturnAction
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for sales returns.',
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

        if (! $user->hasPermissionTo('sales.return.create') || ! $user->hasPermissionTo('sales.return.post')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required sales.return.create or sales.return.post permission.',
                status: 403
            );
        }

        $invoicePublicId = $request->input('sales_invoice_id');
        if (! is_string($invoicePublicId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $invoicePublicId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The original sales invoice was not found.',
                status: 404
            );
        }

        $invoice = SalesInvoice::where('company_id', $company->id)
            ->where('public_id', $invoicePublicId)
            ->with(['lines.product'])
            ->first();

        if ($invoice === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The original sales invoice was not found.',
                status: 404
            );
        }

        $lines = $request->input('lines', []);
        if (! is_array($lines) || empty($lines)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Lines must be a non-empty array.',
                status: 422
            );
        }

        try {
            $postedReturn = DB::transaction(function () use ($company, $user, $invoice, $lines, $createDraftAction, $postReturnAction) {
                app(CompanyContext::class)->setCompany($company, $user);

                $draftLines = [];
                foreach ($lines as $lineData) {
                    $productId = $lineData['product_id'] ?? null;
                    $origLine = $invoice->lines->first(function ($l) use ($productId) {
                        return $l->product && (string) $l->product->public_id === (string) $productId;
                    });
                    if ($origLine !== null) {
                        $draftLines[] = [
                            'sales_invoice_line_id' => (int) $origLine->id,
                            'quantity' => (string) ($lineData['quantity'] ?? '1'),
                        ];
                    }
                }

                if (empty($draftLines)) {
                    throw new \InvalidArgumentException('No valid invoice lines matched the return request.');
                }

                $draftData = [
                    'sales_invoice_id' => (int) $invoice->id,
                    'issue_date' => now()->format('Y-m-d'),
                    'reason' => 'مرتجع مبيعات من تطبيق الجوال',
                    'lines' => $draftLines,
                ];

                $draft = $createDraftAction->execute($company, $user, $draftData);

                return $postReturnAction->execute($draft, $user);
            });
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Sales return failed: '.$e->getMessage(),
                status: 422
            );
        }

        $postedAt = $postedReturn->posted_at instanceof Carbon
            ? $postedReturn->posted_at->format('Y-m-d\TH:i:s\Z')
            : ($postedReturn->issue_date instanceof Carbon ? $postedReturn->issue_date->format('Y-m-d\TH:i:s\Z') : now()->format('Y-m-d\TH:i:s\Z'));

        return response()->json([
            'id' => (string) $postedReturn->public_id,
            'return_number' => (string) ($postedReturn->return_number ?? $postedReturn->public_id),
            'status' => (string) $postedReturn->status,
            'total_amount' => (string) BigDecimal::of((string) $postedReturn->grand_total_currency)->toScale(2, RoundingMode::HALF_UP),
            'currency' => (string) $postedReturn->currency_code,
            'posted_at' => $postedAt,
        ], 201, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }
}
