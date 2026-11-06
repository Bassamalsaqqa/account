<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Sales;

use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Services\Sales\SalesActorGuard;
use App\Support\DocumentIdentity;
use App\Support\QuantityStep;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class ReturnForm extends Component
{
    #[Locked]
    public int $pageCompanyId;

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

    public function boot(CompanyContext $context): void
    {
        if (isset($this->pageCompanyId)) {
            $this->authorizeReturnAction();
        }
    }

    public function mount(CompanyContext $context, ?int $invoice_id = null): void
    {
        $invoice_id ??= request()->integer('invoice_id') ?: null;
        $company = $context->company();
        $this->pageCompanyId = (int) $company->id;
        $user = auth()->user();

        if (! $user->hasPermissionTo('sales.return.create')) {
            abort(403, 'Unauthorized.');
        }

        $this->issue_date = Carbon::now($company->timezone)->toDateString();

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
        $invoice = SalesInvoice::with('lines.productUnit.unit')
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
                    'quantity_step' => QuantityStep::forUnit($line->productUnit?->unit, [(string) $lineQty, (string) $maxReturnable]),
                    'unit_price' => (string) $line->unit_price,
                    'unit_name' => app()->getLocale() === 'en' ? ($line->unit_name_en ?? $line->unit_name_ar) : $line->unit_name_ar,
                ];
            }
        }
    }

    /**
     * Authorize fresh company and actor context for sales return operations.
     */
    protected function authorizeReturnAction(): Company
    {
        $context = app(CompanyContext::class);
        abort_unless(isset($this->pageCompanyId) && $context->hasCompany()
            && (int) $context->companyId() === $this->pageCompanyId, 403);
        try {
            return DB::transaction(fn () => app(SalesActorGuard::class)
                ->lockAndAuthorize($this->pageCompanyId, auth()->user(), 'sales.return.create'));
        } catch (AuthorizationException) {
            abort(403);
        }
    }

    /**
     * Batch step return quantity for a line within 0..currentremaining DB cap.
     *
     * @param  array<string, mixed>  $expectedIdentity
     * @param  array<int, string>  $directions
     * @return array{ok: bool, quantity?: string, minimumReached?: bool, message?: string}
     */
    public function batchStepQuantity(int $index, mixed $expectedQuantity, array $expectedIdentity, array $directions, string $field = 'return_quantity'): array
    {
        $company = $this->authorizeReturnAction();

        $refuse = function (string $message) use ($index): array {
            $this->addError("lines.{$index}.return_quantity", $message);

            return ['ok' => false, 'message' => $message];
        };

        if ($field !== 'return_quantity') {
            return $refuse(__('mobile.quantity_stale'));
        }

        if ($directions === [] || count($directions) > 100 || ! array_is_list($directions)) {
            return $refuse(__('refinement.invalid_direction'));
        }

        foreach ($directions as $direction) {
            if (! in_array($direction, ['up', 'down', '+', '-', 'increment', 'decrement'], true)) {
                return $refuse(__('refinement.invalid_direction'));
            }
        }

        if ($index < 0 || ! isset($this->lines[$index])) {
            return $refuse(__('mobile.line_not_found'));
        }

        $lineData = $this->lines[$index];

        $identity = [];
        foreach (DocumentIdentity::FIELDS as $idField) {
            $identity[$idField] = $lineData[$idField] ?? null;
        }
        if (count($expectedIdentity) !== count($identity)) {
            return $refuse(__('mobile.quantity_stale'));
        }
        foreach ($identity as $idField => $val) {
            if (! array_key_exists($idField, $expectedIdentity) || $expectedIdentity[$idField] !== $val) {
                return $refuse(__('mobile.quantity_stale'));
            }
        }

        if (! is_string($expectedQuantity) || preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', trim($expectedQuantity)) !== 1) {
            return $refuse(__('mobile.quantity_stale'));
        }

        $currentQtyStr = trim((string) $lineData['return_quantity']);
        $expectedQtyStr = trim($expectedQuantity);
        if (str_contains($currentQtyStr, '.')) {
            $currentQtyStr = rtrim(rtrim($currentQtyStr, '0'), '.');
        }
        if (str_contains($expectedQtyStr, '.')) {
            $expectedQtyStr = rtrim(rtrim($expectedQtyStr, '0'), '.');
        }
        if ($currentQtyStr === '') {
            $currentQtyStr = '0';
        }
        if ($expectedQtyStr === '') {
            $expectedQtyStr = '0';
        }
        if ($currentQtyStr !== $expectedQtyStr) {
            return $refuse(__('mobile.quantity_stale'));
        }

        $salesInvoiceLineId = (int) $lineData['sales_invoice_line_id'];

        /** @var SalesInvoiceLine|null $sourceLine */
        $sourceLine = SalesInvoiceLine::where('id', $salesInvoiceLineId)
            ->where('company_id', $company->id)
            ->where('sales_invoice_id', $this->sales_invoice_id)
            ->whereHas('salesInvoice', function ($q) use ($company) {
                $q->where('company_id', $company->id)
                    ->where('status', SalesInvoice::STATUS_POSTED);
            })
            ->with(['productUnit.unit'])
            ->first();

        if ($sourceLine === null) {
            return $refuse(__('refinement.source_line_not_found'));
        }

        // Recalculate fresh authorized returnable DB cap - do not trust client max_returnable
        $alreadyReturned = (string) DB::table('sales_return_lines')
            ->join('sales_returns', 'sales_return_lines.sales_return_id', '=', 'sales_returns.id')
            ->where('sales_returns.company_id', $company->id)
            ->where('sales_returns.status', SalesReturn::STATUS_POSTED)
            ->where('sales_return_lines.sales_invoice_line_id', $sourceLine->id)
            ->sum('sales_return_lines.quantity');

        $origQty = BigDecimal::of((string) $sourceLine->quantity);
        $priorQty = BigDecimal::of($alreadyReturned ?: '0');
        $freshMaxReturnable = $origQty->minus($priorQty);
        if ($freshMaxReturnable->isNegative()) {
            $freshMaxReturnable = BigDecimal::zero();
        }

        $this->lines[$index]['max_returnable'] = (string) $freshMaxReturnable;
        $this->lines[$index]['already_returned_quantity'] = (string) $priorQty;

        // Resolve unit precision
        $unit = $sourceLine->productUnit?->unit;
        $allowsFraction = $unit ? (bool) $unit->allows_fraction : false;
        $maxDecimals = $allowsFraction ? min(max(0, (int) $unit->decimal_places), 6) : 0;
        foreach ([(string) $sourceLine->quantity, (string) $freshMaxReturnable] as $historicalQuantity) {
            $decPart = explode('.', $historicalQuantity)[1] ?? '';
            $maxDecimals = max($maxDecimals, min(strlen(rtrim($decPart, '0')), 6));
        }

        $stepValue = $maxDecimals > 0
            ? '0.'.str_repeat('0', $maxDecimals - 1).'1'
            : '1';
        $stepBd = BigDecimal::of($stepValue);

        $rawCurrent = trim((string) $lineData['return_quantity']);
        if ($rawCurrent === '') {
            $rawCurrent = '0';
        }

        if (preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $rawCurrent) !== 1) {
            return $refuse(__('refinement.exact_string_required'));
        }

        try {
            $currentBd = BigDecimal::of($rawCurrent);
        } catch (\Throwable) {
            return $refuse(__('refinement.exact_string_required'));
        }

        if ($currentBd->isNegative()) {
            $currentBd = BigDecimal::zero();
        }

        foreach ($directions as $dir) {
            $isIncrement = in_array(strtolower(trim($dir)), ['up', '+', 'increment'], true);
            if ($isIncrement) {
                $currentBd = $currentBd->plus($stepBd);
                if ($currentBd->isGreaterThan($freshMaxReturnable)) {
                    $currentBd = $freshMaxReturnable;
                }
            } else {
                $currentBd = $currentBd->minus($stepBd);
                if ($currentBd->isNegative()) {
                    $currentBd = BigDecimal::zero();
                }
                if ($currentBd->isGreaterThan($freshMaxReturnable)) {
                    $currentBd = $freshMaxReturnable;
                }
            }
        }

        $formatted = $maxDecimals > 0 ? (string) $currentBd : (string) $currentBd->toBigInteger();
        $this->lines[$index]['return_quantity'] = $formatted;
        $this->resetErrorBag("lines.{$index}.return_quantity");

        return ['ok' => true, 'quantity' => $formatted, 'minimumReached' => $currentBd->isZero()];
    }

    /**
     * Step return quantity for a line within 0..currentremaining DB cap.
     */
    public function stepReturnQuantity(int|string $index, string $direction): void
    {
        $idx = (int) $index;
        $line = $this->lines[$idx] ?? null;
        if ($line === null) {
            return;
        }
        $identity = [];
        foreach (DocumentIdentity::FIELDS as $idField) {
            $identity[$idField] = $line[$idField] ?? null;
        }
        $this->batchStepQuantity($idx, $line['return_quantity'], $identity, [$direction], 'return_quantity');
    }

    /**
     * Set return quantity for a line directly to full fresh returnable DB cap.
     */
    public function returnFullRemaining(int|string $index): void
    {
        $company = $this->authorizeReturnAction();

        if (! array_key_exists($index, $this->lines)) {
            return;
        }

        $lineData = $this->lines[$index];
        $salesInvoiceLineId = (int) $lineData['sales_invoice_line_id'];

        /** @var SalesInvoiceLine|null $sourceLine */
        $sourceLine = SalesInvoiceLine::where('id', $salesInvoiceLineId)
            ->where('company_id', $company->id)
            ->where('sales_invoice_id', $this->sales_invoice_id)
            ->whereHas('salesInvoice', function ($q) use ($company) {
                $q->where('company_id', $company->id)
                    ->where('status', SalesInvoice::STATUS_POSTED);
            })
            ->with(['productUnit.unit'])
            ->first();

        if ($sourceLine === null) {
            $this->addError("lines.{$index}.return_quantity", __('refinement.source_line_not_found'));

            return;
        }

        $alreadyReturned = (string) DB::table('sales_return_lines')
            ->join('sales_returns', 'sales_return_lines.sales_return_id', '=', 'sales_returns.id')
            ->where('sales_returns.company_id', $company->id)
            ->where('sales_returns.status', SalesReturn::STATUS_POSTED)
            ->where('sales_return_lines.sales_invoice_line_id', $sourceLine->id)
            ->sum('sales_return_lines.quantity');

        $origQty = BigDecimal::of((string) $sourceLine->quantity);
        $priorQty = BigDecimal::of($alreadyReturned ?: '0');
        $freshMaxReturnable = $origQty->minus($priorQty);
        if ($freshMaxReturnable->isNegative()) {
            $freshMaxReturnable = BigDecimal::zero();
        }

        $this->lines[$index]['max_returnable'] = (string) $freshMaxReturnable;
        $this->lines[$index]['already_returned_quantity'] = (string) $priorQty;

        $unit = $sourceLine->productUnit?->unit;
        $allowsFraction = $unit ? (bool) $unit->allows_fraction : false;
        $maxDecimals = $allowsFraction ? min(max(0, (int) $unit->decimal_places), 6) : 0;
        foreach ([(string) $sourceLine->quantity, (string) $freshMaxReturnable] as $historicalQuantity) {
            $decPart = explode('.', $historicalQuantity)[1] ?? '';
            $maxDecimals = max($maxDecimals, min(strlen(rtrim($decPart, '0')), 6));
        }
        $allowsFraction = $maxDecimals > 0;

        $formatted = $maxDecimals > 0 ? (string) $freshMaxReturnable : (string) $freshMaxReturnable->toBigInteger();
        $this->lines[$index]['return_quantity'] = $formatted;
        $this->resetErrorBag("lines.{$index}.return_quantity");
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
            'sales_invoice_id' => ['required', 'integer', Rule::exists('sales_invoices', 'id')->where('company_id', $company->id)],
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
