<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\SalesReturnLotAllocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Posting\AccountingReversalService;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class VoidSalesReturnAction
{
    public function __construct(
        protected InventoryMovementService $inventoryMovementService,
        protected AccountingReversalService $accountingReversalService,
    ) {}

    public function execute(SalesReturn $salesReturn, User $user, ?string $reason = null): SalesReturn
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $salesReturn->company_id) {
            throw new NoActiveCompanyException("Active company context does not match return company [{$salesReturn->company_id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $user->id) {
            throw new AuthorizationException('Actor must be authenticated and match user.');
        }

        if (! $user->belongsToCompany($salesReturn->company_id)) {
            throw new AuthorizationException("User does not belong to company [{$salesReturn->company_id}].");
        }

        setPermissionsTeamId($salesReturn->company_id);

        if (! $user->hasPermissionTo('sales.return.void')) {
            throw new AuthorizationException('User does not have permission to void sales returns.');
        }

        return DB::transaction(function () use ($salesReturn, $user, $reason): SalesReturn {
            Company::where('id', $salesReturn->company_id)->lockForUpdate()->firstOrFail();
            app(SalesActorGuard::class)->lockAndAuthorize((int) $salesReturn->company_id, $user, 'sales.return.void');

            /** @var SalesReturn $lockedReturn */
            $lockedReturn = SalesReturn::where('id', $salesReturn->id)->lockForUpdate()->firstOrFail();

            if ($lockedReturn->isVoid()) {
                return $lockedReturn;
            }

            if (! $lockedReturn->isPosted()) {
                throw new InvalidArgumentException("Cannot void return in [{$lockedReturn->status}] status.");
            }

            // Compensating outbound stock removal
            $returnLines = SalesReturnLine::where('sales_return_id', $lockedReturn->id)->get();
            $compensatingLines = [];

            $restorations = SalesReturnLotAllocation::where('company_id', $lockedReturn->company_id)
                ->where('sales_return_id', $lockedReturn->id)->orderBy('id')->get();
            foreach ($restorations as $allocation) {
                $originalMovement = $allocation->stockMovement;
                if ($originalMovement === null) {
                    throw new InvalidArgumentException('Missing immutable returned-stock allocation.');
                }
                $product = Product::whereKey($originalMovement->product_id)->firstOrFail();
                $compensatingLines[] = new StockMovementLineCommand(
                    productId: $product->id, warehouseId: (int) $originalMovement->warehouse_id,
                    quantity: Quantity::of((string) $allocation->quantity_allocated_base),
                    unitId: $product->base_unit_id, lotId: $allocation->inventory_lot_id
                );
            }

            if (! empty($compensatingLines)) {
                $stockCmd = new StockMovementCommand(
                    companyId: $lockedReturn->company_id,
                    movementType: StockMovement::TYPE_SALE,
                    movementDate: Carbon::today($lockedReturn->company->timezone)->toDateString(),
                    lines: $compensatingLines,
                    sourceType: 'sales_return_void',
                    sourceId: $lockedReturn->id,
                    idempotencyKey: "sales_return_{$lockedReturn->id}_void_stock",
                    createdBy: $user->id,
                    reason: "Void of Sales Return {$lockedReturn->return_number}",
                );

                $movements = $this->inventoryMovementService->record($stockCmd);
                $expectedByProduct = [];
                foreach ($restorations as $allocation) {
                    $id = (int) $allocation->stockMovement->product_id;
                    $expectedByProduct[$id] = ($expectedByProduct[$id] ?? BigDecimal::zero())->plus($allocation->total_cost_base);
                }
                $removedByProduct = [];
                $removedValue = BigDecimal::zero();
                foreach ($movements as $movement) {
                    $id = (int) $movement->product_id;
                    $removedByProduct[$id] = ($removedByProduct[$id] ?? BigDecimal::zero())->plus(BigDecimal::of($movement->value_delta_base)->abs());
                    $removedValue = $removedValue->plus(BigDecimal::of((string) $movement->value_delta_base)->abs());
                }
                foreach ($expectedByProduct as $id => $expected) {
                    if (! $expected->isEqualTo($removedByProduct[$id] ?? BigDecimal::zero())) {
                        throw new InvalidArgumentException('Return valuation cannot be reversed coherently for this product.');
                    }
                }
                if (! $removedValue->isEqualTo(BigDecimal::of((string) $lockedReturn->cogs_total_base))) {
                    throw new InvalidArgumentException('Return cannot be voided safely after inventory valuation changed. Use a reviewed correction workflow.');
                }
            }

            // Reversal of accounting batch
            $reversalBatch = null;
            if ($lockedReturn->posting_batch_id !== null) {
                $postingBatch = $lockedReturn->postingBatch;
                if ($postingBatch !== null && ! $postingBatch->isReversed()) {
                    $reversalBatch = $this->accountingReversalService->reverse(
                        $postingBatch,
                        $user,
                        $reason ?? "Void of Sales Return {$lockedReturn->return_number}"
                    );
                }
            }

            if ($reversalBatch === null) {
                throw new InvalidArgumentException('Canonical accounting reversal is required before void.');
            }
            $lockedReturn->completeCanonicalVoid($reversalBatch, $user, $reason);

            return $lockedReturn->fresh(['lines', 'postingBatch', 'voidPostingBatch']);
        });
    }
}
