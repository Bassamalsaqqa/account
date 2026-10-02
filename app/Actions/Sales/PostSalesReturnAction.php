<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\LedgerAccount;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesInvoiceLotAllocation;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\SalesReturnLotAllocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\HistoricalSaleCost;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Posting\AccountingPostingService;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Sales\ReceivableBookValue;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesPostingLines;
use App\Services\Sales\SalesReturnAmounts;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PostSalesReturnAction
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected InventoryMovementService $inventoryMovementService,
        protected AccountingPostingService $accountingPostingService,
    ) {}

    public function execute(SalesReturn $salesReturn, User $user): SalesReturn
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

        if (! $user->hasPermissionTo('sales.return.post')) {
            throw new AuthorizationException('User does not have permission to post sales returns.');
        }

        return DB::transaction(function () use ($salesReturn, $user): SalesReturn {
            // 1. Lock company FOR UPDATE
            /** @var Company $company */
            $company = Company::where('id', $salesReturn->company_id)->lockForUpdate()->firstOrFail();
            app(SalesActorGuard::class)->lockAndAuthorize((int) $salesReturn->company_id, $user, 'sales.return.post');

            // 2. Lock return FOR UPDATE
            /** @var SalesReturn $lockedReturn */
            $lockedReturn = SalesReturn::where('id', $salesReturn->id)->lockForUpdate()->firstOrFail();

            if ($lockedReturn->isPosted()) {
                return $lockedReturn->load(['lines', 'postingBatch']);
            }

            if ($lockedReturn->isVoid()) {
                throw new InvalidArgumentException("Cannot post voided sales return [{$lockedReturn->id}].");
            }

            // 3. Lock original invoice
            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::where('company_id', $company->id)->where('id', $lockedReturn->sales_invoice_id)->lockForUpdate()->firstOrFail();
            if (! $invoice->isPosted()) {
                throw new InvalidArgumentException("Original sales invoice [{$invoice->id}] must be posted.");
            }

            // 4. Lock customer
            /** @var Customer $customer */
            $customer = Customer::where('company_id', $company->id)->where('id', $lockedReturn->customer_id)->lockForUpdate()->firstOrFail();

            app(SalesReturnAmounts::class)->refresh($lockedReturn);
            $lines = $lockedReturn->lines;
            if ($lines->isEmpty()) {
                throw new InvalidArgumentException('Sales return must have at least one line.');
            }

            $warehouse = null;
            if ($lockedReturn->warehouse_id !== null) {
                /** @var Warehouse $warehouse */
                $warehouse = Warehouse::where('company_id', $company->id)->where('id', $lockedReturn->warehouse_id)->lockForUpdate()->firstOrFail();
                if (! $warehouse->active) {
                    throw new InvalidArgumentException("Warehouse [{$warehouse->id}] is inactive.");
                }
            }

            $issueDate = (string) $lockedReturn->issue_date->format('Y-m-d');
            $year = (int) substr($issueDate, 0, 4);

            // 5. Stock restoration at exact original historical sale cost
            $totalCogsBase = BigDecimal::zero();
            $stockLineCommands = [];
            $lineMovementsPlan = [];

            foreach ($lines as $returnLine) {
                // Verify return quantity bounds against invoice line for ALL lines (service and stock)
                /** @var SalesInvoiceLine $invLine */
                $invLine = SalesInvoiceLine::where('sales_invoice_id', $invoice->id)
                    ->where('id', $returnLine->sales_invoice_line_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $alreadyReturned = BigDecimal::zero();
                $priorReturnLines = SalesReturnLine::query()
                    ->where('sales_invoice_line_id', $invLine->id)
                    ->where('sales_return_id', '!=', $lockedReturn->id)
                    ->whereHas('salesReturn', fn ($q) => $q->where('status', SalesReturn::STATUS_POSTED))
                    ->get();

                foreach ($priorReturnLines as $prl) {
                    $alreadyReturned = $alreadyReturned->plus(BigDecimal::of((string) $prl->quantity));
                }

                $maxReturnable = BigDecimal::of((string) $invLine->quantity)->minus($alreadyReturned);
                $thisLineQty = BigDecimal::of((string) $returnLine->quantity);

                if ($thisLineQty->isGreaterThan($maxReturnable)) {
                    throw new InvalidArgumentException("Requested return quantity [{$thisLineQty}] exceeds remaining quantity [{$maxReturnable}] on invoice line [{$invLine->id}].");
                }

                if ($returnLine->product_id === null) {
                    continue;
                }

                /** @var Product $product */
                $product = Product::where('company_id', $company->id)->where('id', $returnLine->product_id)->lockForUpdate()->firstOrFail();
                if (! $product->track_stock) {
                    continue;
                }

                if ($warehouse === null) {
                    throw new InvalidArgumentException('Warehouse is required to restore stock for sales return.');
                }

                // Allocate return quantity against original sold lots
                $soldAllocs = SalesInvoiceLotAllocation::where('sales_invoice_line_id', $invLine->id)->orderBy('id')->get();
                $remQtyToRestore = BigDecimal::of((string) $returnLine->quantity_base);

                if ($soldAllocs->isNotEmpty()) {
                    foreach ($soldAllocs as $soldAlloc) {
                        if ($remQtyToRestore->isZero()) {
                            break;
                        }

                        $soldQty = BigDecimal::of((string) $soldAlloc->quantity_allocated_base);

                        // Immutable movement provenance identifies each original sold allocation, not a whole return line's lot_id.
                        $priorRestorations = StockMovement::where('company_id', $company->id)
                            ->where('reversal_of_id', $soldAlloc->stock_movement_id)
                            ->where('movement_type', StockMovement::TYPE_SALE_RETURN)->get();
                        $lotAlreadyRestored = BigDecimal::zero();
                        foreach ($priorRestorations as $restoration) {
                            if ($restoration->source_type === 'sales_return' && SalesReturn::whereKey($restoration->source_id)->where('status', SalesReturn::STATUS_VOID)->exists()) {
                                continue;
                            }
                            $lotAlreadyRestored = $lotAlreadyRestored->plus($restoration->quantity_delta_base);
                        }

                        $lotAvailToRestore = $soldQty->minus($lotAlreadyRestored);
                        if ($lotAvailToRestore->isLessThanOrEqualTo(0)) {
                            continue;
                        }

                        $take = $lotAvailToRestore->isGreaterThanOrEqualTo($remQtyToRestore) ? $remQtyToRestore : $lotAvailToRestore;

                        $cmd = new StockMovementLineCommand(
                            productId: $product->id,
                            warehouseId: $warehouse->id,
                            quantity: Quantity::of((string) $take),
                            unitId: $product->base_unit_id,
                            unitCostBase: (string) $soldAlloc->unit_cost_base, // Original historical COGS!
                            lotId: $soldAlloc->inventory_lot_id,
                            valueDeltaBase: (string) app(HistoricalSaleCost::class)->value(
                                $company->id, (int) $soldAlloc->stock_movement_id, $product->id, $warehouse->id,
                                $soldAlloc->inventory_lot_id, 'sales_return', $lockedReturn->id, $take
                            ),
                            originalMovementId: (int) $soldAlloc->stock_movement_id,
                        );
                        $cmdIdx = count($stockLineCommands);
                        $stockLineCommands[] = $cmd;
                        $lineMovementsPlan[$cmdIdx] = [
                            'line' => $returnLine,
                            'lot_id' => $soldAlloc->inventory_lot_id,
                        ];

                        $remQtyToRestore = $remQtyToRestore->minus($take);
                    }
                } else {
                    throw new InvalidArgumentException('Original stock sale has no immutable stock allocations.');
                }
                if (! $remQtyToRestore->isZero()) {
                    throw new InvalidArgumentException('Requested return quantity could not be completely allocated to original sold stock.');
                }
            }

            if (! empty($stockLineCommands)) {
                $stockCmd = new StockMovementCommand(
                    companyId: $company->id,
                    movementType: StockMovement::TYPE_SALE_RETURN,
                    movementDate: $issueDate,
                    lines: $stockLineCommands,
                    sourceType: 'sales_return',
                    sourceId: $lockedReturn->id,
                    idempotencyKey: "sales_return_{$lockedReturn->id}_stock",
                    createdBy: $user->id,
                    reason: "Sales Return {$lockedReturn->return_number}",
                );

                $movements = $this->inventoryMovementService->record($stockCmd);

                $cogsByReturnLineId = [];
                $firstMovementByReturnLineId = [];

                foreach ($movements as $idx => $m) {
                    $plan = $lineMovementsPlan[$idx];
                    /** @var SalesReturnLine $rLine */
                    $rLine = $plan['line'];

                    $valDelta = BigDecimal::of((string) $m->value_delta_base)->abs();
                    $unitCost = BigDecimal::of((string) $m->unit_cost_base);
                    $qtyAlloc = BigDecimal::of((string) $m->quantity_delta_base)->abs();

                    $lineId = (int) $rLine->id;
                    $cogsByReturnLineId[$lineId] = ($cogsByReturnLineId[$lineId] ?? BigDecimal::zero())->plus($valDelta);
                    if (! isset($firstMovementByReturnLineId[$lineId])) {
                        $firstMovementByReturnLineId[$lineId] = $m->id;
                    }

                    // Create provenance record in sales_return_lot_allocations
                    SalesReturnLotAllocation::create([
                        'company_id' => $company->id,
                        'sales_return_id' => $lockedReturn->id,
                        'sales_return_line_id' => $rLine->id,
                        'inventory_lot_id' => $m->lot_id,
                        'quantity_allocated_base' => (string) $qtyAlloc->toScale(6),
                        'unit_cost_base' => (string) $unitCost->toScale(6),
                        'total_cost_base' => (string) $valDelta->toScale(6),
                        'stock_movement_id' => $m->id,
                    ]);

                    $totalCogsBase = $totalCogsBase->plus($valDelta);
                }

                // Update return lines with accumulated COGS across all movements
                foreach ($lines as $rLine) {
                    $lineId = (int) $rLine->id;
                    if (isset($cogsByReturnLineId[$lineId])) {
                        $totalLineCogs = $cogsByReturnLineId[$lineId];
                        $qtyBase = BigDecimal::of((string) $rLine->quantity_base);
                        $unitCogs = $qtyBase->isPositive()
                            ? $totalLineCogs->dividedBy($qtyBase, 6, RoundingMode::HALF_UP)
                            : BigDecimal::zero();

                        $rLine->cogs_total_base = (string) $totalLineCogs->toScale(6);
                        $rLine->cogs_unit_base = (string) $unitCogs->toScale(6);
                        $rLine->stock_movement_id = $firstMovementByReturnLineId[$lineId] ?? null;
                        $rLine->save();
                    }
                }
            }

            // 6. Sequence numbering
            $returnNumber = $this->sequenceService->generateNextNumber(
                $company->id,
                DocumentSequence::TYPE_SALES_RETURN,
                $year
            );

            // 7. Double-entry GL posting
            $salesReturnsAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'sales_returns')->firstOrFail();
            $taxOutputAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'tax_output')->firstOrFail();
            $arAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'accounts_receivable')->firstOrFail();
            $invAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'inventory')->firstOrFail();
            $cogsAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'cogs')->firstOrFail();

            $lockedReturn->grand_total_base = (string) app(ReceivableBookValue::class)->relief($invoice, BigDecimal::of($lockedReturn->grand_total_currency), (int) $lockedReturn->id);

            $currency = $lockedReturn->currency_code;
            $fx = ExchangeRate::from($lockedReturn->exchange_rate);

            $grandTotalCurrency = BigDecimal::of((string) $lockedReturn->grand_total_currency);
            $grandTotalBase = BigDecimal::of((string) $lockedReturn->grand_total_base);
            $subtotalBase = BigDecimal::of((string) $lockedReturn->grand_total_base)->minus($lockedReturn->tax_total_base);
            $taxTotalBase = BigDecimal::of((string) $lockedReturn->tax_total_base);

            $subtotalCurrency = BigDecimal::of((string) $lockedReturn->grand_total_currency)->minus($lockedReturn->tax_total_currency);
            $taxTotalCurrency = BigDecimal::of((string) $lockedReturn->tax_total_currency);

            $postingLines = [];
            $lineNum = 1;

            // Dr Sales Returns (revenue contra)
            if ($subtotalBase->isPositive()) {
                app(SalesPostingLines::class)->append($postingLines,
                    lineNumber: $lineNum++,
                    ledgerAccountId: $salesReturnsAccount->id,
                    debitBase: MoneyAmount::from((string) $subtotalBase->toScale(6)),
                    creditBase: MoneyAmount::from('0.000000'),
                    transactionCurrencyCode: $currency,
                    transactionAmount: MoneyAmount::from((string) $subtotalCurrency->toScale(6)),
                    exchangeRate: $fx,
                    description: "Sales Return {$returnNumber} Revenue Reversal",
                );

            }

            $taxes = [];
            foreach ($lines as $returnLine) {
                $tax = BigDecimal::of($returnLine->line_tax);
                if ($tax->isPositive()) {
                    $original = SalesInvoiceLine::query()->findOrFail($returnLine->sales_invoice_line_id);
                    $account = LedgerAccount::where('company_id', $company->id)->where('id', $original->sales_tax_account_id)->where('active', true)->where('is_control', false)->firstOrFail();
                    $taxes[$account->id] = ($taxes[$account->id] ?? BigDecimal::zero())->plus($tax);
                }
            }
            $taxRemainingBase = $taxTotalBase;
            $taxRemainingGroups = count($taxes);
            foreach ($taxes as $accountId => $tax) {
                $taxRemainingGroups--;
                $groupBase = $taxRemainingGroups === 0 ? $taxRemainingBase : $tax->multipliedBy($lockedReturn->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                $taxRemainingBase = $taxRemainingBase->minus($groupBase);
                app(SalesPostingLines::class)->append($postingLines, $lineNum++, $accountId,
                    MoneyAmount::from($groupBase), MoneyAmount::zero(), $currency, MoneyAmount::from($tax), $fx,
                    "Sales Return {$returnNumber} Tax Reversal");
            }

            // Cr Accounts Receivable (reduces customer AR)
            app(SalesPostingLines::class)->append($postingLines,
                lineNumber: $lineNum++,
                ledgerAccountId: $arAccount->id,
                debitBase: MoneyAmount::from('0.000000'),
                creditBase: MoneyAmount::from((string) $grandTotalBase->toScale(6)),
                transactionCurrencyCode: $currency,
                transactionAmount: MoneyAmount::from((string) $grandTotalCurrency->toScale(6)),
                exchangeRate: $fx,
                description: "Sales Return {$returnNumber} - {$customer->displayName()}",
            );

            // Dr Inventory / Cr COGS at restored original cost
            if ($totalCogsBase->isPositive()) {
                $cogsScaled = (string) $totalCogsBase->toScale(6);

                app(SalesPostingLines::class)->append($postingLines,
                    lineNumber: $lineNum++,
                    ledgerAccountId: $invAccount->id,
                    debitBase: MoneyAmount::from($cogsScaled),
                    creditBase: MoneyAmount::from('0.000000'),
                    description: "Sales Return {$returnNumber} Inventory Restoration",
                );

                app(SalesPostingLines::class)->append($postingLines,
                    lineNumber: $lineNum++,
                    ledgerAccountId: $cogsAccount->id,
                    debitBase: MoneyAmount::from('0.000000'),
                    creditBase: MoneyAmount::from($cogsScaled),
                    description: "Sales Return {$returnNumber} COGS Relief",
                );
            }

            $postingCmd = new PostingCommand(
                company: $company,
                postingDate: Carbon::parse($issueDate),
                sourceType: 'sales_return',
                sourceId: $lockedReturn->id,
                transactionCurrencyCode: $currency,
                baseCurrencyCode: $company->base_currency_code,
                exchangeRate: $fx,
                idempotencyKey: "sales_return_{$lockedReturn->id}_posting",
                postedBy: $user,
                description: "Sales Return {$returnNumber}",
                lines: $postingLines,
            );

            $batch = $this->accountingPostingService->post($postingCmd);

            // 8. Update return
            $lockedReturn->return_number = $returnNumber;

            $lockedReturn->cogs_total_base = (string) $totalCogsBase->toScale(6);
            $lockedReturn->posting_batch_id = $batch->id;
            $lockedReturn->posted_at = Carbon::now();
            $lockedReturn->posted_by = $user->id;
            $lockedReturn->completeCanonicalPost($batch, $user);

            return $lockedReturn->fresh(['lines', 'postingBatch', 'customer', 'salesInvoice']);
        });
    }
}
