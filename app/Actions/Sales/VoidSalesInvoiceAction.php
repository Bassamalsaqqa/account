<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\CustomerPaymentAllocation;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLotAllocation;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Posting\AccountingReversalService;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class VoidSalesInvoiceAction
{
    public function __construct(
        protected InventoryMovementService $inventoryMovementService,
        protected AccountingReversalService $accountingReversalService,
    ) {}

    public function execute(SalesInvoice $invoice, User $user, ?string $reason = null): SalesInvoice
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || (int) $context->companyId() !== (int) $invoice->company_id) {
            throw new NoActiveCompanyException("Active company context does not match invoice company [{$invoice->company_id}].");
        }

        if (! auth()->check() || (int) auth()->id() !== (int) $user->id) {
            throw new AuthorizationException('Actor must be authenticated and match user.');
        }

        if (! $user->belongsToCompany($invoice->company_id)) {
            throw new AuthorizationException("User does not belong to company [{$invoice->company_id}].");
        }

        setPermissionsTeamId($invoice->company_id);

        if (! $user->hasPermissionTo('sales.invoice.void')) {
            throw new AuthorizationException('User does not have permission to void sales invoices.');
        }

        return DB::transaction(function () use ($invoice, $user, $reason): SalesInvoice {
            // Lock company FOR UPDATE
            Company::where('id', $invoice->company_id)->lockForUpdate()->firstOrFail();
            app(SalesActorGuard::class)->lockAndAuthorize((int) $invoice->company_id, $user, 'sales.invoice.void');

            /** @var SalesInvoice $lockedInvoice */
            $lockedInvoice = SalesInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($lockedInvoice->isVoid()) {
                return $lockedInvoice;
            }

            if (! $lockedInvoice->isPosted()) {
                throw new InvalidArgumentException("Cannot void sales invoice in [{$lockedInvoice->status}] status. Only posted invoices can be voided.");
            }

            // Check active payment allocations
            $hasActivePayments = CustomerPaymentAllocation::query()
                ->where('sales_invoice_id', $lockedInvoice->id)
                ->whereHas('customerPayment', fn ($q) => $q->where('is_reversed', false))
                ->exists();

            if ($hasActivePayments) {
                throw new InvalidArgumentException("Cannot void sales invoice [{$lockedInvoice->id}] with active payment allocations. Please reverse payments first.");
            }

            // Check linked sales returns
            $hasReturns = SalesReturn::query()
                ->where('sales_invoice_id', $lockedInvoice->id)
                ->where('status', '!=', SalesReturn::STATUS_VOID)
                ->exists();

            if ($hasReturns) {
                throw new InvalidArgumentException("Cannot void sales invoice [{$lockedInvoice->id}] with linked sales returns.");
            }

            // Compensating stock restoration
            $lotAllocs = SalesInvoiceLotAllocation::where('sales_invoice_id', $lockedInvoice->id)->get();
            if ($lotAllocs->isNotEmpty()) {
                $compensatingLines = [];
                foreach ($lotAllocs as $alloc) {
                    $compensatingLines[] = new StockMovementLineCommand(
                        productId: $alloc->salesInvoiceLine->product_id,
                        warehouseId: (int) $lockedInvoice->warehouse_id,
                        quantity: Quantity::of((string) $alloc->quantity_allocated_base),
                        unitId: $alloc->salesInvoiceLine->product->base_unit_id,
                        unitCostBase: (string) $alloc->unit_cost_base, // Restore at original historical sale cost
                        lotId: $alloc->inventory_lot_id,
                        valueDeltaBase: (string) $alloc->total_cost_base,
                        originalMovementId: (int) $alloc->stock_movement_id,
                    );
                }

                $stockCmd = new StockMovementCommand(
                    companyId: $lockedInvoice->company_id,
                    movementType: StockMovement::TYPE_SALE_RETURN,
                    movementDate: Carbon::today()->toDateString(),
                    lines: $compensatingLines,
                    sourceType: 'sales_invoice_void',
                    sourceId: $lockedInvoice->id,
                    idempotencyKey: "sales_invoice_{$lockedInvoice->id}_void_stock",
                    createdBy: $user->id,
                    reason: "Void of Sales Invoice {$lockedInvoice->invoice_number}",
                );

                $this->inventoryMovementService->record($stockCmd);
            }

            // Accounting reversal
            $reversalBatch = null;
            if ($lockedInvoice->posting_batch_id !== null) {
                $postingBatch = $lockedInvoice->postingBatch;
                if ($postingBatch !== null && ! $postingBatch->isReversed()) {
                    $reversalBatch = $this->accountingReversalService->reverse(
                        $postingBatch,
                        $user,
                        $reason ?? "Void of Sales Invoice {$lockedInvoice->invoice_number}"
                    );
                }
            }

            if ($reversalBatch === null) {
                throw new InvalidArgumentException('Canonical accounting reversal is required before void.');
            }
            $lockedInvoice->completeCanonicalVoid($reversalBatch, $user, $reason);

            return $lockedInvoice->fresh(['lines', 'lotAllocations', 'postingBatch', 'voidPostingBatch']);
        });
    }
}
