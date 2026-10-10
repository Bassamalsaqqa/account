<?php

declare(strict_types=1);

namespace Tests\Feature\Phase10;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Purchasing\AllocateLandedCostAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\SaveTaxRateAction;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Queries\CustomerBalancesReportQuery;
use App\Application\Reporting\Queries\InventoryValuationReportQuery;
use App\Application\Reporting\Queries\ProfitReportQuery;
use App\Application\Reporting\Queries\VendorBalancesReportQuery;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\InventoryBalance;
use App\Models\LandedCostAllocation;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Money\ExchangeRateService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\PurchasePayablePosition;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase8\Phase8TestCase;

final class GoldenTradingJourneyTest extends Phase8TestCase
{
    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:33796dcfad9d375084931f6e2467d32c5890835848aa617c5a0833a08892415d']);

        // Provision synthetic control entity (Company B) to verify zero cross-contamination
        app(CompanyContext::class)->clear();
        $this->companyB = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة المراقبة والتحكم ب',
            'name_en' => 'Control Company B',
            'base_currency_code' => 'ILS',
        ]);

        // Restore active company context back to trading entity (Company A)
        $this->activateUser($this->owner);
    }

    /**
     * Connected golden trading journey scenario:
     * 1. Multi-unit product setup (10 cartons of 12 pieces) & multi-currency exchange rate snapshots (USD, JOD)
     * 2. Post unreversed freight expense classified as landed cost
     * 3. Draft purchase of 10 cartons @ 120.00 ILS commercial cost
     * 4. Allocate posted freight (120.00 ILS) to draft purchase before posting
     * 5. Post purchase: inventory capitalized at 11.00 ILS per base piece (132.00 ILS per carton), commercial AP = 1200.00 ILS
     * 6. Sell 3 cartons @ 200.00 ILS with 60.00 ILS discount and 10% tax (594.00 ILS total)
     * 7. Return 1 carton: stock restored by 12 pieces @ 11.00 ILS, AR reduced by 198.00 ILS to 396.00 ILS
     * 8. Settle customer receivable (396.00 ILS) and vendor payable (1200.00 ILS) via canonical cash payments
     * 9. Mutate master records (product, tax, rates, parties) and verify historical postings remain strictly immutable
     * 10. Verify exact GL, inventory valuation, profit, and AR/AP balances reports truth
     * 11. Verify control Company B economic fingerprint is 100% unchanged
     * 12. End all SIX domain reconciliations healthy (Accounting, Inventory, Money, Phase 7, Sales, Payables)
     */
    public function test_connected_golden_trading_journey(): void
    {
        // -------------------------------------------------------------------------
        // Step 0: Capture baseline economic fingerprints for Company B
        // -------------------------------------------------------------------------
        $companyBFingerprintBefore = $this->companyFingerprint($this->companyB);

        // -------------------------------------------------------------------------
        // Step 1: Multi-Unit Product & Multi-Currency Setup (Journeys J01, J06 prep)
        // -------------------------------------------------------------------------
        $cartonUnit = Unit::where('company_id', $this->company->id)->where('code', 'carton')->firstOrFail();
        $pieceUnit = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        // 1 carton = 12 pieces
        $cartonProductUnit = app(ProductCatalogService::class)->addOrUpdateAlternateUnit(
            $this->product,
            $cartonUnit->id,
            '12.000000',
            false,
            false
        );

        $this->assertSame('12.000000', $cartonProductUnit->conversion_to_base);
        $this->assertSame($cartonUnit->id, $cartonProductUnit->unit_id);
        $this->assertSame($this->product->id, $cartonProductUnit->product_id);

        // Multi-currency rate snapshots for ILS primary entity
        app(ExchangeRateService::class)->recordRate(
            $this->company,
            'USD',
            '3.5000000000',
            new \DateTimeImmutable('2026-10-01'),
            $this->owner
        );
        app(ExchangeRateService::class)->recordRate(
            $this->company,
            'JOD',
            '5.0000000000',
            new \DateTimeImmutable('2026-10-01'),
            $this->owner
        );

        // -------------------------------------------------------------------------
        // Step 2: Post Unreversed Freight Expense (Landed Cost) (Journeys J07, J11)
        // -------------------------------------------------------------------------
        $freightExpense = app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->landedCategory->id,
            'expense_date' => '2026-10-02',
            'classification' => Expense::CLASSIFICATION_LANDED_COST,
            'description' => 'Freight and customs clearance for 10 cartons',
            'currency_code' => 'ILS',
            'amount' => '120.000000',
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->ilsCashAccount->id,
            'idempotency_key' => 'landed-freight-fixture-001',
        ]);

        $this->assertSame(Expense::STATUS_POSTED, $freightExpense->status);
        $this->assertSame('120.000000', $freightExpense->amount);
        $this->assertSame('120.000000', $freightExpense->base_amount);
        $this->assertNotNull($freightExpense->posting_batch_id);

        // Verify GL batch of freight expense: Dr Landed Cost Clearing 120, Cr Cash 120
        $freightBatch = PostingBatch::with('lines')->findOrFail($freightExpense->posting_batch_id);
        $clearingAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'landed_cost_clearing')->firstOrFail();
        $this->assertSame(2, $freightBatch->lines->count());
        $this->assertSame($clearingAccount->id, $freightBatch->lines[0]->ledger_account_id);
        $this->assertSame('120.000000', $freightBatch->lines[0]->debit_base);
        $this->assertSame('120.000000', $freightBatch->lines[1]->credit_base);

        // -------------------------------------------------------------------------
        // Step 3: Create Draft Purchase of 10 Cartons (Journey J06, J07)
        // -------------------------------------------------------------------------
        $purchaseDraft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'vendor_invoice_number' => 'VINV-2026-1001',
            'purchase_date' => '2026-10-03',
            'due_date' => '2026-11-03',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $cartonProductUnit->id,
                    'quantity' => '10.000000',
                    'unit_cost' => '120.000000', // 120 ILS commercial per carton = 1,200.00 ILS total
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                    'lots' => [],
                ],
            ],
        ]);

        $this->assertSame(Purchase::STATUS_DRAFT, $purchaseDraft->status);
        $this->assertSame('1200.000000', $purchaseDraft->grand_total_currency);
        $this->assertSame('1200.000000', $purchaseDraft->grand_total_base);
        $this->assertNull($purchaseDraft->posting_batch_id);

        // -------------------------------------------------------------------------
        // Step 4: Allocate Freight Landed Cost to Draft Purchase (Journey J07)
        // -------------------------------------------------------------------------
        $allocations = app(AllocateLandedCostAction::class)->execute(
            $purchaseDraft,
            $freightExpense,
            LandedCostAllocation::METHOD_VALUE,
            $this->owner
        );

        $this->assertCount(1, $allocations);
        $alloc = $allocations->first();
        $this->assertSame('120.000000', $alloc->allocated_base);
        $this->assertSame(LandedCostAllocation::STATUS_DRAFT, $alloc->status);

        // -------------------------------------------------------------------------
        // Step 5: Post Purchase with Landed Cost (Journeys J06, J07)
        // -------------------------------------------------------------------------
        $postedPurchase = app(PostPurchaseAction::class)->execute($purchaseDraft, $this->owner);

        $this->assertSame(Purchase::STATUS_POSTED, $postedPurchase->status);
        $this->assertNotNull($postedPurchase->purchase_number);
        $this->assertNotNull($postedPurchase->posting_batch_id);

        $purchaseLine = $postedPurchase->lines->first();
        $this->assertSame('10.000000', $purchaseLine->quantity);
        $this->assertSame('120.000000', $purchaseLine->quantity_base); // 10 cartons * 12 pieces = 120 pieces
        $this->assertSame('120.000000', $purchaseLine->unit_cost);
        $this->assertSame('120.000000', $purchaseLine->landed_cost_allocated_base);
        // Capitalized acquisition unit cost = (1200 commercial + 120 landed) / 120 base pieces = 11.000000 ILS per piece
        $this->assertSame('11.000000', $purchaseLine->inventory_unit_cost_base);

        // Landed cost allocation is locked
        $alloc->refresh();
        $this->assertSame(LandedCostAllocation::STATUS_LOCKED, $alloc->status);
        $this->assertNotNull($alloc->locked_at);

        // Stock movement verification
        $purchaseMovement = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'purchase')
            ->where('source_id', $postedPurchase->id)
            ->firstOrFail();

        $this->assertSame(StockMovement::TYPE_PURCHASE, $purchaseMovement->movement_type);
        $this->assertSame('10.000000', $purchaseMovement->source_quantity);
        $this->assertSame($cartonUnit->id, $purchaseMovement->unit_id);
        $this->assertSame('120.000000', $purchaseMovement->quantity_delta_base);
        $this->assertSame('1320.000000', $purchaseMovement->value_delta_base); // 120 pieces * 11.00 ILS

        // Warehouse inventory balance is 120 pieces
        $whBalance = InventoryBalance::where('company_id', $this->company->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertSame('120.000000', $whBalance->quantity_base);

        // GL verification: AP is strictly commercial (1200.00), clearing account net zero
        $purchaseBatch = PostingBatch::with('lines')->findOrFail($postedPurchase->posting_batch_id);
        $inventoryAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'inventory')->firstOrFail();
        $payableAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_payable')->firstOrFail();

        $this->assertCount(4, $purchaseBatch->lines);
        // Line 1: Dr Inventory commercial 1200
        $this->assertSame($inventoryAccount->id, $purchaseBatch->lines[0]->ledger_account_id);
        $this->assertSame('1200.000000', $purchaseBatch->lines[0]->debit_base);
        // Line 2: Cr Accounts Payable commercial 1200
        $this->assertSame($payableAccount->id, $purchaseBatch->lines[1]->ledger_account_id);
        $this->assertSame('1200.000000', $purchaseBatch->lines[1]->credit_base);
        // Line 3: Dr Inventory landed 120
        $this->assertSame($inventoryAccount->id, $purchaseBatch->lines[2]->ledger_account_id);
        $this->assertSame('120.000000', $purchaseBatch->lines[2]->debit_base);
        // Line 4: Cr Landed Cost Clearing 120
        $this->assertSame($clearingAccount->id, $purchaseBatch->lines[3]->ledger_account_id);
        $this->assertSame('120.000000', $purchaseBatch->lines[3]->credit_base);

        // Verify clearing account net balance is exactly zero
        $clearingNet = DB::table('posting_lines')
            ->where('company_id', $this->company->id)
            ->where('ledger_account_id', $clearingAccount->id)
            ->selectRaw('SUM(debit_base) - SUM(credit_base) AS net_balance')
            ->value('net_balance');
        $this->assertSame('0.000000', (string) BigDecimal::of((string) ($clearingNet ?? '0'))->toScale(6));

        // -------------------------------------------------------------------------
        // Step 6: Customer, Tax Rate & Sales Invoice Setup (Journeys J01, J03)
        // -------------------------------------------------------------------------
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'شركة التجارة والعملاء الذهبية',
            'name_en' => 'Golden Trading Customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $taxAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'tax_output')->firstOrFail();
        $taxRate = app(SaveTaxRateAction::class)->execute($this->company, $this->owner, [
            'code' => 'VAT10',
            'name_ar' => 'ضريبة القيمة المضافة 10%',
            'name_en' => 'VAT 10%',
            'rate' => '10.000000',
            'calculation' => 'exclusive',
            'active' => true,
            'sales_tax_account_id' => $taxAccount->id,
        ]);

        // Sell 3 cartons @ 200.00 ILS with 60.00 ILS discount and 10% tax
        // Subtotal: 3 * 200 = 600.00 ILS
        // Discount: 60.00 ILS -> Net before tax: 540.00 ILS
        // Tax (10% exclusive on 540): 54.00 ILS
        // Grand Total: 594.00 ILS
        $invoiceDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-04',
            'due_date' => '2026-11-04',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $cartonProductUnit->id,
                    'item_description' => 'بيع 3 كراتين زيت زيتون',
                    'quantity' => '3.000000',
                    'unit_price' => '200.000000',
                    'discount_type' => 'fixed',
                    'discount_value' => '60.000000',
                    'tax_rate_id' => $taxRate->id,
                ],
            ],
        ]);

        $this->assertSame(SalesInvoice::STATUS_DRAFT, $invoiceDraft->status);
        $this->assertSame('600.000000', $invoiceDraft->subtotal_base);
        $this->assertSame('60.000000', $invoiceDraft->discount_total_base);
        $this->assertSame('54.000000', $invoiceDraft->tax_total_base);
        $this->assertSame('594.000000', $invoiceDraft->grand_total_base);

        // -------------------------------------------------------------------------
        // Step 7: Post Sales Invoice & Verify FEFO Stock Relief + COGS (Journey J03)
        // -------------------------------------------------------------------------
        $postedInvoice = app(PostSalesInvoiceAction::class)->execute($invoiceDraft, $this->owner);

        $this->assertSame(SalesInvoice::STATUS_POSTED, $postedInvoice->status);
        $this->assertNotNull($postedInvoice->invoice_number);
        $this->assertNotNull($postedInvoice->posting_batch_id);

        $invoiceLine = $postedInvoice->lines->first();
        $this->assertSame('3.000000', $invoiceLine->quantity);
        $this->assertSame('36.000000', $invoiceLine->quantity_base); // 3 cartons * 12 pieces = 36 pieces
        $this->assertSame('200.000000', $invoiceLine->unit_price);
        $this->assertSame('60.000000', $invoiceLine->line_discount);
        $this->assertSame('54.000000', $invoiceLine->line_tax);
        $this->assertSame('594.000000', $invoiceLine->line_total);
        $this->assertSame('10.000000', $invoiceLine->tax_rate_snapshot);

        // Outbound stock movement verification: 36 pieces @ 11.00 ILS = 396.00 ILS COGS
        $saleMovement = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'sales_invoice')
            ->where('source_id', $postedInvoice->id)
            ->firstOrFail();

        $this->assertSame(StockMovement::TYPE_SALE, $saleMovement->movement_type);
        $this->assertSame('-36.000000', $saleMovement->quantity_delta_base);
        $this->assertSame('-396.000000', $saleMovement->value_delta_base);

        // Remaining warehouse inventory: 120 - 36 = 84 pieces
        $whBalance->refresh();
        $this->assertSame('84.000000', $whBalance->quantity_base);

        // Customer receivable outstanding: 594.00 ILS
        $this->assertSame('594.000000', (string) $postedInvoice->calculateOutstanding());

        // -------------------------------------------------------------------------
        // Step 8: Sales Return of 1 Carton (Journey J05)
        // -------------------------------------------------------------------------
        // Returning 1 carton:
        // Original line had 3 cartons, discount 60.00 (20.00 / carton), tax 10%
        // Return 1 carton: unit_price 200.00, discount 20.00 -> net before tax 180.00, tax 18.00, total 198.00 ILS
        $returnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $postedInvoice->id,
            'issue_date' => '2026-10-05',
            'reason' => 'Customer returned 1 undamaged carton',
            'lines' => [
                [
                    'sales_invoice_line_id' => $invoiceLine->id,
                    'quantity' => '1.000000',
                ],
            ],
        ]);

        $this->assertSame(SalesReturn::STATUS_DRAFT, $returnDraft->status);
        $this->assertSame('198.000000', $returnDraft->grand_total);
        $this->assertSame('198.000000', $returnDraft->grand_total_base);

        $postedReturn = app(PostSalesReturnAction::class)->execute($returnDraft, $this->owner);

        $this->assertSame(SalesReturn::STATUS_POSTED, $postedReturn->status);
        $this->assertNotNull($postedReturn->return_number);
        $this->assertNotNull($postedReturn->posting_batch_id);

        $returnLine = $postedReturn->lines->first();
        $this->assertSame('1.000000', $returnLine->quantity);
        $this->assertSame('12.000000', $returnLine->quantity_base);
        $this->assertSame('200.000000', $returnLine->unit_price);
        $this->assertSame('20.000000', $returnLine->line_discount);
        $this->assertSame('18.000000', $returnLine->line_tax);
        $this->assertSame('198.000000', $returnLine->line_total);
        $this->assertSame('10.000000', $returnLine->tax_rate_snapshot);

        // Restored stock movement: 1 carton = 12 pieces @ historical cost 11.00 ILS = 132.00 ILS
        $returnMovement = StockMovement::where('company_id', $this->company->id)
            ->where('source_type', 'sales_return')
            ->where('source_id', $postedReturn->id)
            ->firstOrFail();

        $this->assertSame(StockMovement::TYPE_SALE_RETURN, $returnMovement->movement_type);
        $this->assertSame('12.000000', $returnMovement->quantity_delta_base);
        $this->assertSame('132.000000', $returnMovement->value_delta_base);
        $this->assertSame($saleMovement->id, $returnMovement->reversal_of_id);

        // Restored warehouse inventory: 84 + 12 = 96 pieces (8 cartons)
        $whBalance->refresh();
        $this->assertSame('96.000000', $whBalance->quantity_base);

        // Invoice outstanding is now 594.00 - 198.00 = 396.00 ILS
        $postedInvoice->refresh();
        $this->assertSame('396.000000', (string) $postedInvoice->calculateOutstanding());

        // -------------------------------------------------------------------------
        // Step 9: Settle Customer Receivable via Customer Payment (Journey J04)
        // -------------------------------------------------------------------------
        $customerPayment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-06',
            'payment_method' => 'cash',
            'amount' => '396.000000',
            'exchange_rate' => '1.0000000000',
            'reference_number' => 'RCP-2026-1001',
            'idempotency_key' => 'golden-customer-settle-001',
            'allocations' => [
                [
                    'sales_invoice_id' => $postedInvoice->id,
                    'allocated_amount' => '396.000000',
                ],
            ],
        ]);

        $this->assertNotNull($customerPayment->payment_number);
        $this->assertFalse($customerPayment->is_reversed);
        $this->assertSame('396.000000', $customerPayment->amount);
        $this->assertSame('396.000000', $customerPayment->amount_base);

        $postedInvoice->refresh();
        $this->assertSame('0.000000', (string) $postedInvoice->calculateOutstanding());
        $this->assertSame(SalesInvoice::PAYMENT_STATUS_PAID, $postedInvoice->derivedPaymentStatus());

        // -------------------------------------------------------------------------
        // Step 10: Settle Vendor Payable via Vendor Payment (Journey J08)
        // -------------------------------------------------------------------------
        $vendorPayment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-06',
            'payment_method' => 'cash',
            'amount' => '1200.000000',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'golden-vendor-settle-001',
            'allocations' => [
                [
                    'purchase_id' => $postedPurchase->id,
                    'allocated_amount' => '1200.000000',
                ],
            ],
        ]);

        $this->assertNotNull($vendorPayment->payment_number);
        $this->assertFalse($vendorPayment->is_reversed);
        $this->assertSame('1200.000000', $vendorPayment->amount);
        $this->assertSame('1200.000000', $vendorPayment->amount_base);

        $postedPurchase->refresh();
        $this->assertSame('0.000000', (string) $postedPurchase->calculateOutstanding());
        $this->assertSame(PurchasePayablePosition::STATUS_SETTLED, $postedPurchase->derivedPaymentStatus());

        // -------------------------------------------------------------------------
        // Step 11: Mutate Master Records & Verify Immutability of Posted Documents
        // -------------------------------------------------------------------------
        // Mutate product master defaults
        $this->product->update([
            'suggested_sale_price' => '999.000000',
            'default_purchase_cost_base' => '888.000000',
            'name_ar' => 'سلعة غذائية معدلة',
            'name_en' => 'Modified Food Item',
        ]);

        // Mutate customer master data
        $customer->update([
            'name_ar' => 'عميل تم تعديله لاحقاً',
            'name_en' => 'Later Modified Customer',
        ]);

        // Mutate vendor master data
        $this->vendor->update([
            'name_ar' => 'مورد تم تعديله لاحقاً',
            'name_en' => 'Later Modified Vendor',
        ]);

        // Mutate tax rate master data
        $taxRate->update([
            'rate' => '25.000000',
            'active' => false,
        ]);

        // Mutate exchange rates
        app(ExchangeRateService::class)->recordRate(
            $this->company,
            'USD',
            '4.2000000000',
            new \DateTimeImmutable('2026-10-10'),
            $this->owner
        );
        app(ExchangeRateService::class)->recordRate(
            $this->company,
            'JOD',
            '6.1000000000',
            new \DateTimeImmutable('2026-10-10'),
            $this->owner
        );

        // Assert purchase line snapshots are completely unchanged
        $freshPurchaseLine = $postedPurchase->fresh()->lines->first();
        $this->assertSame('10.000000', $freshPurchaseLine->quantity);
        $this->assertSame('120.000000', $freshPurchaseLine->quantity_base);
        $this->assertSame('120.000000', $freshPurchaseLine->unit_cost);
        $this->assertSame('120.000000', $freshPurchaseLine->landed_cost_allocated_base);
        $this->assertSame('11.000000', $freshPurchaseLine->inventory_unit_cost_base);

        // Assert sales invoice line snapshots are completely unchanged
        $freshInvoiceLine = $postedInvoice->fresh()->lines->first();
        $this->assertSame('3.000000', $freshInvoiceLine->quantity);
        $this->assertSame('36.000000', $freshInvoiceLine->quantity_base);
        $this->assertSame('200.000000', $freshInvoiceLine->unit_price);
        $this->assertSame('60.000000', $freshInvoiceLine->line_discount);
        $this->assertSame('54.000000', $freshInvoiceLine->line_tax);
        $this->assertSame('594.000000', $freshInvoiceLine->line_total);
        $this->assertSame('10.000000', $freshInvoiceLine->tax_rate_snapshot);

        // Assert sales return line snapshots are completely unchanged
        $freshReturnLine = $postedReturn->fresh()->lines->first();
        $this->assertSame('1.000000', $freshReturnLine->quantity);
        $this->assertSame('12.000000', $freshReturnLine->quantity_base);
        $this->assertSame('200.000000', $freshReturnLine->unit_price);
        $this->assertSame('20.000000', $freshReturnLine->line_discount);
        $this->assertSame('18.000000', $freshReturnLine->line_tax);
        $this->assertSame('198.000000', $freshReturnLine->line_total);
        $this->assertSame('10.000000', $freshReturnLine->tax_rate_snapshot);

        // -------------------------------------------------------------------------
        // Step 12: Verify Financial and Inventory Report Truth (Journey J13)
        // -------------------------------------------------------------------------
        $period = ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company);

        // 1. Profit Report
        // Net sales: 540.00 sale - 180.00 return = 360.000000 ILS
        // COGS: 396.00 sale - 132.00 return = 264.000000 ILS
        // Gross Profit = 360 - 264 = 96.000000 ILS
        // Operating Expenses: 0.000000 ILS (freight was capitalized into inventory as landed cost)
        // Net Profit = 96.000000 ILS
        $profit = app(ProfitReportQuery::class)->execute($this->company, ['period' => $period], $this->owner);
        $this->assertSame('360.000000', $profit->netSales);
        $this->assertSame('264.000000', $profit->cogs);
        $this->assertSame('96.000000', $profit->grossProfit);
        $this->assertSame('0.000000', $profit->operatingExpenses);
        $this->assertSame('96.000000', $profit->netProfit);

        // 2. Inventory Valuation Report
        // Ending stock: 96 pieces (8 cartons) @ 11.000000 ILS = 1,056.000000 ILS
        $valReport = app(InventoryValuationReportQuery::class)->execute($this->company, [], $this->owner);
        $this->assertSame('1056.000000', (string) BigDecimal::of((string) ($valReport->totals['total_valuation'] ?? '0'))->toScale(6));

        // 3. Customer Balances Report: Customer fully paid
        $custBalances = app(CustomerBalancesReportQuery::class)->execute($this->company, [], $this->owner);
        $custRow = collect($custBalances->rows)->firstWhere('customer_id', $customer->id);
        $this->assertNotNull($custRow);
        $this->assertSame('0.000000', $custRow['outstanding_balance']);

        // 4. Vendor Balances Report: Vendor fully paid
        $vendBalances = app(VendorBalancesReportQuery::class)->execute($this->company, [], $this->owner);
        $vendRow = collect($vendBalances->rows)->firstWhere('vendor_id', $this->vendor->id);
        $this->assertNotNull($vendRow);
        $this->assertSame('0.000000', $vendRow['balance']);

        // -------------------------------------------------------------------------
        // Step 13: Verify Control Company B Zero Contamination (Journey J16)
        // -------------------------------------------------------------------------
        $companyBFingerprintAfter = $this->companyFingerprint($this->companyB);
        $this->assertSame($companyBFingerprintBefore, $companyBFingerprintAfter);

        // -------------------------------------------------------------------------
        // Step 14: Execute and Assert All SIX Domain Reconciliations
        // -------------------------------------------------------------------------
        // 1. Accounting Reconciliation
        $accountingReport = app(AccountingReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($accountingReport->isHealthy, json_encode($accountingReport->violations));
        $this->assertEmpty($accountingReport->violations);

        // 2. Inventory Reconciliation
        $inventoryReport = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($inventoryReport->isHealthy, json_encode($inventoryReport->discrepancies));
        $this->assertEmpty($inventoryReport->discrepancies);
        $this->assertSame('1056.000000', $inventoryReport->totalValuationBase);

        // 3. Money Reconciliation
        $moneyReport = app(MoneyReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($moneyReport->isHealthy, json_encode($moneyReport->violations));
        $this->assertEmpty($moneyReport->violations);

        // 4. Phase 7 Reconciliation (Landed cost & expense integrity)
        $phase7Report = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($phase7Report->isHealthy, json_encode($phase7Report->violations));
        $this->assertEmpty($phase7Report->violations);

        // 5. Sales Reconciliation
        $salesReport = app(SalesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($salesReport->isHealthy, json_encode($salesReport->violations));
        $this->assertEmpty($salesReport->violations);

        // 6. Payables Reconciliation (Service interface, NOT artisan command)
        $payablesReport = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($payablesReport->isHealthy, json_encode($payablesReport->violations));
        $this->assertEmpty($payablesReport->violations);
    }

    /**
     * Compute SHA-256 fingerprint of all company-owned rows across economic tables.
     *
     * @return array<string, string>
     */
    private function companyFingerprint(Company $company): array
    {
        $tables = [
            'purchases',
            'purchase_lines',
            'purchase_line_lots',
            'purchase_returns',
            'purchase_return_lines',
            'sales_invoices',
            'sales_invoice_lines',
            'sales_returns',
            'sales_return_lines',
            'posting_batches',
            'posting_lines',
            'stock_movements',
            'inventory_balances',
            'inventory_cost_states',
            'inventory_lots',
            'inventory_lot_balances',
            'customer_payments',
            'customer_payment_allocations',
            'vendor_payments',
            'vendor_payment_allocations',
            'money_transfers',
            'checks',
            'check_events',
            'expenses',
            'employee_advances',
            'salary_entries',
            'salary_advance_allocations',
            'salary_payments',
            'salary_payment_allocations',
            'landed_cost_allocations',
            'document_sequences',
        ];

        $hashes = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)
                ->where('company_id', $company->id)
                ->orderBy('id')
                ->get()
                ->all();

            $hashes[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }

        return $hashes;
    }
}
