<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\UpdatePurchaseReturnDraftAction;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\InventoryBalance;
use App\Models\InventoryLotBalance;
use App\Models\Purchase;
use App\Models\PurchaseLine;
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

    /**
     * Step return quantity for a purchase line within 0..currentremaining DB cap.
     */
    public function stepReturnQuantity(int|string $index, string $direction): void
    {
        $this->authorizePurchasing('purchasing.purchase.view');
        $company = $this->authorizePurchasing('purchasing.return.manage');
        $this->authorizePurchasing('purchasing.cost.view');

        if (! array_key_exists($index, $this->lines)) {
            return;
        }

        $validDirections = ['up', '+', 'increment', 'down', '-', 'decrement'];
        $dir = strtolower(trim($direction));
        if (! in_array($dir, $validDirections, true)) {
            $this->addError("lines.{$index}.return_quantity", __('refinement.invalid_direction'));

            return;
        }

        if ($this->returnId !== null) {
            abort_unless(PurchaseReturn::where('company_id', $company->id)->whereKey($this->returnId)
                ->where('purchase_id', $this->purchaseId)->where('status', PurchaseReturn::STATUS_DRAFT)->exists(), 403);
        }
        $lineData = $this->lines[$index];
        $purchaseLineId = (int) $lineData['purchase_line_id'];

        /** @var PurchaseLine|null $sourceLine */
        $sourceLine = PurchaseLine::where('id', $purchaseLineId)
            ->where('company_id', $company->id)
            ->where('purchase_id', $this->purchaseId)
            ->whereHas('purchase', function ($q) use ($company) {
                $q->where('company_id', $company->id)
                    ->where('status', Purchase::STATUS_POSTED);
            })
            ->with(['productUnit.unit', 'product.baseUnit'])
            ->first();

        if ($sourceLine === null) {
            $this->addError("lines.{$index}.return_quantity", __('refinement.source_line_not_found'));

            return;
        }

        // Recalculate fresh authorized returnable DB cap - do not trust client remaining_quantity
        $alreadyReturned = (string) DB::table('purchase_return_lines')
            ->join('purchase_returns', 'purchase_return_lines.purchase_return_id', '=', 'purchase_returns.id')
            ->where('purchase_returns.company_id', $company->id)
            ->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
            ->where('purchase_return_lines.purchase_line_id', $sourceLine->id)
            ->sum('purchase_return_lines.quantity');

        $origQty = BigDecimal::of((string) $sourceLine->quantity);
        $priorQty = BigDecimal::of($alreadyReturned ?: '0');
        $freshMaxReturnable = $origQty->minus($priorQty);
        if ($freshMaxReturnable->isNegative()) {
            $freshMaxReturnable = BigDecimal::zero();
        }

        $warehouseId = Purchase::where('company_id', $company->id)->whereKey($this->purchaseId)->value('warehouse_id');
        $stockBase = (string) (InventoryBalance::where('company_id', $company->id)
            ->where('product_id', $sourceLine->product_id)->where('warehouse_id', $warehouseId)->value('quantity_base') ?? '0');
        $ratio = BigDecimal::of((string) $sourceLine->unit_conversion_ratio);
        if ($sourceLine->stock_movement_id !== null && $ratio->isPositive()) {
            $stockUnits = BigDecimal::of($stockBase)->dividedBy($ratio, 6, RoundingMode::DOWN);
            $freshMaxReturnable = BigDecimal::min($freshMaxReturnable, BigDecimal::max('0', $stockUnits));
        }
        if ($sourceLine->lots->isNotEmpty() && $ratio->isPositive()) {
            $lotCapacity = BigDecimal::zero();
            foreach ($sourceLine->lots as $originalLot) {
                $priorLot = (string) DB::table('purchase_return_allocations')
                    ->join('purchase_returns', 'purchase_return_allocations.purchase_return_id', '=', 'purchase_returns.id')
                    ->where('purchase_returns.company_id', $company->id)->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
                    ->where('purchase_return_allocations.purchase_line_lot_id', $originalLot->id)->sum('purchase_return_allocations.quantity');
                $lotRemaining = BigDecimal::max('0', BigDecimal::of((string) $originalLot->quantity)->minus($priorLot));
                $lotBase = (string) (InventoryLotBalance::where('company_id', $company->id)->where('product_id', $sourceLine->product_id)
                    ->where('warehouse_id', $warehouseId)->where('lot_id', $originalLot->created_inventory_lot_id)->value('quantity_base') ?? '0');
                $lotAvailable = BigDecimal::max('0', BigDecimal::of($lotBase)->dividedBy($ratio, 6, RoundingMode::DOWN));
                $lotCapacity = $lotCapacity->plus(BigDecimal::min($lotRemaining, $lotAvailable));
            }
            $freshMaxReturnable = BigDecimal::min($freshMaxReturnable, $lotCapacity);
        }
        $this->lines[$index]['remaining_quantity'] = (string) $freshMaxReturnable;
        $this->lines[$index]['prior_returned_quantity'] = (string) $priorQty;

        // Resolve unit precision
        $unit = $sourceLine->productUnit !== null ? $sourceLine->productUnit->unit : $sourceLine->product?->baseUnit;
        $allowsFraction = $unit ? (bool) $unit->allows_fraction : false;
        $maxDecimals = $allowsFraction ? min(max(0, (int) $unit->decimal_places), 6) : 0;
        foreach ([(string) $sourceLine->quantity, (string) $freshMaxReturnable] as $historicalQuantity) {
            $decPart = explode('.', $historicalQuantity)[1] ?? '';
            $maxDecimals = max($maxDecimals, min(strlen(rtrim($decPart, '0')), 6));
        }
        $allowsFraction = $maxDecimals > 0;

        $stepValue = $maxDecimals > 0
            ? '0.'.str_repeat('0', $maxDecimals - 1).'1'
            : '1';

        $rawCurrent = trim((string) $lineData['return_quantity']);
        if ($rawCurrent === '') {
            $rawCurrent = '0';
        }

        if (preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $rawCurrent) !== 1) {
            $this->addError("lines.{$index}.return_quantity", __('refinement.exact_string_required'));

            return;
        }
        $normalizedCurrent = str_contains($rawCurrent, '.') ? rtrim(rtrim($rawCurrent, '0'), '.') : $rawCurrent;
        if (str_contains($normalizedCurrent, '.') && strlen(explode('.', $normalizedCurrent)[1]) > $maxDecimals) {
            $this->addError("lines.{$index}.return_quantity", __('refinement.exact_string_required'));

            return;
        }
        try {
            $currentBd = BigDecimal::of($rawCurrent);
        } catch (\Throwable) {
            $this->addError("lines.{$index}.return_quantity", __('refinement.exact_string_required'));

            return;
        }

        if ($currentBd->isNegative()) {
            $currentBd = BigDecimal::zero();
        }

        $isIncrement = in_array($dir, ['up', '+', 'increment'], true);
        $stepBd = BigDecimal::of($stepValue);

        if ($isIncrement) {
            $newBd = $currentBd->plus($stepBd);
            if ($newBd->isGreaterThan($freshMaxReturnable)) {
                $newBd = $freshMaxReturnable;
            }
        } else {
            $newBd = $currentBd->minus($stepBd);
            if ($newBd->isNegative()) {
                $newBd = BigDecimal::zero();
            }
            if ($newBd->isGreaterThan($freshMaxReturnable)) {
                $newBd = $freshMaxReturnable;
            }
        }

        $formatted = (string) $newBd->toScale($maxDecimals, RoundingMode::DOWN);
        $this->lines[$index]['return_quantity'] = $formatted;
        $this->resetErrorBag("lines.{$index}.return_quantity");
    }

    /**
     * Set return quantity for a purchase line directly to full fresh returnable DB cap.
     */
    public function returnFullRemaining(int|string $index): void
    {
        $this->authorizePurchasing('purchasing.purchase.view');
        $company = $this->authorizePurchasing('purchasing.return.manage');
        $this->authorizePurchasing('purchasing.cost.view');

        if (! array_key_exists($index, $this->lines)) {
            return;
        }

        if ($this->returnId !== null) {
            abort_unless(PurchaseReturn::where('company_id', $company->id)->whereKey($this->returnId)
                ->where('purchase_id', $this->purchaseId)->where('status', PurchaseReturn::STATUS_DRAFT)->exists(), 403);
        }
        $lineData = $this->lines[$index];
        $purchaseLineId = (int) $lineData['purchase_line_id'];

        /** @var PurchaseLine|null $sourceLine */
        $sourceLine = PurchaseLine::where('id', $purchaseLineId)
            ->where('company_id', $company->id)
            ->where('purchase_id', $this->purchaseId)
            ->whereHas('purchase', function ($q) use ($company) {
                $q->where('company_id', $company->id)
                    ->where('status', Purchase::STATUS_POSTED);
            })
            ->with(['productUnit.unit', 'product.baseUnit'])
            ->first();

        if ($sourceLine === null) {
            $this->addError("lines.{$index}.return_quantity", __('refinement.source_line_not_found'));

            return;
        }

        $alreadyReturned = (string) DB::table('purchase_return_lines')
            ->join('purchase_returns', 'purchase_return_lines.purchase_return_id', '=', 'purchase_returns.id')
            ->where('purchase_returns.company_id', $company->id)
            ->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
            ->where('purchase_return_lines.purchase_line_id', $sourceLine->id)
            ->sum('purchase_return_lines.quantity');

        $origQty = BigDecimal::of((string) $sourceLine->quantity);
        $priorQty = BigDecimal::of($alreadyReturned ?: '0');
        $freshMaxReturnable = $origQty->minus($priorQty);
        if ($freshMaxReturnable->isNegative()) {
            $freshMaxReturnable = BigDecimal::zero();
        }

        $warehouseId = Purchase::where('company_id', $company->id)->whereKey($this->purchaseId)->value('warehouse_id');
        $stockBase = (string) (InventoryBalance::where('company_id', $company->id)
            ->where('product_id', $sourceLine->product_id)->where('warehouse_id', $warehouseId)->value('quantity_base') ?? '0');
        $ratio = BigDecimal::of((string) $sourceLine->unit_conversion_ratio);
        if ($sourceLine->stock_movement_id !== null && $ratio->isPositive()) {
            $stockUnits = BigDecimal::of($stockBase)->dividedBy($ratio, 6, RoundingMode::DOWN);
            $freshMaxReturnable = BigDecimal::min($freshMaxReturnable, BigDecimal::max('0', $stockUnits));
        }
        if ($sourceLine->lots->isNotEmpty() && $ratio->isPositive()) {
            $lotCapacity = BigDecimal::zero();
            foreach ($sourceLine->lots as $originalLot) {
                $priorLot = (string) DB::table('purchase_return_allocations')
                    ->join('purchase_returns', 'purchase_return_allocations.purchase_return_id', '=', 'purchase_returns.id')
                    ->where('purchase_returns.company_id', $company->id)->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
                    ->where('purchase_return_allocations.purchase_line_lot_id', $originalLot->id)->sum('purchase_return_allocations.quantity');
                $lotRemaining = BigDecimal::max('0', BigDecimal::of((string) $originalLot->quantity)->minus($priorLot));
                $lotBase = (string) (InventoryLotBalance::where('company_id', $company->id)->where('product_id', $sourceLine->product_id)
                    ->where('warehouse_id', $warehouseId)->where('lot_id', $originalLot->created_inventory_lot_id)->value('quantity_base') ?? '0');
                $lotAvailable = BigDecimal::max('0', BigDecimal::of($lotBase)->dividedBy($ratio, 6, RoundingMode::DOWN));
                $lotCapacity = $lotCapacity->plus(BigDecimal::min($lotRemaining, $lotAvailable));
            }
            $freshMaxReturnable = BigDecimal::min($freshMaxReturnable, $lotCapacity);
        }
        $this->lines[$index]['remaining_quantity'] = (string) $freshMaxReturnable;
        $this->lines[$index]['prior_returned_quantity'] = (string) $priorQty;

        $unit = $sourceLine->productUnit !== null ? $sourceLine->productUnit->unit : $sourceLine->product?->baseUnit;
        $allowsFraction = $unit ? (bool) $unit->allows_fraction : false;
        $maxDecimals = $allowsFraction ? min(max(0, (int) $unit->decimal_places), 6) : 0;
        foreach ([(string) $sourceLine->quantity, (string) $freshMaxReturnable] as $historicalQuantity) {
            $decPart = explode('.', $historicalQuantity)[1] ?? '';
            $maxDecimals = max($maxDecimals, min(strlen(rtrim($decPart, '0')), 6));
        }
        $allowsFraction = $maxDecimals > 0;

        $formatted = $maxDecimals > 0 ? (string) $freshMaxReturnable : (string) $freshMaxReturnable->toScale(0, RoundingMode::DOWN);
        $this->lines[$index]['return_quantity'] = $formatted;
        $this->resetErrorBag("lines.{$index}.return_quantity");
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
