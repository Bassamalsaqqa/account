<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Services\Sales\DocumentDataBuilder;
use App\Services\Sales\PdfRendererService;
use App\Services\Sales\PrintRenderer;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PdfDocumentController extends Controller
{
    public function quotation(string $publicId, CompanyContext $context, PdfRendererService $pdf): Response
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.document.pdf') || ! $user->hasPermissionTo('sales.quote.view')) {
            abort(403, 'Unauthorized.');
        }

        $quote = Quotation::with(['lines', 'customer'])
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        if (request()->query('format') === 'print') {
            return response(app(PrintRenderer::class)->render(app(DocumentDataBuilder::class)->build($quote)));
        }
        $pdfBytes = $pdf->renderQuotation($quote);

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"quotation-{$quote->quotation_number}.pdf\"",
        ]);
    }

    public function invoice(string $publicId, CompanyContext $context, PdfRendererService $pdf): Response
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.document.pdf') || ! $user->hasPermissionTo('sales.invoice.view')) {
            abort(403, 'Unauthorized.');
        }

        $invoice = SalesInvoice::with(['lines', 'customer'])
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        if (request()->query('format') === 'print') {
            return response(app(PrintRenderer::class)->render(app(DocumentDataBuilder::class)->build($invoice)));
        }
        $pdfBytes = $pdf->renderSalesInvoice($invoice);

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"invoice-{$invoice->invoice_number}.pdf\"",
        ]);
    }

    public function return(string $publicId, CompanyContext $context, PdfRendererService $pdf): Response
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.document.pdf') || ! $user->hasPermissionTo('sales.return.view')) {
            abort(403, 'Unauthorized.');
        }

        $return = SalesReturn::with(['lines', 'customer', 'salesInvoice'])
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        if (request()->query('format') === 'print') {
            return response(app(PrintRenderer::class)->render(app(DocumentDataBuilder::class)->build($return)));
        }
        $pdfBytes = $pdf->renderSalesReturn($return);

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"return-{$return->return_number}.pdf\"",
        ]);
    }

    public function payment(string $publicId, CompanyContext $context, PdfRendererService $pdf): Response
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.document.pdf') || ! $user->hasPermissionTo('money.receipt.view')) {
            abort(403, 'Unauthorized.');
        }

        $payment = CustomerPayment::with(['customer', 'moneyAccount', 'allocations.salesInvoice'])
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        if (request()->query('format') === 'print') {
            return response(app(PrintRenderer::class)->render(app(DocumentDataBuilder::class)->build($payment)));
        }
        $pdfBytes = $pdf->renderCustomerPayment($payment);

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"receipt-{$payment->payment_number}.pdf\"",
        ]);
    }

    public function statement(string $publicId, Request $request, CompanyContext $context, CustomerStatementQuery $statementQuery, PdfRendererService $pdf): Response
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.document.pdf') || ! $user->hasPermissionTo('sales.statement.view')) {
            abort(403, 'Unauthorized.');
        }

        $customer = Customer::where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $from = $request->query('from') ? (string) $request->query('from') : null;
        $to = $request->query('to') ? (string) $request->query('to') : null;

        $statements = $statementQuery->execute($customer, $from, $to);

        if (request()->query('format') === 'print') {
            return response(app(PrintRenderer::class)->render(app(DocumentDataBuilder::class)->statement($statements)));
        }
        $pdfBytes = $pdf->renderCustomerStatement($statements);

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"statement-{$customer->code}.pdf\"",
        ]);
    }
}
