<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Actions\Sales\ConvertQuotationToInvoiceAction;
use App\Models\PublicShare;
use App\Models\Quotation;
use App\Services\Sales\PublicShareService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class QuotationDetail extends Component
{
    public Quotation $quotation;

    public ?string $shareUrl = null;

    public bool $showShareModal = false;

    public int $shareExpiryDays = 30;

    public ?string $sharePassword = null;

    public function mount(string $publicId, CompanyContext $context): void
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.quote.view')) {
            abort(403, 'Unauthorized.');
        }

        $this->quotation = Quotation::with(['lines', 'customer'])
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $activeShare = PublicShare::where('company_id', $company->id)
            ->where('subject_type', PublicShare::SUBJECT_QUOTATION)
            ->where('subject_id', $this->quotation->id)
            ->where('is_active', true)
            ->first();

        if ($activeShare !== null && $activeShare->isValid()) {
            $this->shareUrl = auth()->user()->hasPermissionTo('sales.document.share') ? app(PublicShareService::class)->urlFor($activeShare, auth()->user()) : null;
        }
    }

    public function markAsSent(): void
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('sales.quote.send')) {
            abort(403, 'Unauthorized.');
        }

        if ($this->quotation->status === Quotation::STATUS_DRAFT) {
            $this->quotation->transition(Quotation::STATUS_SENT, $user);
            session()->flash('success', __('sales.updated_successfully'));
        }
    }

    public function returnToDraft(): void
    {
        $this->quotation->transition(Quotation::STATUS_DRAFT, auth()->user());
        $this->quotation->refresh();
        session()->flash('success', __('sales.updated_successfully'));
    }

    public function markAsAccepted(): void
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('sales.quote.edit')) {
            abort(403, 'Unauthorized.');
        }

        if (in_array($this->quotation->status, [Quotation::STATUS_SENT], true)) {
            $this->quotation->transition(Quotation::STATUS_ACCEPTED, $user);
            session()->flash('success', __('sales.updated_successfully'));
        }
    }

    public function markAsRejected(): void
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('sales.quote.edit')) {
            abort(403, 'Unauthorized.');
        }

        if (in_array($this->quotation->status, [Quotation::STATUS_SENT], true)) {
            $this->quotation->transition(Quotation::STATUS_REJECTED, $user);
            session()->flash('success', __('sales.updated_successfully'));
        }
    }

    public function convertToInvoice(ConvertQuotationToInvoiceAction $convertAction, CompanyContext $context): mixed
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.quote.convert')) {
            abort(403, 'Unauthorized.');
        }

        $invoice = $convertAction->execute($this->quotation, $user);
        session()->flash('success', __('sales.status_converted'));

        return redirect()->route('invoices.show', $invoice->public_id);
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
            subjectType: PublicShare::SUBJECT_QUOTATION,
            subjectId: $this->quotation->id,
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
        foreach (PublicShare::where('company_id', $company->id)->where('subject_type', PublicShare::SUBJECT_QUOTATION)->where('subject_id', $this->quotation->id)->where('is_active', true)->get() as $share) {
            $service->revokeShare($share, auth()->user());
        }
        $this->shareUrl = null;
        session()->flash('success', __('sales.share_revoked'));
    }

    public function render(): View
    {
        $user = auth()->user();
        $canEdit = $user->hasPermissionTo('sales.quote.edit') && $this->quotation->status === Quotation::STATUS_DRAFT;
        $canReturnToDraft = $user->hasPermissionTo('sales.quote.edit') && $this->quotation->status === Quotation::STATUS_SENT;
        $canSend = $user->hasPermissionTo('sales.quote.send') && $this->quotation->status === Quotation::STATUS_DRAFT;
        $canConvert = $user->hasPermissionTo('sales.quote.convert') && $this->quotation->status === Quotation::STATUS_ACCEPTED;
        $canShare = $user->hasPermissionTo('sales.document.share');

        return view('livewire.pages.sales.quotation-detail', [
            'canEdit' => $canEdit,
            'canReturnToDraft' => $canReturnToDraft,
            'canSend' => $canSend,
            'canConvert' => $canConvert,
            'canShare' => $canShare,
        ]);
    }
}
