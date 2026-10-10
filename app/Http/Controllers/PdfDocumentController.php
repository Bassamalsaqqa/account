<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Sales\Documents\DocumentData;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Services\Sales\DocumentDataBuilder;
use App\Services\Sales\DocumentRenderLimits;
use App\Services\Sales\PdfRendererService;
use App\Services\Sales\PrintRenderer;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PdfDocumentController extends Controller
{
    public function quotation(string $publicId, CompanyContext $context, PdfRendererService $pdf): Response
    {
        $company = $context->company();

        $this->authorizeOutput($context, 'sales.quote.view');

        $quote = Quotation::query()
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return $this->renderSource($quote, $pdf);
    }

    public function invoice(string $publicId, CompanyContext $context, PdfRendererService $pdf): Response
    {
        $company = $context->company();

        $this->authorizeOutput($context, 'sales.invoice.view');

        $invoice = SalesInvoice::query()
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return $this->renderSource($invoice, $pdf);
    }

    public function return(string $publicId, CompanyContext $context, PdfRendererService $pdf): Response
    {
        $company = $context->company();

        $this->authorizeOutput($context, 'sales.return.view');

        $return = SalesReturn::query()
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return $this->renderSource($return, $pdf);
    }

    public function payment(string $publicId, CompanyContext $context, PdfRendererService $pdf): Response
    {
        $company = $context->company();

        $this->authorizeOutput($context, 'money.receipt.view');

        $payment = CustomerPayment::query()
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return $this->renderSource($payment, $pdf);
    }

    public function statement(string $publicId, Request $request, CompanyContext $context, CustomerStatementQuery $statementQuery, PdfRendererService $pdf): Response
    {
        $company = $context->company();

        $this->authorizeOutput($context, 'sales.statement.view');

        $customer = Customer::where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $range = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => array_filter(['nullable', 'date_format:Y-m-d', $request->filled('from') ? 'after_or_equal:from' : null])]);
        $from = $range['from'] ?? null;
        $to = $range['to'] ?? null;
        app(DocumentRenderLimits::class)->assertStatementSource((int) $company->id, (int) $customer->id);
        $statements = $statementQuery->execute($customer, $from, $to);

        return $this->renderData(app(DocumentDataBuilder::class)->statement($statements, $this->locale()), $pdf);
    }

    private function locale(): ?string
    {
        $options = request()->validate(['locale' => ['nullable', 'in:ar,en'], 'format' => ['nullable', 'in:print,pdf']]);

        return $options['locale'] ?? null;
    }

    private function renderSource(Quotation|SalesInvoice|SalesReturn|CustomerPayment $source, PdfRendererService $pdf): Response
    {
        $data = DB::transaction(fn () => app(DocumentDataBuilder::class)->build($source, forPdf: true, locale: $this->locale(), includeApplications: $source instanceof CustomerPayment));

        return $this->renderData($data, $pdf);
    }

    private function renderData(DocumentData $data, PdfRendererService $pdf): Response
    {
        if (request()->query('format') === 'print') {
            return response(app(PrintRenderer::class)->render($data));
        }
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $data->type.'-'.($data->document['number'] ?? 'document'));

        return response($pdf->renderDocument($data), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.substr($name, 0, 150).'.pdf"',
        ]);
    }

    private function authorizeOutput(CompanyContext $context, string $permission): void
    {
        abort_unless(auth()->check(), 403);
        DB::transaction(function () use ($context, $permission): void {
            foreach (['sales.document.pdf', $permission] as $required) {
                app(SalesActorGuard::class)->lockAndAuthorize((int) $context->companyId(), auth()->user(), $required);
            }
            if ($permission === 'sales.statement.view') {
                app(SalesActorGuard::class)->lockAndAuthorize((int) $context->companyId(), auth()->user(), 'customers.statement.view');
            }
        });
    }
}
