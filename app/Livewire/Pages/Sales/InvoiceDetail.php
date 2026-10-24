<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Application\Commands\SalesInvoiceCommands;
use App\Exceptions\NoActiveCompanyException;
use App\Models\PublicShare;
use App\Models\SalesInvoice;
use App\Services\Sales\PublicShareService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    // Phase 11 Command Parity & State Guarding
    #[Locked]
    public ?string $confirmationToken = null;

    #[Locked]
    public ?string $executeKey = null;

    public bool $showPreview = false;

    /**
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $previewData = null;

    public ?string $previewError = null;

    public function updated(?string $property = null): void
    {
        if ($this->showPreview && ! in_array($property, ['showPreview', 'confirmationToken', 'executeKey', 'previewData', 'previewError'], true)) {
            $this->cancelPreview();
        }
    }

    public function mount(string $publicId, CompanyContext $context): void
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.invoice.view')) {
            abort(403, 'Unauthorized.');
        }

        $this->invoice = SalesInvoice::with(['lines', 'customer', 'warehouse', 'allocations.payment', 'returns', 'quotation'])
            ->where('company_id', $company->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    public function postInvoice(SalesInvoiceCommands $commands): void
    {
        $user = auth()->user();
        if (! $user || ! $user->hasPermissionTo('sales.invoice.post')) {
            abort(403, 'Unauthorized.');
        }

        if (! $this->invoice->isDraft()) {
            abort(400, 'Invoice is not in draft status.');
        }

        $this->previewError = null;

        try {
            $preview = $commands->preview($this->invoice, $user);
            $this->previewData = $preview;
            $this->confirmationToken = $preview['confirmation_token'] ?? null;
            $this->executeKey = (string) Str::uuid();
            $this->showPreview = true;
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException || $e instanceof NoActiveCompanyException || ($e instanceof HttpException && $e->getStatusCode() === 403)) {
                throw $e;
            }
            $this->showPreview = false;
            $this->previewError = $this->resolveUserErrorMessage($e);
        }
    }

    public function confirmPost(SalesInvoiceCommands $commands): void
    {
        $user = auth()->user();
        if (! $user || ! $user->hasPermissionTo('sales.invoice.post')) {
            abort(403, 'Unauthorized.');
        }

        if (! $this->showPreview || ! $this->confirmationToken) {
            $this->previewError = __('commands.preview_expired');

            return;
        }

        if (! empty($this->previewData['blockers']) || ($this->previewData['can_execute'] ?? true) === false) {
            $this->previewError = __('commands.cannot_confirm_blockers');

            return;
        }

        try {
            $this->invoice = $commands->execute($this->invoice, $user, $this->confirmationToken, $this->executeKey);
            $this->cancelPreview();
            session()->flash('success', __('sales.posted_successfully'));
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException || $e instanceof NoActiveCompanyException || ($e instanceof HttpException && $e->getStatusCode() === 403)) {
                throw $e;
            }
            $this->cancelPreview();
            $this->previewError = $this->resolveUserErrorMessage($e);
        }
    }

    public function cancelPreview(): void
    {
        $this->showPreview = false;
        $this->previewData = null;
        $this->confirmationToken = null;
        $this->executeKey = null;
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

    protected function resolveUserErrorMessage(\Throwable $e): string
    {
        if ($e instanceof AuthorizationException
            || $e instanceof NoActiveCompanyException
            || ($e instanceof HttpException && $e->getStatusCode() === 403)) {
            throw $e;
        }

        if ($e instanceof \InvalidArgumentException) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'token') || str_contains($msg, 'expired') || str_contains($msg, 'stale')) {
                return __('commands.preview_expired');
            }
            if (str_contains($msg, 'warehouse')) {
                return __('commands.code_warehouse_inactive');
            }
            if (str_contains($msg, 'customer')) {
                return __('commands.code_customer_inactive');
            }
            if (str_contains($msg, 'stock') || str_contains($msg, 'insufficient')) {
                return __('commands.code_insufficient_stock');
            }

            return __('commands.code_validation_failed');
        }

        if ($e instanceof ConflictHttpException
            || $e->getCode() === 409
            || ($e instanceof HttpException && $e->getStatusCode() === 409)) {
            return __('commands.conflict_error');
        }

        return __('commands.generic_error');
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
            'canCreateInvoice' => $user->hasPermissionTo('sales.invoice.create'),
            'outstanding' => (string) $outstanding,
            'paymentStatus' => $paymentStatus,
        ]);
    }
}
