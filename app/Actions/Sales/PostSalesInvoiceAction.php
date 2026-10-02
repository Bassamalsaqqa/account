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
use App\Models\InventoryLot;
use App\Models\LedgerAccount;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLotAllocation;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\FefoAllocationService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Posting\AccountingPostingService;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Sales\DraftInvoiceIntegrity;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use App\Services\Sales\SalesPostingLines;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PostSalesInvoiceAction
{
    public function __construct(
        protected DocumentSequenceService $sequenceService,
        protected FefoAllocationService $fefoAllocationService,
        protected InventoryMovementService $inventoryMovementService,
        protected AccountingPostingService $accountingPostingService,
    ) {}

    public function execute(SalesInvoice $invoice, User $user): SalesInvoice
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

        if (! $user->hasPermissionTo('sales.invoice.post')) {
            throw new AuthorizationException('User does not have permission to post sales invoices.');
        }

        return DB::transaction(function () use ($invoice, $user): SalesInvoice {
            // 1. Lock company FOR UPDATE
            /** @var Company $company */
            $company = Company::where('id', $invoice->company_id)->lockForUpdate()->firstOrFail();
            app(SalesActorGuard::class)->lockAndAuthorize((int) $invoice->company_id, $user, 'sales.invoice.post');

            // 2. Lock invoice FOR UPDATE
            /** @var SalesInvoice $lockedInvoice */
            $lockedInvoice = SalesInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($lockedInvoice->isPosted()) {
                return $lockedInvoice->load(['lines', 'lotAllocations', 'postingBatch']);
            }

            if ($lockedInvoice->isVoid()) {
                throw new InvalidArgumentException("Cannot post voided sales invoice [{$lockedInvoice->id}].");
            }

            // 3. Revalidate Customer
            /** @var Customer $customer */
            $customer = Customer::where('company_id', $company->id)->where('id', $lockedInvoice->customer_id)->lockForUpdate()->firstOrFail();
            if (! $customer->active) {
                throw new InvalidArgumentException("Cannot post invoice for inactive customer [{$customer->id}].");
            }

            app(SalesDocumentRules::class)->header($company, $lockedInvoice->currency_code,
                BigDecimal::of((string) $lockedInvoice->exchange_rate), $lockedInvoice->issue_date->format('Y-m-d'), $lockedInvoice->document_locale);
            $lockedInvoice->customer_snapshot = $customer->only(['name_ar', 'name_en', 'business_name_ar', 'business_name_en', 'business_name', 'address_ar', 'address_en', 'phone', 'email', 'address_line_1_ar', 'address_line_1_en', 'tax_number']);
            $lockedInvoice->company_snapshot = $company->only(['name_ar', 'name_en', 'phone', 'email', 'address_ar', 'address_en', 'tax_number', 'registration_number']);
            $lockedInvoice->save();
            $lines = $lockedInvoice->lines()->lockForUpdate()->get();
            app(DraftInvoiceIntegrity::class)->validate($lockedInvoice, $lines);
            foreach ($lines as $line) {
                if ($line->product_id !== null) {
                    $product = Product::where('company_id', $company->id)->whereKey($line->product_id)->lockForUpdate()->firstOrFail();
                    $unit = app(SalesDocumentRules::class)->selectedUnit($product, $line->product_unit_id,
                        Quantity::of((string) $line->quantity));
                    if (! BigDecimal::of((string) $unit->conversion_to_base)->isEqualTo(BigDecimal::of((string) $line->unit_conversion_ratio))) {
                        throw new InvalidArgumentException('Draft unit conversion changed; edit and verify the draft before posting.');
                    }
                    app(SalesDocumentRules::class)->price($product, $unit, $user,
                        BigDecimal::of((string) $lockedInvoice->exchange_rate), $lockedInvoice->currency_code, BigDecimal::of((string) $line->unit_price));
                } elseif (! $user->hasPermissionTo('sales.invoice.change_price')) {
                    throw new AuthorizationException('Manual service price requires price-change permission.');
                }
                if (BigDecimal::of((string) $line->line_discount)->isPositive() && ! $user->hasPermissionTo('sales.invoice.change_discount')) {
                    throw new AuthorizationException('Discount permission is required to post this draft.');
                }
            }
            if ($lines->isEmpty()) {
                throw new InvalidArgumentException('Sales invoice must have at least one line.');
            }

            // Check if any product tracks stock
            $hasStockLines = false;
            foreach ($lines as $line) {
                if ($line->product_id !== null) {
                    $prod = Product::where('company_id', $company->id)->where('id', $line->product_id)->first();
                    if ($prod?->track_stock) {
                        $hasStockLines = true;
                        break;
                    }
                }
            }

            $warehouse = null;
            if ($hasStockLines) {
                if ($lockedInvoice->warehouse_id === null) {
                    throw new InvalidArgumentException('Warehouse is required to post stock-bearing sales invoice.');
                }
                $warehouse = Warehouse::where('company_id', $company->id)->where('id', $lockedInvoice->warehouse_id)->lockForUpdate()->firstOrFail();
                if (! $warehouse->active) {
                    throw new InvalidArgumentException("Warehouse [{$warehouse->id}] is inactive.");
                }
            }

            $issueDate = (string) $lockedInvoice->issue_date->format('Y-m-d');
            $year = (int) substr($issueDate, 0, 4);

            // 4. Assign permanent sequence number
            $invoiceNumber = $this->sequenceService->generateNextNumber(
                $company->id,
                DocumentSequence::TYPE_SALES_INVOICE,
                $year
            );

            // 5. Stock Movements & FEFO Lot Allocations
            $totalCogsBase = BigDecimal::zero();
            $movementsByLineId = [];

            if ($hasStockLines) {
                $stockLineCommands = [];
                $allocationsPlan = [];

                foreach ($lines as $line) {
                    if ($line->product_id === null) {
                        continue;
                    }
                    /** @var Product $product */
                    $product = Product::where('company_id', $company->id)->where('id', $line->product_id)->lockForUpdate()->firstOrFail();
                    if (! $product->track_stock) {
                        continue;
                    }

                    $qtyNeeded = Quantity::of((string) $line->quantity_base);
                    $fefoAllocs = $this->fefoAllocationService->allocateWithLock(
                        $product,
                        $warehouse,
                        $qtyNeeded,
                        true,
                        $issueDate
                    );

                    foreach ($fefoAllocs as $alloc) {
                        $cmd = new StockMovementLineCommand(
                            productId: $product->id,
                            warehouseId: $warehouse->id,
                            quantity: $alloc['quantity'],
                            unitId: $product->base_unit_id,
                            unitCostBase: null, // Outbound movement cost derived from inventory engine
                            lotId: $alloc['lot_id'],
                        );
                        $cmdIndex = count($stockLineCommands);
                        $stockLineCommands[] = $cmd;
                        $allocationsPlan[$cmdIndex] = [
                            'line_id' => $line->id,
                            'lot' => $alloc['lot'],
                        ];
                    }
                }

                if (! empty($stockLineCommands)) {
                    $stockCmd = new StockMovementCommand(
                        companyId: $company->id,
                        movementType: StockMovement::TYPE_SALE,
                        movementDate: $issueDate,
                        lines: $stockLineCommands,
                        sourceType: 'sales_invoice',
                        sourceId: $lockedInvoice->id,
                        idempotencyKey: "sales_invoice_{$lockedInvoice->id}_stock",
                        createdBy: $user->id,
                        reason: "Sales Invoice {$invoiceNumber}",
                    );

                    $movements = $this->inventoryMovementService->record($stockCmd);

                    foreach ($movements as $idx => $m) {
                        $plan = $allocationsPlan[$idx];
                        $lineId = $plan['line_id'];
                        /** @var InventoryLot|null $lot */
                        $lot = $plan['lot'];

                        $allocatedQty = BigDecimal::of((string) $m->quantity_delta_base)->abs();
                        $unitCost = BigDecimal::of((string) $m->unit_cost_base);
                        $valDelta = BigDecimal::of((string) $m->value_delta_base)->abs();

                        SalesInvoiceLotAllocation::create([
                            'company_id' => $company->id,
                            'sales_invoice_id' => $lockedInvoice->id,
                            'sales_invoice_line_id' => $lineId,
                            'inventory_lot_id' => $m->lot_id,
                            'lot_number' => $lot?->lot_number,
                            'expiry_date' => $lot?->expiry_date,
                            'quantity_allocated_base' => (string) $allocatedQty->toScale(6),
                            'unit_cost_base' => (string) $unitCost->toScale(6),
                            'total_cost_base' => (string) $valDelta->toScale(6),
                            'stock_movement_id' => $m->id,
                        ]);

                        if (! isset($movementsByLineId[$lineId])) {
                            $movementsByLineId[$lineId] = [
                                'total_cogs' => BigDecimal::zero(),
                                'first_movement_id' => $m->id,
                            ];
                        }
                        $movementsByLineId[$lineId]['total_cogs'] = $movementsByLineId[$lineId]['total_cogs']->plus($valDelta);
                        $totalCogsBase = $totalCogsBase->plus($valDelta);
                    }
                }
            }

            // Update line COGS
            foreach ($lines as $line) {
                if (isset($movementsByLineId[$line->id])) {
                    $lineCogs = $movementsByLineId[$line->id]['total_cogs'];
                    $qtyBase = BigDecimal::of((string) $line->quantity_base);
                    $unitCogs = $qtyBase->isPositive()
                        ? $lineCogs->dividedBy($qtyBase, 6, RoundingMode::HALF_UP)
                        : BigDecimal::zero();

                    $line->cogs_total_base = (string) $lineCogs->toScale(6);
                    $line->cogs_unit_base = (string) $unitCogs->toScale(6);
                    $line->stock_movement_id = $movementsByLineId[$line->id]['first_movement_id'];
                    $line->save();
                }
            }

            foreach (['subtotal', 'discount_total', 'tax_total', 'grand_total'] as $column) {
                $lockedInvoice->setAttribute($column.'_base', (string) BigDecimal::of($lockedInvoice->getAttribute($column.'_currency'))->multipliedBy($lockedInvoice->exchange_rate)->toScale(6, RoundingMode::HALF_UP));
            }

            // 6. Double-entry GL posting
            $arAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'accounts_receivable')->firstOrFail();
            $salesRevAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'sales_revenue')->firstOrFail();
            $taxOutputAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'tax_output')->firstOrFail();
            $cogsAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'cogs')->firstOrFail();
            $invAccount = LedgerAccount::where('company_id', $company->id)->where('system_key', 'inventory')->firstOrFail();

            $currency = $lockedInvoice->currency_code;
            $fx = ExchangeRate::from($lockedInvoice->exchange_rate);

            $grandTotalCurrency = BigDecimal::of((string) $lockedInvoice->grand_total_currency);
            $grandTotalBase = BigDecimal::of((string) $lockedInvoice->grand_total_base);
            $subtotalBase = BigDecimal::of((string) $lockedInvoice->subtotal_base);
            $discountTotalBase = BigDecimal::of((string) $lockedInvoice->discount_total_base);
            $taxTotalBase = BigDecimal::of((string) $lockedInvoice->tax_total_base);

            $subtotalCurrency = BigDecimal::of((string) $lockedInvoice->subtotal_currency);
            $discountTotalCurrency = BigDecimal::of((string) $lockedInvoice->discount_total_currency);
            $taxTotalCurrency = BigDecimal::of((string) $lockedInvoice->tax_total_currency);

            $revenueBase = $grandTotalBase->minus($taxTotalBase);
            $revenueCurrency = $grandTotalCurrency->minus($taxTotalCurrency);

            $postingLines = [];
            $lineNum = 1;

            // Dr Accounts Receivable
            app(SalesPostingLines::class)->append($postingLines,
                lineNumber: $lineNum++,
                ledgerAccountId: $arAccount->id,
                debitBase: MoneyAmount::from((string) $grandTotalBase->toScale(6)),
                creditBase: MoneyAmount::from('0.000000'),
                transactionCurrencyCode: $currency,
                transactionAmount: MoneyAmount::from((string) $grandTotalCurrency->toScale(6)),
                exchangeRate: $fx,
                description: "Sales Invoice {$invoiceNumber} - {$customer->displayName()}",
            );

            // Cr Sales Revenue (net of tax)
            if ($revenueBase->isPositive()) {
                app(SalesPostingLines::class)->append($postingLines,
                    lineNumber: $lineNum++,
                    ledgerAccountId: $salesRevAccount->id,
                    debitBase: MoneyAmount::from('0.000000'),
                    creditBase: MoneyAmount::from((string) $revenueBase->toScale(6)),
                    transactionCurrencyCode: $currency,
                    transactionAmount: MoneyAmount::from((string) $revenueCurrency->toScale(6)),
                    exchangeRate: $fx,
                    description: "Sales Invoice {$invoiceNumber} Revenue",
                );
            }

            // Cr Output Tax routed by TaxRate sales_tax_account_id
            $taxesByAccount = [];
            foreach ($lines as $invLine) {
                $lineTaxCur = BigDecimal::of((string) $invLine->line_tax);
                $lineTaxBase = BigDecimal::of((string) $invLine->line_tax_base);
                if ($lineTaxBase->isPositive()) {
                    $taxAccId = $taxOutputAccount->id;
                    if ($invLine->tax_rate_id !== null) {
                        $tr = TaxRate::where('company_id', $company->id)->where('id', $invLine->tax_rate_id)->first();
                        if ($tr?->sales_tax_account_id !== null) {
                            $customTaxAcc = LedgerAccount::where('company_id', $company->id)->where('id', $tr->sales_tax_account_id)->where('active', true)->first();
                            if ($customTaxAcc === null || $customTaxAcc->is_control) {
                                throw new InvalidArgumentException('Configured sales tax account is invalid or inactive.');
                            }
                            $taxAccId = $customTaxAcc->id;
                        }
                    }

                    if ($invLine->tax_rate_id !== null && ($tr === null || ! $tr->active)) {
                        throw new InvalidArgumentException('Tax rate is missing or inactive.');
                    }
                    $invLine->sales_tax_account_id = $taxAccId;
                    $invLine->save();
                    if (! isset($taxesByAccount[$taxAccId])) {
                        $taxesByAccount[$taxAccId] = [
                            'base' => BigDecimal::zero(),
                            'currency' => BigDecimal::zero(),
                        ];
                    }
                    $taxesByAccount[$taxAccId]['base'] = $taxesByAccount[$taxAccId]['base']->plus($lineTaxBase);
                    $taxesByAccount[$taxAccId]['currency'] = $taxesByAccount[$taxAccId]['currency']->plus($lineTaxCur);
                }
            }

            $taxRemainingBase = $taxTotalBase;
            $taxRemainingGroups = count($taxesByAccount);
            foreach ($taxesByAccount as $taxAccId => $taxAmts) {
                $taxRemainingGroups--;
                $taxAmts['base'] = $taxRemainingGroups === 0 ? $taxRemainingBase : $taxAmts['currency']->multipliedBy($lockedInvoice->exchange_rate)->toScale(6, RoundingMode::HALF_UP);
                $taxRemainingBase = $taxRemainingBase->minus($taxAmts['base']);
                if ($taxAmts['base']->isPositive()) {
                    app(SalesPostingLines::class)->append($postingLines,
                        lineNumber: $lineNum++,
                        ledgerAccountId: $taxAccId,
                        debitBase: MoneyAmount::from('0.000000'),
                        creditBase: MoneyAmount::from((string) $taxAmts['base']->toScale(6)),
                        transactionCurrencyCode: $currency,
                        transactionAmount: MoneyAmount::from((string) $taxAmts['currency']->toScale(6)),
                        exchangeRate: $fx,
                        description: "Sales Invoice {$invoiceNumber} Output Tax",
                    );
                }
            }

            // Dr COGS / Cr Inventory (if > 0)
            if ($totalCogsBase->isPositive()) {
                $cogsScaled = (string) $totalCogsBase->toScale(6);

                app(SalesPostingLines::class)->append($postingLines,
                    lineNumber: $lineNum++,
                    ledgerAccountId: $cogsAccount->id,
                    debitBase: MoneyAmount::from($cogsScaled),
                    creditBase: MoneyAmount::from('0.000000'),
                    description: "Sales Invoice {$invoiceNumber} COGS",
                );

                app(SalesPostingLines::class)->append($postingLines,
                    lineNumber: $lineNum++,
                    ledgerAccountId: $invAccount->id,
                    debitBase: MoneyAmount::from('0.000000'),
                    creditBase: MoneyAmount::from($cogsScaled),
                    description: "Sales Invoice {$invoiceNumber} Inventory Relief",
                );
            }

            $postingCmd = new PostingCommand(
                company: $company,
                postingDate: Carbon::parse($issueDate),
                sourceType: 'sales_invoice',
                sourceId: $lockedInvoice->id,
                transactionCurrencyCode: $currency,
                baseCurrencyCode: $company->base_currency_code,
                exchangeRate: $fx,
                idempotencyKey: "sales_invoice_{$lockedInvoice->id}_posting",
                postedBy: $user,
                description: "Sales Invoice {$invoiceNumber}",
                lines: $postingLines,
            );

            $batch = $this->accountingPostingService->post($postingCmd);

            // 7. Update SalesInvoice
            $lockedInvoice->invoice_number = $invoiceNumber;

            $lockedInvoice->cogs_total_base = (string) $totalCogsBase->toScale(6);
            $lockedInvoice->posting_batch_id = $batch->id;
            $lockedInvoice->posted_at = Carbon::now();
            $lockedInvoice->posted_by = $user->id;
            $lockedInvoice->completeCanonicalPost($batch, $user);

            return $lockedInvoice->fresh(['lines', 'lotAllocations', 'postingBatch', 'customer']);
        });
    }
}
