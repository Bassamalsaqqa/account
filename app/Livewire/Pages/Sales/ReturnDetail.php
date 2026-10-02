<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\VoidSalesReturnAction;
use App\Models\PublicShare;
use App\Models\SalesReturn;
use App\Services\Sales\PublicShareService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ReturnDetail extends Component
{
    public SalesReturn $return;

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

        if (! $user->hasPermissionTo('sales.return.view')) {
            abort(403, 'Unauthorized.');
        }

        $this->return = SalesReturn::with(['lines', 'customer', 'salesInvoice'])
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $activeShare = PublicShare::where('company_id', $company->id)
            ->where('subject_type', PublicShare::SUBJECT_SALES_RETURN)
            ->where('subject_id', $this->return->id)
            ->where('is_active', true)
            ->first();

        if ($activeShare !== null && $activeShare->isValid()) {
            $this->shareUrl = $user->hasPermissionTo('sales.document.share') ? app(PublicShareService::class)->urlFor($activeShare, $user) : null;
        }
    }

    public function postReturn(PostSalesReturnAction $postAction): void
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('sales.return.post')) {
            abort(403, 'Unauthorized.');
        }

        $this->return = $postAction->execute($this->return, $user);
        session()->flash('success', __('sales.posted_successfully'));
    }

    public function voidReturn(VoidSalesReturnAction $voidAction): void
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('sales.return.void')) {
            abort(403, 'Unauthorized.');
        }

        $this->validate([
            'voidReason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->return = $voidAction->execute($this->return, $user, $this->voidReason);
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
            subjectType: PublicShare::SUBJECT_SALES_RETURN,
            subjectId: $this->return->id,
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
        foreach (PublicShare::where('company_id', $company->id)->where('subject_type', PublicShare::SUBJECT_SALES_RETURN)->where('subject_id', $this->return->id)->where('is_active', true)->get() as $share) {
            $service->revokeShare($share, auth()->user());
        }
        $this->shareUrl = null;
        session()->flash('success', __('sales.share_revoked'));
    }

    public function render(): View
    {
        $user = auth()->user();
        $canPost = $user->hasPermissionTo('sales.return.post') && $this->return->status === SalesReturn::STATUS_DRAFT;
        $canVoid = $user->hasPermissionTo('sales.return.void') && $this->return->status === SalesReturn::STATUS_POSTED;
        $canShare = $user->hasPermissionTo('sales.document.share') && $this->return->status === SalesReturn::STATUS_POSTED;

        return view('livewire.pages.sales.return-detail', [
            'canPost' => $canPost,
            'canVoid' => $canVoid,
            'canShare' => $canShare,
        ]);
    }
}
