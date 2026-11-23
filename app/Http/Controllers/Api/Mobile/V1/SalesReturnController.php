<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Sales\ExecuteNativeSalesReturnAction;
use App\Actions\Sales\SalesReturnInput;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\SalesReturnLine;
use App\Models\User;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Sales\ReceivableBookValue;
use App\Services\Sales\SalesReturnAmounts;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
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

        if (! $user->hasPermissionTo('sales.return.view') && ! $user->hasPermissionTo('sales.invoice.view') && ! $user->hasPermissionTo('sales.return.create')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required sales.return.view or sales.return.create permission.',
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

        $requestedLines = $request->input('lines', []);
        if (! is_array($requestedLines) || empty($requestedLines)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Lines must be a non-empty array of items to return.',
                status: 422
            );
        }

        try {
            return DB::transaction(function () use ($company, $user, $invoicePublicId, $requestedLines): JsonResponse {
                $invoice = SalesInvoice::where('company_id', $company->id)
                    ->where('public_id', $invoicePublicId)
                    ->with(['lines.product', 'warehouse'])
                    ->lockForUpdate()
                    ->first();

                if ($invoice === null) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'NOT_FOUND',
                        message: 'The original sales invoice was not found.',
                        status: 404
                    );
                }

                if (! $invoice->isPosted()) {
                    throw new \InvalidArgumentException('Original invoice must be posted.');
                }

                // Fresh-only return history authorization check and calculation share the EXACT same snapshot under lock
                $invoiceLineIds = $invoice->lines->pluck('id');
                $priorReturns = SalesReturnLine::where('company_id', $company->id)
                    ->whereIn('sales_invoice_line_id', $invoiceLineIds)
                    ->whereHas('salesReturn', fn ($q) => $q->where('status', 'posted'))
                    ->get()
                    ->groupBy('sales_invoice_line_id');

                if (! $user->hasPermissionTo('sales.return.view') && $priorReturns->isNotEmpty()) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'FORBIDDEN',
                        message: 'Action is unauthorized. Missing required sales.return.view permission to inspect return history.',
                        status: 403
                    );
                }

                $restockItems = [];
                $totalReturnAmount = BigDecimal::zero();
                $restockWarehouseId = $invoice->warehouse ? (string) $invoice->warehouse->public_id : (string) $invoice->warehouse_id;

                $seen = [];
                $taxComponents = [];
                $fx = ExchangeRate::from($invoice->exchange_rate);
                foreach (SalesReturnInput::normalize($requestedLines) as $input) {
                    $original = SalesReturnInput::resolveLine($invoice, $input);
                    if (isset($seen[$original->id])) {
                        throw new \InvalidArgumentException('Repeated original invoice line.');
                    }
                    $seen[$original->id] = true;
                    $prior = $priorReturns->get($original->id, collect());
                    $quantity = BigDecimal::of($input['quantity']);
                    $amounts = app(SalesReturnAmounts::class)->calculateLine($original, $quantity, $prior, (string) $invoice->currency_code);
                    $totalReturnAmount = $totalReturnAmount->plus($amounts['total']);
                    $tax = $amounts['tax'];
                    if (! $tax->isZero()) {
                        $taxAccId = (int) $original->sales_tax_account_id;
                        $taxComponents[$taxAccId] ??= ['pos' => BigDecimal::zero(), 'neg' => BigDecimal::zero()];
                        if ($tax->isPositive()) {
                            $taxComponents[$taxAccId]['pos'] = $taxComponents[$taxAccId]['pos']->plus($tax);
                        } else {
                            $taxComponents[$taxAccId]['neg'] = $taxComponents[$taxAccId]['neg']->plus($tax);
                        }
                    }
                    $cmu = app(CurrencyMinorUnitsResolver::class)->resolve((string) $invoice->currency_code, $company);
                    $restockItems[] = [
                        'sales_invoice_line_id' => (string) $original->public_id,
                        'product_id' => $original->product?->public_id,
                        'product_name' => $original->product ? (string) $original->product->displayName() : (string) $original->item_description,
                        'quantity_returned' => (string) $quantity,
                        'unit_price' => (string) BigDecimal::of((string) $original->unit_price)->toScale($cmu, RoundingMode::HALF_UP),
                        'unit_price_exact' => (string) BigDecimal::of((string) $original->unit_price),
                        'line_total' => (string) $amounts['total']->toScale($cmu, RoundingMode::HALF_UP),
                        'line_total_exact' => (string) $amounts['total'],
                        'restock_warehouse_id' => $restockWarehouseId,
                    ];
                }

                $taxTotalCurrency = BigDecimal::zero();
                $taxTotalBase = BigDecimal::zero();
                foreach ($taxComponents as $comp) {
                    $taxTotalCurrency = $taxTotalCurrency->plus($comp['pos']->plus($comp['neg']));
                    $posB = $fx->toBase(MoneyAmount::from($comp['pos']))->getAmount();
                    $negB = $fx->toBase(MoneyAmount::from($comp['neg']->abs()))->getAmount();
                    $taxTotalBase = $taxTotalBase->plus($posB->minus($negB));
                }
                $reliefBase = app(ReceivableBookValue::class)->relief($invoice, $totalReturnAmount);
                $subtotalCurrency = $totalReturnAmount->minus($taxTotalCurrency);
                $subtotalBase = $reliefBase->minus($taxTotalBase);
                app(SalesReturnAmounts::class)->validateRepresentability($fx, $totalReturnAmount, $reliefBase, $taxComponents, $subtotalCurrency, $subtotalBase);

                $cmu = app(CurrencyMinorUnitsResolver::class)->resolve((string) $invoice->currency_code, $company);

                return response()->json([
                    'sales_invoice_id' => (string) $invoice->public_id,
                    'invoice_number' => (string) ($invoice->invoice_number ?? $invoice->public_id),
                    'currency' => (string) $invoice->currency_code,
                    'currency_minor_units' => $cmu,
                    'total_return_amount' => (string) $totalReturnAmount->toScale($cmu, RoundingMode::HALF_UP),
                    'total_return_amount_exact' => (string) $totalReturnAmount,
                    'lines' => $restockItems,
                ], 200, [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'VALIDATION_FAILED', message: $e->getMessage(), status: 422);
        }
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/sales/returns
     * Execute sales return draft and post atomically.
     */
    public function store(
        Request $request,
        string $companyId,
        ExecuteNativeSalesReturnAction $executeReturnAction
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
            $result = $executeReturnAction->execute($company, $user, $invoice, $lines, $idempotencyKey,
                $request->attributes->get('mobile_token'), $request->attributes->get('mobile_device'));
        } catch (AuthenticationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'UNAUTHENTICATED', message: $e->getMessage(), status: 401);
        } catch (AuthorizationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'FORBIDDEN', message: $e->getMessage(), status: 403);
        } catch (IdempotencyConflictException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'IDEMPOTENCY_KEY_CONFLICT', message: $e->getMessage(), status: 409);
        } catch (\InvalidArgumentException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'VALIDATION_FAILED', message: $e->getMessage(), status: 422);
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Sales return failed: '.$e->getMessage(),
                status: 422
            );
        }

        $headers = ['Idempotency-Key' => trim($idempotencyKey)];
        if ($result['outcome'] === 'replay') {
            $headers['X-Idempotent-Replay'] = 'true';
        }

        return response()->json($result['snapshot'], $result['status'], $headers + [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }
}
