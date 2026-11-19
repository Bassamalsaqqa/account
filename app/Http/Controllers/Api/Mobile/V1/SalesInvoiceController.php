<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Application\Commands\SalesInvoiceCommands;
use App\Domain\Sales\Calculators\SalesDocumentTotalsCalculator;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Money\CurrencyMinorUnitsResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class SalesInvoiceController extends Controller
{
    /**
     * GET /api/mobile/v1/companies/{companyId}/sales/invoices
     */
    public function index(Request $request, string $companyId, CurrencyMinorUnitsResolver $minorUnitsResolver): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        setPermissionsTeamId($company->id);

        if (! $user->hasPermissionTo('sales.invoice.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required sales.invoice.view permission.',
                status: 403
            );
        }

        $limit = min((int) ($request->query('limit', 30)), 100);
        if ($limit < 1) {
            $limit = 30;
        }

        $query = SalesInvoice::where('company_id', $company->id)
            ->with(['customer' => fn ($q) => $q->where('company_id', $company->id)]);

        // Filter by invoice status or payment status
        $statusFilter = $request->query('status');
        if ($statusFilter !== null && is_string($statusFilter) && trim($statusFilter) !== '') {
            $statusUpper = strtoupper(trim($statusFilter));
            if (in_array($statusUpper, ['DRAFT', 'POSTED', 'VOID'], true)) {
                $query->where('status', strtolower($statusUpper));
            } elseif (in_array($statusUpper, ['PAID_CASH', 'PAID', 'PARTIAL', 'ON_CREDIT'], true)) {
                $allocatedSql = '(SELECT COALESCE(SUM(cpa.allocated_amount), 0) FROM customer_payment_allocations cpa INNER JOIN customer_payments cp ON cp.id = cpa.customer_payment_id LEFT JOIN customer_payment_application_events cpae ON cpae.id = cpa.application_event_id WHERE cpa.sales_invoice_id = sales_invoices.id AND cp.is_reversed = 0 AND (cpa.application_event_id IS NULL OR (cpae.applied_at IS NOT NULL AND cpae.reversed_at IS NULL)))';
                $returnedSql = '(SELECT COALESCE(SUM(sr.grand_total_currency), 0) FROM sales_returns sr WHERE sr.sales_invoice_id = sales_invoices.id AND sr.status = "posted")';
                $hasCashSql = 'EXISTS (SELECT 1 FROM customer_payment_allocations cpa INNER JOIN customer_payments cp ON cp.id = cpa.customer_payment_id LEFT JOIN customer_payment_application_events cpae ON cpae.id = cpa.application_event_id WHERE cpa.sales_invoice_id = sales_invoices.id AND cp.is_reversed = 0 AND cp.payment_method = "cash" AND (cpa.application_event_id IS NULL OR (cpae.applied_at IS NOT NULL AND cpae.reversed_at IS NULL)))';

                $query->where('status', SalesInvoice::STATUS_POSTED);

                if ($statusUpper === 'PAID_CASH') {
                    $query->whereRaw("({$allocatedSql} + {$returnedSql}) >= sales_invoices.grand_total_currency")
                        ->whereRaw($hasCashSql);
                } elseif ($statusUpper === 'PAID') {
                    $query->whereRaw("({$allocatedSql} + {$returnedSql}) >= sales_invoices.grand_total_currency");
                } elseif ($statusUpper === 'PARTIAL') {
                    $query->whereRaw("{$allocatedSql} > 0")
                        ->whereRaw("({$allocatedSql} + {$returnedSql}) < sales_invoices.grand_total_currency");
                } elseif ($statusUpper === 'ON_CREDIT') {
                    $query->whereRaw("{$allocatedSql} = 0")
                        ->whereRaw("{$returnedSql} < sales_invoices.grand_total_currency");
                }
            }
        }

        // Search
        $search = $request->query('search');
        if ($search !== null && is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($q) use ($term): void {
                $q->where('invoice_number', 'like', "%{$term}%")
                    ->orWhere('public_id', 'like', "%{$term}%")
                    ->orWhereHas('customer', function ($cq) use ($term): void {
                        $cq->where('name_ar', 'like', "%{$term}%")
                            ->orWhere('name_en', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    });
            });
        }

        // Customer filter
        $customerPublicId = $request->query('customer_id');
        if ($customerPublicId !== null && is_string($customerPublicId) && trim($customerPublicId) !== '') {
            $trimmedCustomerPublicId = trim($customerPublicId);
            if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $trimmedCustomerPublicId) === 1) {
                $customer = Customer::where('company_id', $company->id)
                    ->where('public_id', $trimmedCustomerPublicId)
                    ->first();
                if ($customer !== null) {
                    $query->where('customer_id', $customer->id);
                } else {
                    $query->whereRaw('1 = 0');
                }
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // Date range filters
        $dateFrom = $request->query('date_from');
        if ($dateFrom !== null && is_string($dateFrom) && trim($dateFrom) !== '') {
            $query->whereDate('issue_date', '>=', trim($dateFrom));
        }
        $dateTo = $request->query('date_to');
        if ($dateTo !== null && is_string($dateTo) && trim($dateTo) !== '') {
            $query->whereDate('issue_date', '<=', trim($dateTo));
        }

        $records = $query->orderBy('id', 'desc')
            ->limit($limit + 1)
            ->get();

        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
        }

        $invoices = $records->map(function (SalesInvoice $inv) use ($company, $minorUnitsResolver): array {
            $cmu = $minorUnitsResolver->resolve((string) $inv->currency_code, $company);
            $financialSummary = $this->resolveFinancialSummaryAndPaymentStatus($inv);
            $paid = $financialSummary['paid'];
            $grandTotal = $financialSummary['grand_total'];
            $remaining = $financialSummary['remaining'];
            $paymentStatus = $financialSummary['payment_status'];

            $date = $inv->issue_date instanceof Carbon
                ? $inv->issue_date->format('Y-m-d')
                : substr((string) $inv->issue_date, 0, 10);

            $customerName = $this->resolveCustomerName($inv, $inv->customer);

            return [
                'id' => (string) $inv->public_id,
                'invoice_number' => (string) ($inv->invoice_number ?? $inv->public_id),
                'status' => (string) $inv->status,
                'customer_id' => (string) $inv->customer->public_id,
                'customer_name' => $customerName,
                'date' => $date,
                'subtotal' => (string) BigDecimal::of((string) ($inv->subtotal_currency ?? '0.000000'))->toScale($cmu, RoundingMode::HALF_UP),
                'discount' => (string) BigDecimal::of((string) ($inv->discount_total_currency ?? '0.000000'))->toScale($cmu, RoundingMode::HALF_UP),
                'tax' => (string) BigDecimal::of((string) ($inv->tax_total_currency ?? '0.000000'))->toScale($cmu, RoundingMode::HALF_UP),
                'total' => (string) $grandTotal->toScale($cmu, RoundingMode::HALF_UP),
                'paid' => (string) $paid->toScale($cmu, RoundingMode::HALF_UP),
                'remaining' => (string) $remaining->toScale($cmu, RoundingMode::HALF_UP),
                'currency' => (string) $inv->currency_code,
                'currency_minor_units' => $cmu,
                'payment_status' => $paymentStatus,
                'created_at' => $inv->created_at?->format('Y-m-d\TH:i:s\Z'),
            ];
        })->values()->all();

        return response()->json([
            'company_id' => $company->public_id,
            'count' => count($invoices),
            'has_more' => $hasMore,
            'invoices' => $invoices,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * GET /api/mobile/v1/companies/{companyId}/sales/invoices/{publicId}
     */
    public function show(Request $request, string $companyId, string $publicId, CurrencyMinorUnitsResolver $minorUnitsResolver): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        setPermissionsTeamId($company->id);

        if (! $user->hasPermissionTo('sales.invoice.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required sales.invoice.view permission.',
                status: 403
            );
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $publicId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested sales invoice was not found.',
                status: 404
            );
        }

        $invoice = SalesInvoice::where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->with(['customer', 'poster', 'lines.product', 'lines.productUnit.unit', 'allocations.customerPayment'])
            ->first();

        if ($invoice === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested sales invoice was not found.',
                status: 404
            );
        }

        $cmu = $minorUnitsResolver->resolve((string) $invoice->currency_code, $company);
        $financialSummary = $this->resolveFinancialSummaryAndPaymentStatus($invoice);
        $paid = $financialSummary['paid'];
        $grandTotal = $financialSummary['grand_total'];
        $remaining = $financialSummary['remaining'];
        $paymentStatus = $financialSummary['payment_status'];

        $isPosted = $invoice->isPosted() || $invoice->isVoid();

        $linesPayload = $invoice->lines->map(function (SalesInvoiceLine $line) use ($invoice, $cmu, $isPosted): array {
            if ($isPosted) {
                $productName = ($invoice->document_locale ?? 'ar') === 'en'
                    ? ($line->product_name_en ?? $line->product_name_ar ?? $line->item_description)
                    : ($line->product_name_ar ?? $line->product_name_en ?? $line->item_description);
                $unitName = ($invoice->document_locale ?? 'ar') === 'en'
                    ? ($line->unit_name_en ?? $line->unit_name_ar ?? '')
                    : ($line->unit_name_ar ?? $line->unit_name_en ?? '');
            } else {
                $productName = $line->product ? (string) $line->product->displayName() : (string) $line->item_description;
                $unitName = $line->unit_name_ar ?? $line->unit_name_en ?? ($line->productUnit?->unit ? (string) $line->productUnit->unit->displayName() : '');
            }

            return [
                'id' => (string) $line->public_id,
                'product_id' => $line->product ? (string) $line->product->public_id : null,
                'product_name' => (string) $productName,
                'unit' => (string) $unitName,
                'quantity' => (string) BigDecimal::of((string) $line->quantity),
                'unit_price' => (string) BigDecimal::of((string) $line->unit_price),
                'line_total' => (string) BigDecimal::of((string) $line->line_total)->toScale($cmu, RoundingMode::HALF_UP),
                'tax_rate' => $line->tax_rate_snapshot !== null ? (string) BigDecimal::of((string) $line->tax_rate_snapshot) : '0.00',
                'discount' => (string) BigDecimal::of((string) ($line->line_discount ?? '0.000000'))->toScale($cmu, RoundingMode::HALF_UP),
            ];
        })->values()->all();

        // Stored immutable snapshots for thermal receipt layout
        if ($isPosted && is_array($invoice->company_snapshot)) {
            $cs = $invoice->company_snapshot;
            $storeNameAr = ! empty($cs['name_ar']) ? (string) $cs['name_ar'] : null;
            $storeNameEn = ! empty($cs['name_en']) ? (string) $cs['name_en'] : null;
            $cityAr = ! empty($cs['city_ar']) ? (string) $cs['city_ar'] : (! empty($cs['city']) ? (string) $cs['city'] : null);
            $cityEn = ! empty($cs['city_en']) ? (string) $cs['city_en'] : null;
            $phone = ! empty($cs['phone']) ? (string) $cs['phone'] : null;
            $taxNumber = ! empty($cs['tax_number']) ? (string) $cs['tax_number'] : null;
        } else {
            $storeNameAr = (string) ($company->name_ar ?? $company->displayName());
            $storeNameEn = (string) ($company->name_en ?? $company->displayName());
            $cityAr = null;
            $cityEn = null;
            $phone = $company->phone !== null ? (string) $company->phone : null;
            $taxNumber = $company->tax_number !== null ? (string) $company->tax_number : null;
        }

        $customerName = $this->resolveCustomerName($invoice, $invoice->customer);

        $dateTime = $invoice->posted_at
            ? $invoice->posted_at->format('Y-m-d H:i')
            : ($invoice->issue_date instanceof Carbon ? $invoice->issue_date->format('Y-m-d H:i') : substr((string) $invoice->issue_date, 0, 16));

        $cashier = null;
        if ($isPosted) {
            $cashier = $invoice->poster?->name;
        } else {
            $cashier = (string) $user->name;
        }

        $thermalReceipt = [
            'store_name_ar' => $storeNameAr,
            'store_name_en' => $storeNameEn,
            'city_ar' => $cityAr,
            'city_en' => $cityEn,
            'phone' => $phone,
            'tax_number' => $taxNumber,
            'invoice_number' => (string) ($invoice->invoice_number ?? $invoice->public_id),
            'date_time' => $dateTime,
            'cashier' => $cashier,
            'customer_name' => $customerName,
            'subtotal' => (string) BigDecimal::of((string) ($invoice->subtotal_currency ?? '0.000000'))->toScale($cmu, RoundingMode::HALF_UP),
            'discount' => (string) BigDecimal::of((string) ($invoice->discount_total_currency ?? '0.000000'))->toScale($cmu, RoundingMode::HALF_UP),
            'tax' => (string) BigDecimal::of((string) ($invoice->tax_total_currency ?? '0.000000'))->toScale($cmu, RoundingMode::HALF_UP),
            'grand_total' => (string) $grandTotal->toScale($cmu, RoundingMode::HALF_UP),
            'paid_amount' => (string) $paid->toScale($cmu, RoundingMode::HALF_UP),
            'remaining_balance' => (string) $remaining->toScale($cmu, RoundingMode::HALF_UP),
            'currency' => (string) $invoice->currency_code,
            'lines' => $linesPayload,
        ];

        return response()->json([
            'id' => (string) $invoice->public_id,
            'company_id' => (string) $company->public_id,
            'invoice_number' => (string) ($invoice->invoice_number ?? $invoice->public_id),
            'status' => (string) $invoice->status,
            'payment_status' => $paymentStatus,
            'customer_id' => (string) $invoice->customer->public_id,
            'customer_name' => $customerName,
            'date' => $invoice->issue_date instanceof Carbon ? $invoice->issue_date->format('Y-m-d') : substr((string) $invoice->issue_date, 0, 10),
            'currency' => (string) $invoice->currency_code,
            'currency_minor_units' => $cmu,
            'subtotal' => (string) BigDecimal::of((string) ($invoice->subtotal_currency ?? '0.000000'))->toScale($cmu, RoundingMode::HALF_UP),
            'discount' => (string) BigDecimal::of((string) ($invoice->discount_total_currency ?? '0.000000'))->toScale($cmu, RoundingMode::HALF_UP),
            'tax' => (string) BigDecimal::of((string) ($invoice->tax_total_currency ?? '0.000000'))->toScale($cmu, RoundingMode::HALF_UP),
            'total' => (string) $grandTotal->toScale($cmu, RoundingMode::HALF_UP),
            'paid' => (string) $paid->toScale($cmu, RoundingMode::HALF_UP),
            'remaining' => (string) $remaining->toScale($cmu, RoundingMode::HALF_UP),
            'lines' => $linesPayload,
            'thermal_receipt' => $thermalReceipt,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * @return array{paid: BigDecimal, grand_total: BigDecimal, remaining: BigDecimal, payment_status: string}
     */
    private function resolveFinancialSummaryAndPaymentStatus(SalesInvoice $invoice): array
    {
        if (! $invoice->isPosted()) {
            $grandTotal = BigDecimal::of((string) $invoice->grand_total_currency);

            return [
                'paid' => BigDecimal::zero(),
                'grand_total' => $grandTotal,
                'remaining' => $invoice->isVoid() ? BigDecimal::zero() : $grandTotal,
                'payment_status' => 'ON_CREDIT',
            ];
        }

        $summary = $invoice->calculateFinancialSummary();
        $paid = $summary['paid'];
        $grandTotal = BigDecimal::of((string) $invoice->grand_total_currency);
        $remaining = $summary['outstanding'];

        $isPaidInFull = $remaining->isZero() || $remaining->isNegative();
        $isPartiallyPaid = $paid->isPositive() && $remaining->isPositive();

        if ($isPaidInFull) {
            $hasCashPayment = $invoice->paymentAllocations()
                ->active()
                ->whereHas('customerPayment', fn ($q) => $q->where('payment_method', CustomerPayment::METHOD_CASH))
                ->exists();
            $paymentStatus = $hasCashPayment ? 'PAID_CASH' : 'PAID';
        } elseif ($isPartiallyPaid) {
            $paymentStatus = 'PARTIAL';
        } else {
            $paymentStatus = 'ON_CREDIT';
        }

        return [
            'paid' => $paid,
            'grand_total' => $grandTotal,
            'remaining' => $remaining,
            'payment_status' => $paymentStatus,
        ];
    }

    private function resolveCustomerName(SalesInvoice $invoice, ?Customer $customer = null): ?string
    {
        if ($invoice->isPosted() || $invoice->isVoid()) {
            $cs = $invoice->customer_snapshot;
            if (is_array($cs)) {
                $isEn = ($invoice->document_locale ?? 'ar') === 'en';
                $name = $isEn
                    ? ($cs['name_en'] ?? $cs['name_ar'] ?? $cs['business_name_en'] ?? $cs['business_name_ar'] ?? $cs['business_name'] ?? null)
                    : ($cs['name_ar'] ?? $cs['name_en'] ?? $cs['business_name_ar'] ?? $cs['business_name_en'] ?? $cs['business_name'] ?? null);

                if ($name !== null && trim((string) $name) !== '') {
                    return trim((string) $name);
                }
            }

            return null;
        }

        if ($customer !== null) {
            return (string) $customer->displayName();
        }

        return (string) $invoice->customer->displayName();
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/sales/invoices/draft
     */
    public function storeDraft(Request $request, string $companyId, SalesInvoiceCommands $commands): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        setPermissionsTeamId($company->id);

        if (! $user->hasPermissionTo('sales.invoice.create')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required sales.invoice.create permission.',
                status: 403
            );
        }

        $rawKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        $idempotencyKey = is_string($rawKey) ? trim($rawKey) : '';
        if ($idempotencyKey === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for this operation.',
                status: 422
            );
        }

        $payload = $request->json()->all();
        if (empty($payload['lines']) || ! is_array($payload['lines'])) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'The sales invoice must contain at least one line item in [lines].',
                status: 422
            );
        }

        try {
            $invoice = $commands->create($company, $user, $payload, $idempotencyKey);
        } catch (HttpExceptionInterface $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: $e->getStatusCode() === 409 ? 'CONFLICT' : 'VALIDATION_FAILED',
                message: $e->getMessage(),
                status: $e->getStatusCode()
            );
        } catch (InvalidArgumentException $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: $e->getMessage(),
                status: 422
            );
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'INTERNAL_ERROR',
                message: 'An error occurred while creating draft: '.$e->getMessage(),
                status: 500
            );
        }

        return response()->json([
            'id' => (string) $invoice->public_id,
            'status' => (string) $invoice->status,
            'total' => (string) BigDecimal::of((string) $invoice->grand_total_currency)->toScale(2, RoundingMode::HALF_UP),
            'currency' => (string) $invoice->currency_code,
            'created_at' => $invoice->created_at?->format('Y-m-d\TH:i:s\Z'),
        ], 201, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * PUT /api/mobile/v1/companies/{companyId}/sales/invoices/{publicId}
     */
    public function updateDraft(Request $request, string $companyId, string $publicId, SalesInvoiceCommands $commands): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        setPermissionsTeamId($company->id);

        if (! $user->hasPermissionTo('sales.invoice.create')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required sales.invoice.create permission.',
                status: 403
            );
        }

        $rawKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        $idempotencyKey = is_string($rawKey) ? trim($rawKey) : '';
        if ($idempotencyKey === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for this operation.',
                status: 422
            );
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $publicId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested sales invoice was not found.',
                status: 404
            );
        }

        $invoice = SalesInvoice::where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->first();

        if ($invoice === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested sales invoice was not found.',
                status: 404
            );
        }

        $payload = $request->json()->all();

        try {
            $updated = $commands->update($invoice, $user, $payload, $idempotencyKey);
        } catch (HttpExceptionInterface $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: $e->getStatusCode() === 409 ? 'CONFLICT' : 'VALIDATION_FAILED',
                message: $e->getMessage(),
                status: $e->getStatusCode()
            );
        } catch (InvalidArgumentException $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: $e->getMessage(),
                status: 422
            );
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'INTERNAL_ERROR',
                message: 'An error occurred while updating draft: '.$e->getMessage(),
                status: 500
            );
        }

        return response()->json([
            'id' => (string) $updated->public_id,
            'status' => (string) $updated->status,
            'total' => (string) BigDecimal::of((string) $updated->grand_total_currency)->toScale(2, RoundingMode::HALF_UP),
            'currency' => (string) $updated->currency_code,
            'updated_at' => $updated->updated_at?->format('Y-m-d\TH:i:s\Z'),
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/sales/invoices/preview
     */
    public function preview(
        Request $request,
        string $companyId,
        SalesLineCalculator $lineCalculator,
        SalesDocumentTotalsCalculator $totalsCalculator,
        CurrencyMinorUnitsResolver $minorUnitsResolver
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        setPermissionsTeamId($company->id);

        if (! $user->hasPermissionTo('sales.invoice.create')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required sales.invoice.create permission.',
                status: 403
            );
        }

        $payload = $request->json()->all();
        $lines = $payload['lines'] ?? [];
        if (! is_array($lines) || empty($lines)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Lines must be a non-empty array.',
                status: 422
            );
        }

        $currency = (string) ($payload['currency_code'] ?? $company->base_currency_code);
        $cmu = $minorUnitsResolver->resolve($currency, $company);
        $baseCmu = $minorUnitsResolver->resolve((string) $company->base_currency_code, $company);

        $lineResults = [];
        $previewLines = [];

        foreach ($lines as $lineData) {
            $productPublicId = $lineData['product_id'] ?? null;
            if ($productPublicId === null || ! is_string($productPublicId)) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Each line must specify a product_id.',
                    status: 422
                );
            }

            $product = Product::where('company_id', $company->id)
                ->where('public_id', $productPublicId)
                ->first();

            if ($product === null) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: "Product [{$productPublicId}] was not found.",
                    status: 422
                );
            }

            $qty = $lineData['quantity'] ?? '1';
            $unitPrice = $lineData['unit_price'] ?? $product->default_sale_price_base;
            if ($unitPrice === null) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: "Product [{$productPublicId}] is unpriced and unit_price was not supplied.",
                    status: 422
                );
            }

            $taxRate = null;
            if (isset($lineData['tax_rate']) && is_numeric($lineData['tax_rate'])) {
                $taxRate = (string) $lineData['tax_rate'];
            } elseif (! empty($lineData['tax_rate_id']) && is_string($lineData['tax_rate_id'])) {
                $tr = TaxRate::where('company_id', $company->id)->where('public_id', $lineData['tax_rate_id'])->first();
                if ($tr !== null) {
                    $taxRate = (string) $tr->rate;
                }
            }

            $isTaxInclusive = (bool) ($lineData['is_tax_inclusive'] ?? false);
            $discountVal = $lineData['discount_amount'] ?? '0';

            try {
                $calcInput = new SalesLineCalculationInput(
                    quantity: (string) $qty,
                    unitPrice: (string) $unitPrice,
                    discountType: 'fixed',
                    discountValue: (string) $discountVal,
                    taxRate: $taxRate,
                    taxInclusive: $isTaxInclusive,
                    currencyMinorUnits: $cmu,
                    exchangeRate: '1.0000000000',
                    baseCurrencyMinorUnits: $baseCmu
                );
                $calcResult = $lineCalculator->calculate($calcInput);
                $lineResults[] = $calcResult;

                $previewLines[] = [
                    'product_id' => (string) $product->public_id,
                    'product_name' => (string) $product->displayName(),
                    'unit_id' => $lineData['unit_id'] ?? null,
                    'quantity' => (string) BigDecimal::of((string) $qty)->toScale(4, RoundingMode::HALF_UP),
                    'unit_price' => (string) BigDecimal::of((string) $unitPrice)->toScale(2, RoundingMode::HALF_UP),
                    'line_subtotal' => (string) $calcResult->subtotal->toScale(2, RoundingMode::HALF_UP),
                    'tax_amount' => (string) $calcResult->tax->toScale(2, RoundingMode::HALF_UP),
                    'line_total' => (string) $calcResult->total->toScale(2, RoundingMode::HALF_UP),
                ];
            } catch (Throwable $e) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Sales line calculation error: '.$e->getMessage(),
                    status: 422
                );
            }
        }

        $totals = $totalsCalculator->calculate($lineResults);

        return response()->json([
            'currency' => $currency,
            'currency_minor_units' => $cmu,
            'subtotal' => (string) $totals->subtotalCurrency->toScale(2, RoundingMode::HALF_UP),
            'tax_total' => (string) $totals->taxTotalCurrency->toScale(2, RoundingMode::HALF_UP),
            'discount_total' => (string) $totals->discountTotalCurrency->toScale(2, RoundingMode::HALF_UP),
            'grand_total' => (string) $totals->grandTotalCurrency->toScale(2, RoundingMode::HALF_UP),
            'lines' => $previewLines,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/sales/invoices/{publicId}/execute
     */
    public function execute(Request $request, string $companyId, string $publicId): JsonResponse
    {
        return MobileApiBoundary::buildErrorResponse(
            code: 'DURABLE_EVENT_REQUIRED',
            message: 'Submit the original sale intent through the native V2 event endpoint.',
            status: 410
        );
    }
}
