<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Models\PublicShare;
use App\Models\SalesInvoice;
use App\Services\Sales\PublicShareService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class InvoiceDetail extends Component
{
    public SalesInvoice $invoice;

    public ?string $shareUrl = null;

    public bool $showShareModal = false;

    public int $shareExpiryDays = 30;

    public ?string $sharePassword = null;

    public bool $showVoidModal = false;

    public string $voidReason = '';

    public function mount(string $publicId, CompanyContext $context): void
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.invoice.view')) {
            abort(403, 'Unauthorized.');
        }

        $this->invoice = SalesInvoice::with(['lines', 'customer', 'warehouse', 'allocations.payment', 'returns'])
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

    }

    public function postInvoice(PostSalesInvoiceAction $postAction): void
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('sales.invoice.post')) {
            abort(403, 'Unauthorized.');
        }

        $this->invoice = $postAction->execute($this->invoice, $user);
        session()->flash('success', __('sales.posted_successfully'));
    }

    public function voidInvoice(VoidSalesInvoiceAction $voidAction): void
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('sales.invoice.void')) {
            abort(403, 'Unauthorized.');
        }

        $this->validate([
            'voidReason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->invoice = $voidAction->execute($this->invoice, $user, $this->voidReason);
        $this->showVoidModal = false;
        session()->flash('success', __('sales.voided_successfully'));
    }

    public function createShareLink(PublicShareService $shareService, CompanyContext $context): void
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.document.share')) {
            abort(403, 'Unauthorized.');
        }

        $this->validate(['shareExpiryDays' => ['integer', 'min:0', 'max:3650'], 'sharePassword' => ['nullable', 'string', 'max:255']]);
        $result = $shareService->createShare(
            company: $company,
            user: $user,
            subjectType: PublicShare::SUBJECT_SALES_INVOICE,
            subjectId: $this->invoice->id,
            expiresAt: $this->shareExpiryDays > 0 ? Carbon::now()->addDays($this->shareExpiryDays) : null,
            password: $this->sharePassword
        );

        $this->shareUrl = $result['url'];
        $this->showShareModal = false;
        session()->flash('success', __('sales.created_successfully'));
    }

    public function revokeShareLink(PublicShareService $service): void
    {
        $company = app(CompanyContext::class)->company();
        abort_unless(auth()->user()->hasPermissionTo('sales.document.share'), 403);
        foreach (PublicShare::where('company_id', $company->id)->where('subject_type', PublicShare::SUBJECT_SALES_INVOICE)->where('subject_id', $this->invoice->id)->where('is_active', true)->get() as $share) {
            $service->revokeShare($share, auth()->user());
        }
        $this->shareUrl = null;
        session()->flash('success', __('sales.share_revoked'));
    }

    public function render(): View
    {
        $user = auth()->user();
        $canPost = $user->hasPermissionTo('sales.invoice.post') && $this->invoice->isDraft();
        $canEditDraft = $user->hasPermissionTo('sales.invoice.edit_draft') && $this->invoice->isDraft();
        $canVoid = $user->hasPermissionTo('sales.invoice.void') && $this->invoice->isPosted();
        $canShare = $user->hasPermissionTo('sales.document.share') && $this->invoice->isPosted();
        $canViewCost = $user->hasPermissionTo('inventory.cost.view');
        $canReturn = $user->hasPermissionTo('sales.return.create') && $this->invoice->isPosted();
        $canPay = $user->hasPermissionTo('money.receipt.create') && $this->invoice->isPosted();

        $outstanding = $this->invoice->calculateOutstanding();
        $paymentStatus = $this->invoice->derivedPaymentStatus();

        return view('livewire.pages.sales.invoice-detail', [
            'canPost' => $canPost,
            'canEditDraft' => $canEditDraft,
            'canVoid' => $canVoid,
            'canShare' => $canShare,
            'canViewCost' => $canViewCost,
            'canReturn' => $canReturn,
            'canPay' => $canPay,
            'outstanding' => (string) $outstanding,
            'paymentStatus' => $paymentStatus,
        ]);
    }
}
