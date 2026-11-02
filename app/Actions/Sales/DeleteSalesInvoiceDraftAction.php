<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Application\Platform\TenantWriteAccess;
use App\Models\PostingBatch;
use App\Models\PublicShare;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class DeleteSalesInvoiceDraftAction
{
    public function execute(SalesInvoice $invoice, User $actor): void
    {
        app(TenantWriteAccess::class)->transaction((int) $invoice->company_id, function () use ($invoice, $actor): void {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $invoice->company_id, $actor, 'sales.invoice.edit_draft');
            if (! $actor->hasRole('Owner')) {
                throw new AuthorizationException;
            }
            $locked = SalesInvoice::where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($invoice->id);
            if (! $locked->isDraft() || $locked->quotation_id !== null || $locked->invoice_number !== null
                || $locked->posting_batch_id !== null || $locked->posted_at !== null
                || $locked->allocations()->exists() || $locked->returns()->exists() || $locked->lotAllocations()->exists()
                || PublicShare::where('company_id', $locked->company_id)->where('subject_type', PublicShare::SUBJECT_SALES_INVOICE)->where('subject_id', $locked->id)->exists()
                || PostingBatch::where('company_id', $locked->company_id)->where('source_type', 'sales_invoice')->where('source_id', $locked->id)->exists()
                || StockMovement::where('company_id', $locked->company_id)->where('source_type', 'sales_invoice')->where('source_id', $locked->id)->exists()) {
                throw new InvalidArgumentException(__('sales.delete_draft_blocked'));
            }
            app(AuditService::class)->log((int) $locked->company_id, 'sales_invoice.draft_deleted', 'Owner discarded an unposted invoice draft', (int) $actor->id, $locked,
                before: ['public_id' => $locked->public_id, 'customer_id' => $locked->customer_id, 'grand_total_currency' => $locked->grand_total_currency,
                    'currency_code' => $locked->currency_code, 'line_count' => $locked->lines()->count()]);
            $locked->delete();
        });
    }
}
