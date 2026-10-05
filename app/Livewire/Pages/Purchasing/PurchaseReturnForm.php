<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\UpdatePurchaseReturnDraftAction;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\InventoryLotBalance;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\PurchaseReturnLine;
use App\Services\Purchasing\PurchaseReturnValidationException;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseReturnForm extends Component
{
    use AuthorizesPurchasingPages;

    #[Locked]
    public string $mode = 'create';

    #[Locked]
    public string $publicId;

    #[Locked]
    public int $purchaseId;

    #[Locked]
    public ?int $returnId = null;

    #[Locked]
    public ?string $returnPublicId = null;

    public string $purchaseNumber = '';

    public string $purchasePublicId = '';

    public string $purchaseDate = '';

    public string $vendorName = '';

    public string $warehouseName = '';

    public string $currencyCode = '';

    public string $return_date = '';

    public string $reason = '';

    public string $notes = '';

    /**
     * @var list<array{
     *     purchase_line_id: int,
     *     item_description: string,
     *     product_sku: string,
     *     unit_name: string,
     *     purchased_quantity: string,
     *     prior_returned_quantity: string,
     *     remaining_quantity: string,
     *     unit_cost: string,
     *     return_quantity: string,
     *     track_expiry: bool,
     *     allocations: list<array{
     *         original_stock_movement_id: int,
     *         purchase_line_lot_id: ?int,
     *         inventory_lot_id: ?int,
     *         lot_number: ?string,
     *         expiry_date: ?string,
     *         original_quantity: string,
     *         prior_returned_quantity: string,
     *         remaining_quantity: string,
     *         available_quantity: string,
     *         quantity: string
     *     }>
     * }>
     */
    public array $lines = [];

    public function mount(string $publicId, CompanyContext $context): void
    {
        $this->pageCompanyId = $context->companyId();
        $this->authorizePurchasing('purchasing.purchase.view');
        $company = $this->authorizePurchasing('purchasing.return.manage');
        $this->authorizePurchasing('purchasing.cost.view');

        $this->publicId = $publicId;
        $isCreate = request()->routeIs('purchase-returns.create')
            || (! PurchaseReturn::where('company_id', $company->id)->where('public_id', $publicId)->exists()
                && Purchase::where('company_id', $company->id)->where('public_id', $publicId)->exists());

        if ($isCreate) {
            $this->initCreate($publicId);
        } else {
            $this->initEdit($publicId);
        }
    }

    private function initCreate(string $purchasePublicId): void
    {
        $company = $this->authorizePurchasing('purchasing.purchase.view');
        /** @var Purchase $purchase */
        $purchase = Purchase::where('company_id', $company->id)
            ->where('public_id', $purchasePublicId)
            ->with(['lines.product', 'lines.lots.inventoryLot', 'vendor', 'warehouse'])
            ->firstOrFail();

        if (! $purchase->isPosted()) {
            abort(404, 'Only posted purchases can be returned.');
        }

        $this->mode = 'create';
        $this->purchaseId = $purchase->id;
        $this->purchasePublicId = $purchase->public_id;
        $this->purchaseNumber = $purchase->purchase_number ?? '';
        $this->purchaseDate = $purchase->purchase_date->format('Y-m-d');
        $this->vendorName = $purchase->vendor->displayName();
        $this->warehouseName = $purchase->warehouse->displayName();
        $this->currencyCode = $purchase->currency_code;

        $today = Carbon::now($company->timezone)->toDateString();
        $this->return_date = $today >= $this->purchaseDate ? $today : $this->purchaseDate;

        $this->lines = [];
        foreach ($purchase->lines as $line) {
            $prior = (string) DB::table('purchase_return_lines')
                ->join('purchase_returns', 'purchase_return_lines.purchase_return_id', '=', 'purchase_returns.id')
                ->where('purchase_returns.company_id', $company->id)
                ->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
                ->where('purchase_return_lines.purchase_line_id', $line->id)
                ->sum('purchase_return_lines.quantity');

            $origQty = BigDecimal::of((string) $line->quantity);
            $priorQty = BigDecimal::of($prior);
            $remQty = $origQty->minus($priorQty);

            $allocations = [];
            if ($line->product->track_expiry) {
                foreach ($line->lots as $lot) {
                    $lotPrior = (string) DB::table('purchase_return_allocations')
                        ->join('purchase_returns', 'purchase_return_allocations.purchase_return_id', '=', 'purchase_returns.id')
                        ->where('purchase_returns.company_id', $company->id)
                        ->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
                        ->where('purchase_return_allocations.purchase_line_lot_id', $lot->id)
                        ->sum('purchase_return_allocations.quantity');

                    $lotOrig = BigDecimal::of((string) $lot->quantity);
                    $lotPriorQty = BigDecimal::of($lotPrior);
                    $lotRem = $lotOrig->minus($lotPriorQty);

                    $lotBalanceBase = (string) (InventoryLotBalance::where('company_id', $company->id)
                        ->where('product_id', $line->product_id)
                        ->where('warehouse_id', $purchase->warehouse_id)
                        ->where('lot_id', $lot->created_inventory_lot_id)
                        ->value('quantity_base') ?? '0');

                    $ratio = BigDecimal::of((string) $line->unit_conversion_ratio);
                    $onHand = $ratio->isPositive()
                        ? (string) BigDecimal::of($lotBalanceBase)->dividedBy($ratio, 4, RoundingMode::DOWN)
                        : '0';

                    $allocations[] = [
                        'original_stock_movement_id' => (int) $lot->stock_movement_id,
                        'purchase_line_lot_id' => (int) $lot->id,
                        'inventory_lot_id' => (int) $lot->created_inventory_lot_id,
                        'lot_number' => $lot->lot_number,
                        'expiry_date' => $lot->expiry_date?->format('Y-m-d'),
                        'original_quantity' => (string) $lotOrig,
                        'prior_returned_quantity' => (string) $lotPriorQty,
                        'remaining_quantity' => (string) $lotRem,
                        'available_quantity' => $onHand,
                        'quantity' => '',
                    ];
                }
            }

            $this->lines[] = [
                'purchase_line_id' => (int) $line->id,
                'item_description' => $line->item_description,
                'product_sku' => $line->product_sku,
                'unit_name' => app()->getLocale() === 'en' ? ($line->unit_name_en ?? $line->unit_name_ar) : $line->unit_name_ar,
                'purchased_quantity' => (string) $origQty,
                'prior_returned_quantity' => (string) $priorQty,
                'remaining_quantity' => (string) $remQty,
                'unit_cost' => (string) $line->unit_cost,
                'return_quantity' => '',
                'track_expiry' => (bool) $line->product->track_expiry,
                'allocations' => $allocations,
            ];
        }
    }

    private function initEdit(string $returnPublicId): void
    {
        $company = $this->authorizePurchasing('purchasing.purchase.view');
        /** @var PurchaseReturn $return */
        $return = PurchaseReturn::where('company_id', $company->id)
            ->where('public_id', $returnPublicId)
            ->with(['purchase.lines.product', 'purchase.lines.lots.inventoryLot', 'lines.allocations', 'vendor', 'warehouse'])
            ->firstOrFail();

        if (! $return->isDraft()) {
            abort(404, 'Only draft returns can be edited.');
        }

        $purchase = $return->purchase;
        $this->mode = 'edit';
        $this->returnId = $return->id;
        $this->returnPublicId = $return->public_id;
        $this->purchaseId = $purchase->id;
        $this->purchasePublicId = $purchase->public_id;
        $this->purchaseNumber = $purchase->purchase_number ?? '';
        $this->purchaseDate = $purchase->purchase_date->format('Y-m-d');
        $this->vendorName = $purchase->vendor->displayName();
        $this->warehouseName = $purchase->warehouse->displayName();
        $this->currencyCode = $purchase->currency_code;
        $this->return_date = $return->return_date->format('Y-m-d');
        $this->reason = $return->reason ?? '';
        $this->notes = $return->notes ?? '';

        $existingLinesByPurchaseLine = $return->lines->keyBy('purchase_line_id');

        $this->lines = [];
        foreach ($purchase->lines as $line) {
            $prior = (string) DB::table('purchase_return_lines')
                ->join('purchase_returns', 'purchase_return_lines.purchase_return_id', '=', 'purchase_returns.id')
                ->where('purchase_returns.company_id', $company->id)
                ->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
                ->where('purchase_return_lines.purchase_line_id', $line->id)
                ->sum('purchase_return_lines.quantity');

            $origQty = BigDecimal::of((string) $line->quantity);
            $priorQty = BigDecimal::of($prior);
            $remQty = $origQty->minus($priorQty);

            /** @var PurchaseReturnLine|null $draftLine */
            $draftLine = $existingLinesByPurchaseLine->get($line->id);
            $draftQty = $draftLine !== null ? (string) $draftLine->quantity : '';

            $allocations = [];
            if ($line->product->track_expiry) {
                $draftAllocByLotId = $draftLine !== null ? $draftLine->allocations->keyBy('purchase_line_lot_id') : collect();
                foreach ($line->lots as $lot) {
                    $lotPrior = (string) DB::table('purchase_return_allocations')
                        ->join('purchase_returns', 'purchase_return_allocations.purchase_return_id', '=', 'purchase_returns.id')
                        ->where('purchase_returns.company_id', $company->id)
                        ->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
                        ->where('purchase_return_allocations.purchase_line_lot_id', $lot->id)
                        ->sum('purchase_return_allocations.quantity');

                    $lotOrig = BigDecimal::of((string) $lot->quantity);
                    $lotPriorQty = BigDecimal::of($lotPrior);
                    $lotRem = $lotOrig->minus($lotPriorQty);

                    $lotBalanceBase = (string) (InventoryLotBalance::where('company_id', $company->id)
                        ->where('product_id', $line->product_id)
                        ->where('warehouse_id', $purchase->warehouse_id)
                        ->where('lot_id', $lot->created_inventory_lot_id)
                        ->value('quantity_base') ?? '0');

                    $ratio = BigDecimal::of((string) $line->unit_conversion_ratio);
                    $onHand = $ratio->isPositive()
                        ? (string) BigDecimal::of($lotBalanceBase)->dividedBy($ratio, 4, RoundingMode::DOWN)
                        : '0';

                    /** @var PurchaseReturnAllocation|null $existingAlloc */
                    $existingAlloc = $draftAllocByLotId->get($lot->id);
                    $allocQty = $existingAlloc !== null ? (string) $existingAlloc->quantity : '';

                    $allocations[] = [
                        'original_stock_movement_id' => (int) $lot->stock_movement_id,
                        'purchase_line_lot_id' => (int) $lot->id,
                        'inventory_lot_id' => (int) $lot->created_inventory_lot_id,
                        'lot_number' => $lot->lot_number,
                        'expiry_date' => $lot->expiry_date?->format('Y-m-d'),
                        'original_quantity' => (string) $lotOrig,
                        'prior_returned_quantity' => (string) $lotPriorQty,
                        'remaining_quantity' => (string) $lotRem,
                        'available_quantity' => $onHand,
                        'quantity' => $allocQty,
                    ];
                }
            }

            $this->lines[] = [
                'purchase_line_id' => (int) $line->id,
                'item_description' => $line->item_description,
                'product_sku' => $line->product_sku,
                'unit_name' => app()->getLocale() === 'en' ? ($line->unit_name_en ?? $line->unit_name_ar) : $line->unit_name_ar,
                'purchased_quantity' => (string) $origQty,
                'prior_returned_quantity' => (string) $priorQty,
                'remaining_quantity' => (string) $remQty,
                'unit_cost' => (string) $line->unit_cost,
                'return_quantity' => $draftQty,
                'track_expiry' => (bool) $line->product->track_expiry,
                'allocations' => $allocations,
            ];
        }
    }

    public function boot(): void
    {
        if (isset($this->pageCompanyId)) {
            $this->authorizePurchasing('purchasing.purchase.view');
            $this->authorizePurchasing('purchasing.return.manage');
            $this->authorizePurchasing('purchasing.cost.view');
        }
    }

    public function render(): View
    {
        if (isset($this->pageCompanyId)) {
            $this->authorizePurchasing('purchasing.purchase.view');
            $this->authorizePurchasing('purchasing.return.manage');
            $this->authorizePurchasing('purchasing.cost.view');
        }

        return view('livewire.pages.purchasing.purchase-return-form');
    }

    public function saveDraft(): void
    {
        $this->authorizePurchasing('purchasing.purchase.view');
        $company = $this->authorizePurchasing('purchasing.return.manage');
        $this->authorizePurchasing('purchasing.cost.view');

        $this->resetErrorBag();

        if (trim($this->return_date) === '') {
            $this->addError('return_date', __('purchasing.return_date_required'));

            return;
        }

        if ($this->return_date < $this->purchaseDate) {
            $this->addError('return_date', __('purchasing.return_date_before_purchase'));

            return;
        }

        $actionLines = [];
        foreach ($this->lines as $idx => $line) {
            $qtyStr = trim($line['return_quantity']);
            if ($qtyStr === '' || $qtyStr === '0') {
                continue;
            }

            try {
                $qty = BigDecimal::of($qtyStr);
            } catch (\Exception $e) {
                $this->addError("lines.{$idx}.return_quantity", __('purchasing.invalid_line_amounts'));

                return;
            }

            if (! $qty->isPositive()) {
                continue;
            }

            $rem = BigDecimal::of($line['remaining_quantity']);
            if ($qty->isGreaterThan($rem)) {
                $this->addError("lines.{$idx}.return_quantity", __('purchasing.return_quantity_exceeds_remaining'));

                return;
            }

            $lineData = [
                'purchase_line_id' => $line['purchase_line_id'],
                'quantity' => (string) $qty,
            ];

            if ($line['track_expiry']) {
                $allocSum = BigDecimal::zero();
                $lineAllocations = [];
                foreach ($line['allocations'] as $aIdx => $alloc) {
                    $aQtyStr = trim($alloc['quantity']);
                    if ($aQtyStr === '' || $aQtyStr === '0') {
                        continue;
                    }

                    try {
                        $aQty = BigDecimal::of($aQtyStr);
                    } catch (\Exception $e) {
                        $this->addError("lines.{$idx}.allocations.{$aIdx}.quantity", __('purchasing.invalid_line_amounts'));

                        return;
                    }

                    if ($aQty->isPositive()) {
                        $lineAllocations[] = [
                            'original_stock_movement_id' => $alloc['original_stock_movement_id'],
                            'purchase_line_lot_id' => $alloc['purchase_line_lot_id'],
                            'inventory_lot_id' => $alloc['inventory_lot_id'],
                            'quantity' => (string) $aQty,
                        ];
                        $allocSum = $allocSum->plus($aQty);
                    }
                }

                if (! $allocSum->isEqualTo($qty)) {
                    $this->addError("lines.{$idx}.return_quantity", __('purchasing.lot_allocation_mismatch'));

                    return;
                }

                $lineData['allocations'] = $lineAllocations;
            }

            $actionLines[] = $lineData;
        }

        if (empty($actionLines)) {
            $this->addError('lines', __('purchasing.at_least_one_return_line_required'));

            return;
        }

        try {
            if ($this->mode === 'create') {
                $action = app(CreatePurchaseReturnDraftAction::class);
                $return = $action->execute($company, auth()->user(), [
                    'purchase_id' => $this->purchaseId,
                    'return_date' => $this->return_date,
                    'reason' => trim($this->reason) !== '' ? trim($this->reason) : null,
                    'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
                    'lines' => $actionLines,
                ]);
            } else {
                $action = app(UpdatePurchaseReturnDraftAction::class);
                $return = PurchaseReturn::where('company_id', $company->id)
                    ->where('id', $this->returnId)
                    ->firstOrFail();

                $return = $action->execute($return, auth()->user(), [
                    'return_date' => $this->return_date,
                    'reason' => trim($this->reason) !== '' ? trim($this->reason) : null,
                    'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
                    'lines' => $actionLines,
                ]);
            }
        } catch (PurchaseReturnValidationException $e) {
            $this->addError('save', __($e->translationKey, $e->translationParams));

            return;
        } catch (InvalidArgumentException $e) {
            $msg = match (true) {
                str_starts_with($e->getMessage(), 'Requested return quantity') => __('purchasing.return_quantity_exceeds_remaining'),
                $e->getMessage() === 'Original warehouse is inactive.' => __('purchasing.inactive_warehouse'),
                $e->getMessage() === 'Return date cannot be earlier than original purchase date.' => __('purchasing.return_date_before_purchase'),
                $e->getMessage() === 'Purchase return must contain at least one line.' => __('purchasing.at_least_one_return_line_required'),
                $e->getMessage() === 'Purchase return line quantity must be positive.' => __('purchasing.positive_return_quantity_required'),
                $e->getMessage() === 'Purchase return line quantity exceeds remaining returnable quantity.' => __('purchasing.return_quantity_exceeds_remaining'),
                $e->getMessage() === 'Allocations required for expiry-tracked product.' => __('purchasing.allocations_required_for_expiry'),
                $e->getMessage() === 'Allocations are not supported for non-expiry products.' => __('purchasing.allocations_unsupported_for_non_expiry'),
                $e->getMessage() === 'Expiry allocation quantity must be positive.' => __('purchasing.positive_allocation_quantity_required'),
                $e->getMessage() === 'Sum of allocations must match line quantity.' => __('purchasing.lot_allocation_mismatch'),
                $e->getMessage() === 'Allocation quantity exceeds remaining lot quantity.' => __('purchasing.allocation_quantity_exceeds_lot'),
                $e->getMessage() === 'Original stock movement does not belong to this purchase lot.' => __('purchasing.invalid_lot_movement'),
                $e->getMessage() === 'Original stock movement not found.' => __('purchasing.lot_movement_not_found'),
                $e->getMessage() === 'Original transaction currency is disabled in the company.' => __('purchasing.disabled_currency'),
                $e->getMessage() === 'Historical purchase tax account is missing or inactive.' => __('purchasing.inactive_tax_account'),
                default => __('purchasing.draft_save_failed'),
            };
            $this->addError('save', $msg);

            return;
        }

        session()->flash('success', __('purchasing.purchase_return_saved'));
        $this->redirect(route('purchase-returns.show', $return->public_id), navigate: true);
    }
}
