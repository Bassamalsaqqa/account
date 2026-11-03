<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Actions\Sales\DeleteSalesInvoiceDraftAction;
use App\Application\Commands\SalesInvoiceCommands;
use App\Exceptions\NoActiveCompanyException;
use App\Livewire\Concerns\HasTablePageSize;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Support\InvoiceConfirmation;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[Layout('layouts.app')]
class InvoiceIndex extends Component
{
    use HasTablePageSize;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url(as: 'customer')]
    public ?int $customerFilter = null;

    #[Locked]
    public ?int $payingInvoiceId = null;

    #[On('close-payment-modal')]
    #[On('payment-recorded')]
    public function closePaymentModal(): void
    {
        $this->payingInvoiceId = null;
    }

    public function openPaymentModal(int $invoiceId): void
    {
        abort_unless(auth()->user()?->can('money.receipt.create'), 403);
        SalesInvoice::where('company_id', app(CompanyContext::class)->companyId())
            ->where('status', SalesInvoice::STATUS_POSTED)->findOrFail($invoiceId);
        $this->payingInvoiceId = $invoiceId;
    }

    public function postInvoice(string $publicId, CompanyContext $context, SalesInvoiceCommands $commands): void
    {
        $user = auth()->user();
        if (! $user || ! $user->hasPermissionTo('sales.invoice.post')) {
            abort(403, 'Unauthorized.');
        }

        $invoice = SalesInvoice::where('company_id', $context->companyId())->where('public_id', $publicId)->firstOrFail();
        if (! $invoice->isDraft()) {
            return;
        }

        try {
            $preview = $commands->preview($invoice, $user);
            if (! empty($preview['blockers']) || ($preview['can_execute'] ?? true) === false) {
                session()->flash('error', __('commands.cannot_confirm_blockers'));

                return;
            }
            if (! InvoiceConfirmation::canConfirmDirectly($preview)) {
                $this->redirect(route('invoices.show', $invoice->public_id));

                return;
            }
            $commands->execute($invoice, $user, $preview['confirmation_token'] ?? '', (string) Str::uuid());
            session()->flash('success', __('sales.invoice_posted'));
        } catch (AuthorizationException|NoActiveCompanyException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($exception instanceof HttpException && $exception->getStatusCode() === 403) {
                throw $exception;
            }
            report($exception);
            session()->flash('error', __('commands.generic_error'));
        }
    }

    public function deleteDraft(string $publicId, CompanyContext $context, DeleteSalesInvoiceDraftAction $action): void
    {
        $invoice = SalesInvoice::where('company_id', $context->companyId())->where('public_id', $publicId)->firstOrFail();
        try {
            $action->execute($invoice, auth()->user());
        } catch (\InvalidArgumentException $exception) {
            $this->addError('deleteDraft', $exception->getMessage());

            return;
        }
        $this->resetPage();
        session()->flash('success', __('sales.draft_deleted'));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCustomerFilter(): void
    {
        $this->resetPage();
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.invoice.view')) {
            abort(403, 'Unauthorized.');
        }

        $canCreate = $user->hasPermissionTo('sales.invoice.create');

        $query = SalesInvoice::with(['customer', 'allocations' => fn ($q) => $q->active(),
            'returns' => fn ($q) => $q->where('status', 'posted')])
            ->where('company_id', $company->id);

        if (trim($this->search) !== '') {
            $term = trim($this->search);
            $query->where(function (Builder $q) use ($term) {
                $q->where('invoice_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', function (Builder $cQ) use ($term) {
                        $cQ->where('name_ar', 'like', "%{$term}%")
                            ->orWhere('name_en', 'like', "%{$term}%")
                            ->orWhere('code', 'like', "%{$term}%");
                    });
            });
        }

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->customerFilter !== null) {
            $query->where('customer_id', $this->customerFilter);
        }

        $invoices = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($this->tablePageSize());
        // Request-local list projection only. Canonical posting/payment checks still read fresh state.
        $summaries = [];
        foreach ($invoices as $invoice) {
            $total = BigDecimal::of($invoice->grand_total_currency);
            $outstanding = BigDecimal::zero();
            if ($invoice->isPosted()) {
                $outstanding = $total;
                foreach ($invoice->allocations as $allocation) {
                    $outstanding = $outstanding->minus($allocation->allocated_amount);
                }
                foreach ($invoice->returns as $return) {
                    $outstanding = $outstanding->minus($return->grand_total_currency);
                }
            }
            $summaries[$invoice->id] = ['outstanding' => $outstanding, 'status' => match (true) {
                ! $invoice->isPosted() => SalesInvoice::PAYMENT_STATUS_UNPAID,
                $outstanding->isNegative() => SalesInvoice::PAYMENT_STATUS_CREDIT,
                $outstanding->isZero() => SalesInvoice::PAYMENT_STATUS_PAID,
                $outstanding->isEqualTo($total) => SalesInvoice::PAYMENT_STATUS_UNPAID,
                default => SalesInvoice::PAYMENT_STATUS_PARTIALLY_PAID,
            }];
        }
        $customers = Customer::where('company_id', $company->id)->orderBy('name_ar')->get();

        return view('livewire.pages.sales.invoice-index', [
            'invoices' => $invoices,
            'customers' => $customers,
            'canCreate' => $canCreate,
            'company' => $company,
            'summaries' => $summaries,
        ]);
    }
}
