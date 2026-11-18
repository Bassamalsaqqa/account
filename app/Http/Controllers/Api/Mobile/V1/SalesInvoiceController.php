<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\UpdateSalesInvoiceDraftAction;
use App\Application\Commands\SalesInvoiceCommands;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\Customer;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class SalesInvoiceController extends Controller
{
    /**
     * GET /api/mobile/v1/companies/{companyId}/sales/invoices
     */
    public function index(Request $request, string $companyId): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

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
        if ($statusFilter !== null && is_string($statusFilter)) {
            $statusUpper = strtoupper(trim($statusFilter));
            if (in_array($statusUpper, ['DRAFT', 'POSTED', 'VOID'], true)) {
                $query->where('status', strtolower($statusUpper));
            } elseif ($statusUpper === 'PAID_CASH') {
                $query->where('status', SalesInvoice::STATUS_POSTED)
                    ->whereRaw('paid_amount >= grand_total_currency');
            } elseif ($statusUpper === 'PARTIAL') {
                $query->where('status', SalesInvoice::STATUS_POSTED)
                    ->whereRaw('paid_amount > 0 AND paid_amount < grand_total_currency');
            } elseif ($statusUpper === 'ON_CREDIT') {
                $query->where('status', SalesInvoice::STATUS_POSTED)
                    ->where(function ($q): void {
                        $q->whereNull('paid_amount')->orWhere('paid_amount', '<=', 0);
                    });
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
        if ($customerPublicId !== null && is_string($customerPublicId)) {
            $customer = Customer::where('company_id', $company->id)
                ->where('public_id', $customerPublicId)
                ->first();
            if ($customer !== null) {
                $query->where('customer_id', $customer->id);
            }
        }

        // Date range filters
        if ($request->has('date_from')) {
            $query->whereDate('posting_date', '>=', (string) $request->query('date_from'));
        }
        if ($request->has('date_to')) {
            $query->whereDate('posting_date', '<=', (string) $request->query('date_to'));
        }

        $records = $query->orderBy('id', 'desc')
            ->limit($limit + 1)
            ->get();

        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
        }

        $invoices = $records->map(function (SalesInvoice $inv): array {
            $paid = BigDecimal::of((string) ($inv->paid_amount ?? '0.000000'));
            $grandTotal = BigDecimal::of((string) $inv->grand_total_currency);
            $remaining = $grandTotal->minus($paid);

            $paymentStatus = match (true) {
                $remaining->isZero() || $remaining->isNegative() => 'PAID_CASH',
                $paid->isPositive() => 'PARTIAL',
                default => 'ON_CREDIT',
            };

            return [
                'id' => (string) $inv->public_id,
                'invoice_number' => (string) ($inv->invoice_number ?? $inv->public_id),
                'status' => (string) $inv->status,
                'customer_id' => $inv->customer ? (string) $inv->customer->public_id : null,
                'customer_name' => $inv->customer ? (string) $inv->customer->displayName() : 'عميل نقدي عام',
                'date' => $inv->posting_date ? $inv->posting_date->format('Y-m-d') : ($inv->issue_date ? $inv->issue_date->format('Y-m-d') : now()->format('Y-m-d')),
                'subtotal' => (string) BigDecimal::of((string) ($inv->subtotal_currency ?? '0.000000'))->toScale(2, RoundingMode::HALF_UP),
                'discount' => (string) BigDecimal::of((string) ($inv->discount_total_currency ?? '0.000000'))->toScale(2, RoundingMode::HALF_UP),
                'tax' => (string) BigDecimal::of((string) ($inv->tax_total_currency ?? '0.000000'))->toScale(2, RoundingMode::HALF_UP),
                'total' => (string) $grandTotal->toScale(2, RoundingMode::HALF_UP),
                'paid' => (string) $paid->toScale(2, RoundingMode::HALF_UP),
                'remaining' => (string) $remaining->toScale(2, RoundingMode::HALF_UP),
                'currency' => (string) $inv->currency_code,
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
    public function show(Request $request, string $companyId, string $publicId): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('sales.invoice.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required sales.invoice.view permission.',
                status: 403
            );
        }

        $invoice = SalesInvoice::where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->with(['customer', 'lines.product', 'lines.unit', 'allocations.customerPayment'])
            ->first();

        if ($invoice === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested sales invoice was not found.',
                status: 404
            );
        }

        $paid = BigDecimal::of((string) ($invoice->paid_amount ?? '0.000000'));
        $grandTotal = BigDecimal::of((string) $invoice->grand_total_currency);
        $remaining = $grandTotal->minus($paid);

        $paymentStatus = match (true) {
            $remaining->isZero() || $remaining->isNegative() => 'PAID_CASH',
            $paid->isPositive() => 'PARTIAL',
            default => 'ON_CREDIT',
        };

        $linesPayload = $invoice->lines->map(function ($line): array {
            return [
                'id' => (string) $line->id,
                'product_id' => $line->product ? (string) $line->product->public_id : null,
                'product_name' => $line->product ? (string) $line->product->displayName() : (string) $line->description,
                'unit' => $line->unit ? (string) $line->unit->displayName() : '',
                'quantity' => (string) BigDecimal::of((string) $line->quantity)->toScale(4, RoundingMode::HALF_UP),
                'unit_price' => (string) BigDecimal::of((string) $line->unit_price)->toScale(2, RoundingMode::HALF_UP),
                'line_total' => (string) BigDecimal::of((string) $line->line_total)->toScale(2, RoundingMode::HALF_UP),
                'tax_rate' => (string) ($line->tax_percent ?? '0.00'),
                'discount' => (string) BigDecimal::of((string) ($line->discount_amount ?? '0.000000'))->toScale(2, RoundingMode::HALF_UP),
            ];
        })->values()->all();

        // Thermal receipt layout payload
        $thermalReceipt = [
            'store_name_ar' => (string) ($company->name_ar ?? $company->name),
            'store_name_en' => (string) ($company->name_en ?? $company->name),
            'city_ar' => (string) ($company->city_ar ?? $company->city ?? ''),
            'city_en' => (string) ($company->city_en ?? $company->city ?? ''),
            'phone' => (string) ($company->phone ?? ''),
            'tax_number' => (string) ($company->tax_number ?? ''),
            'invoice_number' => (string) ($invoice->invoice_number ?? $invoice->public_id),
            'date_time' => $invoice->posting_date ? $invoice->posting_date->format('Y-m-d H:i') : now()->format('Y-m-d H:i'),
            'cashier' => (string) $user->name,
            'customer_name' => $invoice->customer ? (string) $invoice->customer->displayName() : 'عميل نقدي عام',
            'subtotal' => (string) BigDecimal::of((string) ($invoice->subtotal_currency ?? '0.000000'))->toScale(2, RoundingMode::HALF_UP),
            'discount' => (string) BigDecimal::of((string) ($invoice->discount_total_currency ?? '0.000000'))->toScale(2, RoundingMode::HALF_UP),
            'tax' => (string) BigDecimal::of((string) ($invoice->tax_total_currency ?? '0.000000'))->toScale(2, RoundingMode::HALF_UP),
            'grand_total' => (string) $grandTotal->toScale(2, RoundingMode::HALF_UP),
            'paid_amount' => (string) $paid->toScale(2, RoundingMode::HALF_UP),
            'remaining_balance' => (string) $remaining->toScale(2, RoundingMode::HALF_UP),
            'currency' => (string) $invoice->currency_code,
            'lines' => $linesPayload,
        ];

        return response()->json([
            'id' => (string) $invoice->public_id,
            'company_id' => (string) $company->public_id,
            'invoice_number' => (string) ($invoice->invoice_number ?? $invoice->public_id),
            'status' => (string) $invoice->status,
            'payment_status' => $paymentStatus,
            'customer_id' => $invoice->customer ? (string) $invoice->customer->public_id : null,
            'customer_name' => $invoice->customer ? (string) $invoice->customer->displayName() : 'عميل نقدي عام',
            'date' => $invoice->posting_date ? $invoice->posting_date->format('Y-m-d') : ($invoice->issue_date ? $invoice->issue_date->format('Y-m-d') : now()->format('Y-m-d')),
            'currency' => (string) $invoice->currency_code,
            'subtotal' => (string) BigDecimal::of((string) ($invoice->subtotal_currency ?? '0.000000'))->toScale(2, RoundingMode::HALF_UP),
            'discount' => (string) BigDecimal::of((string) ($invoice->discount_total_currency ?? '0.000000'))->toScale(2, RoundingMode::HALF_UP),
            'tax' => (string) BigDecimal::of((string) ($invoice->tax_total_currency ?? '0.000000'))->toScale(2, RoundingMode::HALF_UP),
            'total' => (string) $grandTotal->toScale(2, RoundingMode::HALF_UP),
            'paid' => (string) $paid->toScale(2, RoundingMode::HALF_UP),
            'remaining' => (string) $remaining->toScale(2, RoundingMode::HALF_UP),
            'lines' => $linesPayload,
            'thermal_receipt' => $thermalReceipt,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
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

        if (! $user->hasPermissionTo('sales.invoice.create')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required sales.invoice.create permission.',
                status: 403
            );
        }

        $idempotencyKey = (string) $request->header('Idempotency-Key', $request->input('idempotency_key', ''));
        if (trim($idempotencyKey) === '') {
            $idempotencyKey = 'mobile-draft-'.uniqid('', true);
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

        if (! $user->hasPermissionTo('sales.invoice.create')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required sales.invoice.create permission.',
                status: 403
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

        $idempotencyKey = (string) $request->header('Idempotency-Key', $request->input('idempotency_key', ''));
        if (trim($idempotencyKey) === '') {
            $idempotencyKey = 'mobile-update-'.uniqid('', true);
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
    public function preview(Request $request, string $companyId, SalesInvoiceCommands $commands): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

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

        $subtotal = BigDecimal::zero();
        $totalTax = BigDecimal::zero();
        $discountAmount = BigDecimal::of((string) ($payload['discount_amount'] ?? '0.000000'));

        $previewLines = [];
        foreach ($lines as $line) {
            $qty = BigDecimal::of((string) ($line['quantity'] ?? '1'));
            $price = BigDecimal::of((string) ($line['unit_price'] ?? '0'));
            $lineSub = $qty->multipliedBy($price);
            $taxRate = BigDecimal::of((string) ($line['tax_percent'] ?? '0'));
            $tax = $lineSub->multipliedBy($taxRate)->dividedBy(100, 6, RoundingMode::HALF_UP);
            $lineTotal = $lineSub->plus($tax);

            $subtotal = $subtotal->plus($lineSub);
            $totalTax = $totalTax->plus($tax);

            $previewLines[] = [
                'product_id' => $line['product_id'] ?? null,
                'quantity' => (string) $qty,
                'unit_price' => (string) $price,
                'tax_amount' => (string) $tax->toScale(2, RoundingMode::HALF_UP),
                'line_total' => (string) $lineTotal->toScale(2, RoundingMode::HALF_UP),
            ];
        }

        $grandTotal = $subtotal->minus($discountAmount)->plus($totalTax);
        if ($grandTotal->isNegative()) {
            $grandTotal = BigDecimal::zero();
        }

        return response()->json([
            'subtotal' => (string) $subtotal->toScale(2, RoundingMode::HALF_UP),
            'discount_amount' => (string) $discountAmount->toScale(2, RoundingMode::HALF_UP),
            'tax_total' => (string) $totalTax->toScale(2, RoundingMode::HALF_UP),
            'grand_total' => (string) $grandTotal->toScale(2, RoundingMode::HALF_UP),
            'currency' => (string) ($payload['currency_code'] ?? $company->base_currency_code),
            'lines' => $previewLines,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/sales/invoices/{publicId}/execute
     */
    public function execute(
        Request $request,
        string $companyId,
        string $publicId,
        PostSalesInvoiceAction $postInvoiceAction,
        PostCustomerPaymentAction $postPaymentAction
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('sales.invoice.post')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required sales.invoice.post permission.',
                status: 403
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

        if (! $invoice->isDraft()) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'CONFLICT',
                message: "Sales invoice is in [{$invoice->status}] status and cannot be executed.",
                status: 409
            );
        }

        $paidNow = $request->input('paid_now');
        $moneyAccountId = $request->input('money_account_id');

        try {
            $posted = DB::transaction(function () use ($company, $user, $invoice, $postInvoiceAction, $postPaymentAction, $paidNow, $moneyAccountId): SalesInvoice {
                app(CompanyContext::class)->setCompany($company, $user);

                $postedInvoice = $postInvoiceAction->execute($invoice, $user);

                if ($paidNow !== null && is_scalar($paidNow)) {
                    $paidAmount = BigDecimal::of((string) $paidNow);
                    if ($paidAmount->isPositive()) {
                        $cashAccount = null;
                        if ($moneyAccountId !== null) {
                            $cashAccount = MoneyAccount::where('company_id', $company->id)
                                ->where('public_id', (string) $moneyAccountId)
                                ->first();
                        }
                        if ($cashAccount === null) {
                            $cashAccount = MoneyAccount::where('company_id', $company->id)
                                ->where('account_type', MoneyAccount::TYPE_CASH)
                                ->where('is_active', true)
                                ->first();
                        }

                        if ($cashAccount !== null) {
                            $paymentData = [
                                'customer_id' => $postedInvoice->customer_id,
                                'money_account_id' => $cashAccount->id,
                                'currency_code' => $postedInvoice->currency_code,
                                'exchange_rate' => $postedInvoice->exchange_rate,
                                'amount' => (string) $paidAmount,
                                'payment_date' => now()->format('Y-m-d'),
                                'payment_method' => 'cash',
                                'notes' => 'سداد نقدي عند إنشاء الفاتورة #'.$postedInvoice->invoice_number,
                                'allocations' => [
                                    [
                                        'invoice_id' => $postedInvoice->id,
                                        'amount' => (string) $paidAmount,
                                    ],
                                ],
                            ];
                            $postPaymentAction->execute($company, $user, $paymentData);
                        }
                    }
                }

                return $postedInvoice->fresh(['customer', 'lines']);
            });
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Execution failed: '.$e->getMessage(),
                status: 422
            );
        }

        return response()->json([
            'id' => (string) $posted->public_id,
            'invoice_number' => (string) ($posted->invoice_number ?? $posted->public_id),
            'status' => (string) $posted->status,
            'total' => (string) BigDecimal::of((string) $posted->grand_total_currency)->toScale(2, RoundingMode::HALF_UP),
            'currency' => (string) $posted->currency_code,
            'posted_at' => $posted->posting_date ? $posted->posting_date->format('Y-m-d\TH:i:s\Z') : now()->format('Y-m-d\TH:i:s\Z'),
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }
}
