<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ReturnForm extends Component
{
    public ?int $sales_invoice_id = null;

    public string $issue_date = '';

    public ?string $reason = null;

    public ?string $notes = null;

    /**
     * @var list<array{
     *     sales_invoice_line_id: int,
     *     item_description: string,
     *     original_quantity: string,
     *     already_returned_quantity: string,
     *     max_returnable: string,
     *     return_quantity: string,
     *     unit_price: string,
     *     unit_name: ?string,
     * }>
     */
    public array $lines = [];

    public function mount(CompanyContext $context, ?int $invoice_id = null): void
    {
        $invoice_id ??= request()->integer('invoice_id') ?: null;
        $company = $context->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.return.create')) {
            abort(403, 'Unauthorized.');
        }

        $this->issue_date = Carbon::now()->toDateString();

        if ($invoice_id !== null) {
            $this->sales_invoice_id = $invoice_id;
            $this->loadInvoiceLines();
        }
    }

    public function updatedSalesInvoiceId(): void
    {
        $this->loadInvoiceLines();
    }

    public function loadInvoiceLines(): void
    {
        if ($this->sales_invoice_id === null) {
            $this->lines = [];

            return;
        }

        $company = app(CompanyContext::class)->company();
        $invoice = SalesInvoice::with('lines.productUnit')
            ->where('company_id', $company->id)
            ->where('id', $this->sales_invoice_id)
            ->first();

        if ($invoice === null || ! $invoice->isPosted()) {
            $this->lines = [];

            return;
        }

        $this->lines = [];

        foreach ($invoice->lines as $line) {
            $alreadyReturned = DB::table('sales_return_lines')
                ->join('sales_returns', 'sales_return_lines.sales_return_id', '=', 'sales_returns.id')
                ->where('sales_returns.status', SalesReturn::STATUS_POSTED)
                ->where('sales_return_lines.sales_invoice_line_id', $line->id)
                ->sum('sales_return_lines.quantity');

            $lineQty = BigDecimal::of((string) $line->quantity);
            $priorQty = BigDecimal::of((string) ($alreadyReturned ?: 0));
            $maxReturnable = $lineQty->minus($priorQty);

            if ($maxReturnable->isPositive()) {
                $this->lines[] = [
                    'sales_invoice_line_id' => $line->id,
                    'item_description' => $line->item_description,
                    'original_quantity' => (string) $lineQty,
                    'already_returned_quantity' => (string) $priorQty,
                    'max_returnable' => (string) $maxReturnable,
                    'return_quantity' => '0',
                    'unit_price' => (string) $line->unit_price,
                    'unit_name' => app()->getLocale() === 'en' ? ($line->unit_name_en ?? $line->unit_name_ar) : $line->unit_name_ar,
                ];
            }
        }
    }

    public function save(
        bool $andPost,
        CreateSalesReturnDraftAction $createAction,
        PostSalesReturnAction $postAction
    ): mixed {
        $company = app(CompanyContext::class)->company();
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.return.create')) {
            abort(403, 'Unauthorized.');
        }

        if ($andPost && ! $user->hasPermissionTo('sales.return.post')) {
            abort(403, 'Unauthorized to post returns.');
        }

        $this->validate([
            'sales_invoice_id' => ['required', 'integer', 'exists:sales_invoices,id'],
            'issue_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
        ]);

        $dtoLines = [];
        foreach ($this->lines as $line) {
            $qty = BigDecimal::of((string) ($line['return_quantity'] ?: 0));
            if ($qty->isPositive()) {
                $max = BigDecimal::of((string) $line['max_returnable']);
                if ($qty->isGreaterThan($max)) {
                    $this->addError('lines', "Return quantity [{$qty}] cannot exceed max returnable [{$max}] for {$line['item_description']}.");

                    return null;
                }

                $dtoLines[] = [
                    'sales_invoice_line_id' => (int) $line['sales_invoice_line_id'],
                    'quantity' => (string) $qty,
                ];
            }
        }

        if (empty($dtoLines)) {
            $this->addError('lines', 'Please enter a return quantity greater than 0 for at least one item.');

            return null;
        }

        $payload = [
            'sales_invoice_id' => (int) $this->sales_invoice_id,
            'issue_date' => $this->issue_date,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'lines' => $dtoLines,
        ];

        $return = $createAction->execute($company, $user, $payload);

        if ($andPost) {
            $return = $postAction->execute($return, $user);
            session()->flash('success', __('sales.posted_successfully'));
        } else {
            session()->flash('success', __('sales.created_successfully'));
        }

        return redirect()->route('returns.show', $return->public_id);
    }

    public function render(CompanyContext $context): View
    {
        $company = $context->company();
        $user = auth()->user();

        $canPost = $user->hasPermissionTo('sales.return.post');

        $invoices = SalesInvoice::with('customer')
            ->where('company_id', $company->id)
            ->where('status', SalesInvoice::STATUS_POSTED)
            ->orderByDesc('issue_date')
            ->get();

        return view('livewire.pages.sales.return-form', [
            'invoices' => $invoices,
            'canPost' => $canPost,
        ]);
    }
}
