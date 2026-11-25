<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Purchasing;

use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Actions\Purchasing\UpdatePurchaseReturnDraftAction;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Livewire\Pages\Purchasing\Concerns\AuthorizesPurchasingPages;
use App\Models\InventoryBalance;
use App\Models\InventoryLotBalance;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use App\Models\PurchaseReturnLine;
use App\Services\Purchasing\PurchaseReturnAmounts;
use App\Services\Purchasing\PurchaseReturnValidationException;
use App\Support\DocumentIdentity;
use App\Support\QuantityStep;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    public ?string $preReturnTotal = null;

    public ?string $returnSubtotal = null;

    public ?string $returnDiscount = null;

    public ?string $returnTax = null;

    public ?string $returnTotal = null;

    public ?string $postReturnTotal = null;

    public bool $hasReturnQuantities = false;

    public ?string $returnCalculationError = null;

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
            ->with(['lines.product.baseUnit', 'lines.productUnit.unit', 'lines.lots.inventoryLot', 'vendor', 'warehouse'])
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
        $purchaseLineIds = $purchase->lines->pluck('id')->all();
        $priorReturnsByLine = empty($purchaseLineIds) ? collect() : DB::table('purchase_return_lines')
            ->join('purchase_returns', 'purchase_return_lines.purchase_return_id', '=', 'purchase_returns.id')
            ->where('purchase_returns.company_id', $company->id)
            ->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
            ->whereIn('purchase_return_lines.purchase_line_id', $purchaseLineIds)
            ->select('purchase_return_lines.purchase_line_id', DB::raw('SUM(purchase_return_lines.quantity) as total_returned'))
            ->groupBy('purchase_return_lines.purchase_line_id')
            ->pluck('total_returned', 'purchase_line_id');

        $allLotIds = $purchase->lines->flatMap(fn ($l) => $l->lots->pluck('id'))->all();
        $priorReturnsByLot = empty($allLotIds) ? collect() : DB::table('purchase_return_allocations')
            ->join('purchase_returns', 'purchase_return_allocations.purchase_return_id', '=', 'purchase_returns.id')
            ->where('purchase_returns.company_id', $company->id)
            ->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
            ->whereIn('purchase_return_allocations.purchase_line_lot_id', $allLotIds)
            ->select('purchase_return_allocations.purchase_line_lot_id', DB::raw('SUM(purchase_return_allocations.quantity) as total_returned'))
            ->groupBy('purchase_return_allocations.purchase_line_lot_id')
            ->pluck('total_returned', 'purchase_line_lot_id');

        $allCreatedInventoryLotIds = $purchase->lines
            ->flatMap(fn ($l) => $l->lots->pluck('created_inventory_lot_id'))
            ->filter()
            ->unique()
            ->all();

        $lotBalancesByLotId = empty($allCreatedInventoryLotIds) ? collect() : InventoryLotBalance::where('company_id', $company->id)
            ->where('warehouse_id', $purchase->warehouse_id)
            ->whereIn('lot_id', $allCreatedInventoryLotIds)
            ->pluck('quantity_base', 'lot_id');

        foreach ($purchase->lines as $line) {
            $prior = (string) ($priorReturnsByLine[$line->id] ?? '0');
            $origQty = BigDecimal::of((string) $line->quantity);
            $priorQty = BigDecimal::of($prior);
            $remQty = $origQty->minus($priorQty);

            $allocations = [];
            if ($line->product->track_expiry) {
                foreach ($line->lots as $lot) {
                    $lotPrior = (string) ($priorReturnsByLot[$lot->id] ?? '0');
                    $lotOrig = BigDecimal::of((string) $lot->quantity);
                    $lotPriorQty = BigDecimal::of($lotPrior);
                    $lotRem = $lotOrig->minus($lotPriorQty);

                    $lotBalanceBase = (string) ($lotBalancesByLotId[$lot->created_inventory_lot_id] ?? '0');

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
                'quantity_step' => QuantityStep::forUnit($line->productUnit->unit, [(string) $origQty, (string) $remQty]),
                'return_quantity' => '',
                'track_expiry' => (bool) $line->product->track_expiry,
                'allocations' => $allocations,
            ];
        }

        $this->recalculateTotals();
    }

    private function initEdit(string $returnPublicId): void
    {
        $company = $this->authorizePurchasing('purchasing.purchase.view');
        /** @var PurchaseReturn $return */
        $return = PurchaseReturn::where('company_id', $company->id)
            ->where('public_id', $returnPublicId)
            ->with(['purchase.lines.product.baseUnit', 'purchase.lines.productUnit.unit', 'purchase.lines.lots.inventoryLot', 'lines.allocations', 'vendor', 'warehouse'])
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
        $purchaseLineIds = $purchase->lines->pluck('id')->all();
        $priorReturnsByLine = empty($purchaseLineIds) ? collect() : DB::table('purchase_return_lines')
            ->join('purchase_returns', 'purchase_return_lines.purchase_return_id', '=', 'purchase_returns.id')
            ->where('purchase_returns.company_id', $company->id)
            ->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
            ->where('purchase_returns.id', '!=', $return->id)
            ->whereIn('purchase_return_lines.purchase_line_id', $purchaseLineIds)
            ->select('purchase_return_lines.purchase_line_id', DB::raw('SUM(purchase_return_lines.quantity) as total_returned'))
            ->groupBy('purchase_return_lines.purchase_line_id')
            ->pluck('total_returned', 'purchase_line_id');

        $allLotIds = $purchase->lines->flatMap(fn ($l) => $l->lots->pluck('id'))->all();
        $priorReturnsByLot = empty($allLotIds) ? collect() : DB::table('purchase_return_allocations')
            ->join('purchase_returns', 'purchase_return_allocations.purchase_return_id', '=', 'purchase_returns.id')
            ->where('purchase_returns.company_id', $company->id)
            ->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
            ->where('purchase_returns.id', '!=', $return->id)
            ->whereIn('purchase_return_allocations.purchase_line_lot_id', $allLotIds)
            ->select('purchase_return_allocations.purchase_line_lot_id', DB::raw('SUM(purchase_return_allocations.quantity) as total_returned'))
            ->groupBy('purchase_return_allocations.purchase_line_lot_id')
            ->pluck('total_returned', 'purchase_line_lot_id');

        $allCreatedInventoryLotIds = $purchase->lines
            ->flatMap(fn ($l) => $l->lots->pluck('created_inventory_lot_id'))
            ->filter()
            ->unique()
            ->all();

        $lotBalancesByLotId = empty($allCreatedInventoryLotIds) ? collect() : InventoryLotBalance::where('company_id', $company->id)
            ->where('warehouse_id', $purchase->warehouse_id)
            ->whereIn('lot_id', $allCreatedInventoryLotIds)
            ->pluck('quantity_base', 'lot_id');

        foreach ($purchase->lines as $line) {
            $prior = (string) ($priorReturnsByLine[$line->id] ?? '0');
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
                    $lotPrior = (string) ($priorReturnsByLot[$lot->id] ?? '0');
                    $lotOrig = BigDecimal::of((string) $lot->quantity);
                    $lotPriorQty = BigDecimal::of($lotPrior);
                    $lotRem = $lotOrig->minus($lotPriorQty);

                    $lotBalanceBase = (string) ($lotBalancesByLotId[$lot->created_inventory_lot_id] ?? '0');

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
                'quantity_step' => QuantityStep::forUnit($line->productUnit->unit, [(string) $origQty, (string) $remQty]),
                'return_quantity' => $draftQty,
                'track_expiry' => (bool) $line->product->track_expiry,
                'allocations' => $allocations,
            ];
        }

        $this->recalculateTotals();
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
     * Batch step return quantity for a purchase line within 0..currentremaining DB cap.
     *
     * @param  array<string, mixed>  $expectedIdentity
     * @param  array<int, string>  $directions
     * @return array{ok: bool, quantity?: string, minimumReached?: bool, message?: string}
     */
    public function batchStepQuantity(int $index, mixed $expectedQuantity, array $expectedIdentity, array $directions, string $field = 'return_quantity'): array
    {
        $this->authorizePurchasing('purchasing.purchase.view');
        $company = $this->authorizePurchasing('purchasing.return.manage');
        $this->authorizePurchasing('purchasing.cost.view');

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

        if ($this->returnId !== null) {
            abort_unless(PurchaseReturn::where('company_id', $company->id)->whereKey($this->returnId)
                ->where('purchase_id', $this->purchaseId)->where('status', PurchaseReturn::STATUS_DRAFT)->exists(), 403);
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

        $trimmedExpected = is_string($expectedQuantity) ? trim($expectedQuantity) : null;
        if ($trimmedExpected === null || ($trimmedExpected !== '' && preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $trimmedExpected) !== 1)) {
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
            return $refuse(__('refinement.source_line_not_found'));
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
            $lineLotIds = $sourceLine->lots->pluck('id')->all();
            $priorLots = empty($lineLotIds) ? collect() : DB::table('purchase_return_allocations')
                ->join('purchase_returns', 'purchase_return_allocations.purchase_return_id', '=', 'purchase_returns.id')
                ->where('purchase_returns.company_id', $company->id)->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
                ->whereIn('purchase_return_allocations.purchase_line_lot_id', $lineLotIds)
                ->select('purchase_return_allocations.purchase_line_lot_id', DB::raw('SUM(purchase_return_allocations.quantity) as total_returned'))
                ->groupBy('purchase_return_allocations.purchase_line_lot_id')
                ->pluck('total_returned', 'purchase_line_lot_id');

            $createdLotIds = $sourceLine->lots->pluck('created_inventory_lot_id')->filter()->unique()->all();
            $lotBalances = empty($createdLotIds) ? collect() : InventoryLotBalance::where('company_id', $company->id)
                ->where('warehouse_id', $warehouseId)
                ->whereIn('lot_id', $createdLotIds)
                ->pluck('quantity_base', 'lot_id');

            foreach ($sourceLine->lots as $originalLot) {
                $priorLot = (string) ($priorLots[$originalLot->id] ?? '0');
                $lotRemaining = BigDecimal::max('0', BigDecimal::of((string) $originalLot->quantity)->minus($priorLot));
                $lotBase = (string) ($lotBalances[$originalLot->created_inventory_lot_id] ?? '0');
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

        $formatted = (string) $currentBd->toScale($maxDecimals, RoundingMode::DOWN);
        $this->lines[$index]['return_quantity'] = $formatted;
        $this->resetErrorBag("lines.{$index}.return_quantity");
        $this->recalculateTotals();

        return ['ok' => true, 'quantity' => $formatted, 'minimumReached' => $currentBd->isZero()];
    }

    /**
     * Step return quantity for a purchase line within 0..currentremaining DB cap.
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
            $lineLotIds = $sourceLine->lots->pluck('id')->all();
            $priorLots = empty($lineLotIds) ? collect() : DB::table('purchase_return_allocations')
                ->join('purchase_returns', 'purchase_return_allocations.purchase_return_id', '=', 'purchase_returns.id')
                ->where('purchase_returns.company_id', $company->id)->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
                ->whereIn('purchase_return_allocations.purchase_line_lot_id', $lineLotIds)
                ->select('purchase_return_allocations.purchase_line_lot_id', DB::raw('SUM(purchase_return_allocations.quantity) as total_returned'))
                ->groupBy('purchase_return_allocations.purchase_line_lot_id')
                ->pluck('total_returned', 'purchase_line_lot_id');

            $createdLotIds = $sourceLine->lots->pluck('created_inventory_lot_id')->filter()->unique()->all();
            $lotBalances = empty($createdLotIds) ? collect() : InventoryLotBalance::where('company_id', $company->id)
                ->where('warehouse_id', $warehouseId)
                ->whereIn('lot_id', $createdLotIds)
                ->pluck('quantity_base', 'lot_id');

            foreach ($sourceLine->lots as $originalLot) {
                $priorLot = (string) ($priorLots[$originalLot->id] ?? '0');
                $lotRemaining = BigDecimal::max('0', BigDecimal::of((string) $originalLot->quantity)->minus($priorLot));
                $lotBase = (string) ($lotBalances[$originalLot->created_inventory_lot_id] ?? '0');
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
        $this->recalculateTotals();
    }

    public function recalculate(): void
    {
        $this->recalculateTotals();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'lines.') && str_ends_with($property, '.return_quantity')) {
            $this->recalculateTotals();
        }
    }

    public function recalculateTotals(): void
    {
        if (! isset($this->purchaseId)) {
            $this->resetTotals();

            return;
        }

        $company = app(CompanyContext::class)->company();

        /** @var Purchase|null $purchase */
        $purchase = Purchase::where('company_id', $company->id)
            ->where('id', $this->purchaseId)
            ->where('status', Purchase::STATUS_POSTED)
            ->first();

        if ($purchase === null) {
            $this->resetTotals();

            return;
        }

        // Avoid leaking restricted cost values: verify purchasing.cost.view
        if (auth()->check() && ! auth()->user()->hasPermissionTo('purchasing.cost.view')) {
            $this->resetTotals();

            return;
        }

        $this->currencyCode = (string) $purchase->currency_code;

        $priorReturnsQuery = DB::table('purchase_returns')
            ->where('company_id', $company->id)
            ->where('purchase_id', $this->purchaseId)
            ->where('status', PurchaseReturn::STATUS_POSTED);

        if ($this->returnId !== null) {
            $priorReturnsQuery->where('id', '!=', $this->returnId);
        }

        $priorReturnsTotal = (string) $priorReturnsQuery->sum('grand_total_currency');
        $origPurchaseTotal = BigDecimal::of((string) $purchase->grand_total_currency);
        $preReturn = $origPurchaseTotal->minus(BigDecimal::of($priorReturnsTotal ?: '0'));
        $this->preReturnTotal = (string) $preReturn->toScale(6);

        $sourceLineIds = [];
        foreach ($this->lines as $line) {
            $sourceLineIds[] = (int) $line['purchase_line_id'];
        }

        if (empty($sourceLineIds)) {
            $this->resetCalculatedTotals();

            return;
        }

        $sourceLines = PurchaseLine::where('company_id', $company->id)
            ->where('purchase_id', $this->purchaseId)
            ->whereIn('id', $sourceLineIds)
            ->get()
            ->keyBy('id');

        $allPriorReturnLines = PurchaseReturnLine::where('company_id', $company->id)
            ->whereIn('purchase_line_id', $sourceLines->keys())
            ->when($this->returnId !== null, fn ($q) => $q->where('purchase_return_id', '!=', $this->returnId))
            ->whereHas('purchaseReturn', fn ($q) => $q->where('company_id', $company->id)->where('status', PurchaseReturn::STATUS_POSTED))
            ->get()
            ->groupBy('purchase_line_id');

        $returnSubtotal = BigDecimal::zero();
        $returnDiscount = BigDecimal::zero();
        $returnTax = BigDecimal::zero();
        $returnTotal = BigDecimal::zero();
        $hasPositiveReturn = false;

        $calculator = app(PurchaseReturnAmounts::class);

        foreach ($this->lines as $line) {
            $qtyStr = trim((string) $line['return_quantity']);
            if ($qtyStr === '' || $qtyStr === '0') {
                continue;
            }

            if (preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $qtyStr) !== 1) {
                $this->returnCalculationError = __('purchasing.invalid_return_quantity');
                $this->resetCalculatedTotals();

                return;
            }

            try {
                $qty = BigDecimal::of($qtyStr);
            } catch (\Throwable) {
                $this->returnCalculationError = __('purchasing.invalid_return_quantity');
                $this->resetCalculatedTotals();

                return;
            }

            if (! $qty->isPositive()) {
                continue;
            }

            $purchaseLineId = (int) $line['purchase_line_id'];
            $sourceLine = $sourceLines->get($purchaseLineId);
            if ($sourceLine === null) {
                $this->returnCalculationError = __('refinement.source_line_not_found');
                $this->resetCalculatedTotals();

                return;
            }

            $priorLinesForLine = $allPriorReturnLines->get($sourceLine->id, collect());
            $priorQty = BigDecimal::zero();
            foreach ($priorLinesForLine as $prev) {
                $priorQty = $priorQty->plus($prev->quantity);
            }
            $serverMaxReturnable = BigDecimal::max('0', BigDecimal::of((string) $sourceLine->quantity)->minus($priorQty));

            if ($qty->isGreaterThan($serverMaxReturnable)) {
                $this->returnCalculationError = __('purchasing.return_quantity_exceeds_remaining');
                $this->resetCalculatedTotals();

                return;
            }

            try {
                $returnRecord = $this->returnId !== null
                    ? PurchaseReturn::where('company_id', $company->id)->where('id', $this->returnId)->first()
                    : null;
                /** @var int|null $frozenPrecision */
                $frozenPrecision = $returnRecord?->getAttribute('currency_minor_units')
                    ?? $purchase->getAttribute('currency_minor_units');
                $lineAmounts = $calculator->calculateLine(
                    $sourceLine,
                    $qty,
                    $priorLinesForLine,
                    $purchase->currency_code,
                    $frozenPrecision
                );
                $returnSubtotal = $returnSubtotal->plus($lineAmounts['subtotal']);
                $returnDiscount = $returnDiscount->plus($lineAmounts['discount']);
                $returnTax = $returnTax->plus($lineAmounts['tax']);
                $returnTotal = $returnTotal->plus($lineAmounts['total']);
                $hasPositiveReturn = true;
            } catch (\Throwable) {
                $this->returnCalculationError = __('purchasing.return_calculation_failed');
                $this->resetCalculatedTotals();

                return;
            }
        }

        if (! $hasPositiveReturn) {
            $this->returnCalculationError = null;
            $this->resetCalculatedTotals();

            return;
        }

        $this->returnCalculationError = null;
        $this->hasReturnQuantities = $hasPositiveReturn;
        $this->returnSubtotal = (string) $returnSubtotal->toScale(6);
        $this->returnDiscount = (string) $returnDiscount->toScale(6);
        $this->returnTax = (string) $returnTax->toScale(6);
        $this->returnTotal = (string) $returnTotal->toScale(6);
        $this->postReturnTotal = (string) $preReturn->minus($returnTotal)->toScale(6);
    }

    private function resetCalculatedTotals(): void
    {
        $this->returnSubtotal = null;
        $this->returnDiscount = null;
        $this->returnTax = null;
        $this->returnTotal = null;
        $this->postReturnTotal = null;
        $this->hasReturnQuantities = false;
    }

    private function resetTotals(): void
    {
        $this->currencyCode = '';
        $this->preReturnTotal = null;
        $this->returnCalculationError = null;
        $this->resetCalculatedTotals();
    }

    public function saveAndConfirm(): void
    {
        $this->saveDraft(true);
    }

    public function saveDraft(bool $confirm = false): void
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
            if (preg_match('/for (?:purchase )?line \[(\d+)\]/', $e->getMessage(), $matches)) {
                $failedLineId = (int) $matches[1];
                foreach ($this->lines as $idx => $l) {
                    if ((int) $l['purchase_line_id'] === $failedLineId) {
                        $this->addError("lines.{$idx}.return_quantity", __($e->translationKey, $e->translationParams));
                        break;
                    }
                }
            }

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
            if (preg_match('/for (?:purchase )?line \[(\d+)\]/', $e->getMessage(), $matches)) {
                $failedLineId = (int) $matches[1];
                foreach ($this->lines as $idx => $l) {
                    if ((int) $l['purchase_line_id'] === $failedLineId) {
                        $this->addError("lines.{$idx}.return_quantity", __('purchasing.return_quantity_exceeds_remaining'));
                        break;
                    }
                }
            }

            return;
        }

        // A failed confirmation retries this same draft rather than creating another one.
        $this->mode = 'edit';
        $this->returnId = (int) $return->id;
        $this->returnPublicId = $return->public_id;
        if ($confirm) {
            try {
                app(PostPurchaseReturnAction::class)->execute($return, auth()->user());
            } catch (InvalidArgumentException|\DomainException|ModelNotFoundException|InvalidInventoryMovementException|InvalidUnitConversionException|InvalidQuantityException $exception) {
                $this->addError('save', __('purchasing.return_integrity_failed'));

                return;
            }
        }
        session()->flash('success', __($confirm ? 'purchasing.purchase_return_posted' : 'purchasing.purchase_return_saved'));
        $this->redirect(route('purchase-returns.show', $return->public_id), navigate: true);
    }
}
