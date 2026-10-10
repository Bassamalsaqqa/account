<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\Purchasing\PurchasingDocumentBuilder;
use App\Services\Purchasing\PurchasingDocumentRenderer;
use App\Services\Purchasing\VendorFinancialRead;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class PurchasingDocumentController extends Controller
{
    public function purchase(string $publicId, Request $request): Response
    {
        return $this->output($this->source(Purchase::class, $publicId), $request);
    }

    public function return(string $publicId, Request $request): Response
    {
        return $this->output($this->source(PurchaseReturn::class, $publicId), $request);
    }

    public function payment(string $publicId, Request $request): Response
    {
        return $this->output($this->source(VendorPayment::class, $publicId), $request);
    }

    public function statement(string $publicId, Request $request): Response
    {
        return $this->output($this->source(Vendor::class, $publicId), $request);
    }

    /** @template T of Purchase|PurchaseReturn|VendorPayment|Vendor
     * @param  class-string<T>  $class
     * @return T
     */
    private function source(string $class, string $publicId): Purchase|PurchaseReturn|VendorPayment|Vendor
    {
        return $class::where('company_id', app(CompanyContext::class)->companyId())->where('public_id', $publicId)->firstOrFail();
    }

    private function output(Purchase|PurchaseReturn|VendorPayment|Vendor $source, Request $request): Response
    {
        $options = $request->validate(['locale' => ['nullable', 'in:ar,en'], 'format' => ['nullable', 'in:print,pdf'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => array_filter(['nullable', 'date_format:Y-m-d', $request->filled('from') ? 'after_or_equal:from' : null])]);
        $builder = app(PurchasingDocumentBuilder::class);
        $data = $source instanceof Vendor
            ? $builder->statement($source, $options['from'] ?? null, $options['to'] ?? null, $options['locale'] ?? null)
            : $builder->build($source, $options['locale'] ?? null);
        // The builder has released its consistent-read transaction before mPDF.
        $renderer = app(PurchasingDocumentRenderer::class);
        $print = ($options['format'] ?? null) === 'print';
        $bytes = $print ? $renderer->html($data, true) : $renderer->pdf($data);
        DB::transaction(function () use ($source, $data): void {
            $guard = app(SalesActorGuard::class);
            $companyId = (int) $source->company_id;
            $guard->lockAndAuthorize($companyId, auth()->user(), 'purchasing.document.pdf');
            if ($source instanceof VendorPayment) {
                abort_unless(app(VendorFinancialRead::class)->allows($companyId), 403);
            } else {
                $guard->lockAndAuthorize($companyId, auth()->user(), $source instanceof Vendor ? 'vendors.statement.view' : 'purchasing.purchase.view');
                if ($data['with_cost']) {
                    $guard->lockAndAuthorize($companyId, auth()->user(), 'purchasing.cost.view');
                }
            }
        });
        $name = substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $data['type'].'-'.($data['document']['number'] ?? 'document')), 0, 150);
        $headers = ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff'];
        if (! $print) {
            $headers += ['Content-Type' => 'application/pdf', 'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$name.'.pdf"'];
        }

        return response($bytes, 200, $headers);
    }
}
