<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Expenses\ReverseExpenseAction;
use App\Actions\Purchasing\AllocateLandedCostAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Actions\Purchasing\UpdatePurchaseDraftAction;
use App\Models\Expense;
use App\Models\LandedCostAllocation;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LandedCostTest extends Phase7TestCase
{
    protected Vendor $vendor;

    protected Product $stockProduct1;

    protected Product $stockProduct2;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد الشحن والمواد',
            'name_en' => 'Freight and Materials Vendor',
        ]);

        $pieceUnit = Unit::where('code', 'piece')->firstOrFail();

        $this->stockProduct1 = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج أ',
            'name_en' => 'Product A',
            'sku' => 'PROD-A',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $pieceUnit->id,
            'default_purchase_cost_base' => '100.000000',
        ], $this->owner->id);

        $this->stockProduct2 = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج ب',
            'name_en' => 'Product B',
            'sku' => 'PROD-B',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $pieceUnit->id,
            'default_purchase_cost_base' => '200.000000',
        ], $this->owner->id);

        $this->warehouse = Warehouse::firstOrFail();
    }

    private function postLandedExpense(string $amount = '100.000000', string $date = '2026-10-02'): Expense
    {
        return app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->landedCategory->id,
            'expense_date' => $date,
            'classification' => Expense::CLASSIFICATION_LANDED_COST,
            'description' => 'شحن وتخليص جمركي',
            'currency_code' => 'ILS',
            'amount' => $amount,
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'idempotency_key' => 'landed-exp-'.uniqid(),
        ]);
    }

    private function createPurchaseDraft(array $lines, string $date = '2026-10-03'): Purchase
    {
        return app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'vendor_invoice_number' => 'INV-'.uniqid(),
            'purchase_date' => $date,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'ar',
            'lines' => $lines,
        ]);
    }

    public function test_allocate_landed_cost_by_value(): void
    {
        $expense = $this->postLandedExpense('100.000000');

        $purchase = $this->createPurchaseDraft([
            ['product_id' => $this->stockProduct1->id, 'quantity' => '2', 'unit_cost' => '100', 'lots' => []], // 200 ILS
            ['product_id' => $this->stockProduct2->id, 'quantity' => '3', 'unit_cost' => '200', 'lots' => []], // 600 ILS
        ]); // Total commercial = 800 ILS

        $action = app(AllocateLandedCostAction::class);
        $allocations = $action->execute($purchase, $expense, LandedCostAllocation::METHOD_VALUE, $this->owner);

        $this->assertCount(2, $allocations);

        $line1 = $purchase->lines()->orderBy('line_number')->first();
        $line2 = $purchase->lines()->orderBy('line_number')->skip(1)->first();

        $alloc1 = $allocations->where('purchase_line_id', $line1->id)->first();
        $alloc2 = $allocations->where('purchase_line_id', $line2->id)->first();

        // Line 1: 100 * (200 / 800) = 25.000000
        $this->assertSame('25.000000', $alloc1->allocated_base);
        // Line 2: remainder = 75.000000
        $this->assertSame('75.000000', $alloc2->allocated_base);

        $sum = BigDecimal::of($alloc1->allocated_base)->plus($alloc2->allocated_base);
        $this->assertTrue($sum->isEqualTo('100.000000'));
        $this->assertSame(LandedCostAllocation::STATUS_DRAFT, $alloc1->status);
    }

    public function test_allocate_landed_cost_by_quantity(): void
    {
        $expense = $this->postLandedExpense('100.000000');

        $purchase = $this->createPurchaseDraft([
            ['product_id' => $this->stockProduct1->id, 'quantity' => '1', 'unit_cost' => '500', 'lots' => []], // qty = 1
            ['product_id' => $this->stockProduct2->id, 'quantity' => '3', 'unit_cost' => '100', 'lots' => []], // qty = 3
        ]); // Total qty = 4

        $action = app(AllocateLandedCostAction::class);
        $allocations = $action->execute($purchase, $expense, LandedCostAllocation::METHOD_QUANTITY, $this->owner);

        $this->assertCount(2, $allocations);

        $line1 = $purchase->lines()->orderBy('line_number')->first();
        $line2 = $purchase->lines()->orderBy('line_number')->skip(1)->first();

        $alloc1 = $allocations->where('purchase_line_id', $line1->id)->first();
        $alloc2 = $allocations->where('purchase_line_id', $line2->id)->first();

        // Line 1: 100 * (1 / 4) = 25.000000
        $this->assertSame('25.000000', $alloc1->allocated_base);
        // Line 2: remainder = 75.000000
        $this->assertSame('75.000000', $alloc2->allocated_base);
    }

    public function test_allocate_landed_cost_manual_and_validation(): void
    {
        $expense = $this->postLandedExpense('100.000000');

        $purchase = $this->createPurchaseDraft([
            ['product_id' => $this->stockProduct1->id, 'quantity' => '2', 'unit_cost' => '100', 'lots' => []],
            ['product_id' => $this->stockProduct2->id, 'quantity' => '1', 'unit_cost' => '200', 'lots' => []],
        ]);

        $line1 = $purchase->lines()->orderBy('line_number')->first();
        $line2 = $purchase->lines()->orderBy('line_number')->skip(1)->first();

        $action = app(AllocateLandedCostAction::class);

        // Mismatched sum fails
        $this->expectException(InvalidArgumentException::class);
        $action->execute($purchase, $expense, LandedCostAllocation::METHOD_MANUAL, $this->owner, [
            $line1->id => '40.000000',
            $line2->id => '50.000000', // sum = 90 != 100
        ]);
    }

    public function test_draft_edit_recomputes_automatic_landed_cost(): void
    {
        $expense = $this->postLandedExpense('100.000000');

        $purchase = $this->createPurchaseDraft([
            ['product_id' => $this->stockProduct1->id, 'quantity' => '2', 'unit_cost' => '100', 'lots' => []], // 200
            ['product_id' => $this->stockProduct2->id, 'quantity' => '2', 'unit_cost' => '100', 'lots' => []], // 200
        ]);

        app(AllocateLandedCostAction::class)->execute($purchase, $expense, LandedCostAllocation::METHOD_VALUE, $this->owner);

        // Initial allocation is 50 / 50
        $this->assertSame(2, LandedCostAllocation::where('purchase_id', $purchase->id)->count());

        // Update draft lines: change line 1 to cost 300, line 2 to cost 100 (ratio 3:1)
        $updatedPurchase = app(UpdatePurchaseDraftAction::class)->execute($purchase, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'vendor_invoice_number' => $purchase->vendor_invoice_number,
            'purchase_date' => '2026-10-03',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'ar',
            'lines' => [
                ['product_id' => $this->stockProduct1->id, 'quantity' => '1', 'unit_cost' => '300', 'lots' => []], // 300
                ['product_id' => $this->stockProduct2->id, 'quantity' => '1', 'unit_cost' => '100', 'lots' => []], // 100
            ],
        ]);

        $newAllocations = LandedCostAllocation::where('purchase_id', $updatedPurchase->id)
            ->where('status', LandedCostAllocation::STATUS_DRAFT)
            ->get();

        $this->assertCount(2, $newAllocations);
        $newLine1 = $updatedPurchase->lines()->orderBy('line_number')->first();
        $newLine2 = $updatedPurchase->lines()->orderBy('line_number')->skip(1)->first();

        // Line 1: 100 * (300 / 400) = 75.000000
        $this->assertSame('75.000000', $newAllocations->where('purchase_line_id', $newLine1->id)->first()->allocated_base);
        // Line 2: remainder = 25.000000
        $this->assertSame('25.000000', $newAllocations->where('purchase_line_id', $newLine2->id)->first()->allocated_base);
    }

    public function test_post_purchase_with_landed_cost_capitalizes_inventory_and_posts_gl(): void
    {
        $expense = $this->postLandedExpense('100.000000', '2026-10-02');

        $purchase = $this->createPurchaseDraft([
            ['product_id' => $this->stockProduct1->id, 'quantity' => '10', 'unit_cost' => '100', 'lots' => []], // 1000 ILS commercial
        ], '2026-10-03');

        app(AllocateLandedCostAction::class)->execute($purchase, $expense, LandedCostAllocation::METHOD_VALUE, $this->owner);

        $postedPurchase = app(PostPurchaseAction::class)->execute($purchase, $this->owner);

        $this->assertSame(Purchase::STATUS_POSTED, $postedPurchase->status);

        $line = $postedPurchase->lines->first();
        $this->assertSame('100.000000', $line->landed_cost_allocated_base);
        // Total acquisition = 1000 + 100 = 1100. Unit cost = 110.000000
        $this->assertSame('110.000000', $line->inventory_unit_cost_base);

        // Check locked allocations
        $alloc = LandedCostAllocation::where('purchase_id', $purchase->id)->first();
        $this->assertSame(LandedCostAllocation::STATUS_LOCKED, $alloc->status);
        $this->assertNotNull($alloc->locked_at);

        // Check GL batch
        /** @var PostingBatch $batch */
        $batch = PostingBatch::findOrFail($postedPurchase->posting_batch_id);
        $glLines = $batch->lines()->orderBy('line_number')->get();

        $inventoryAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'inventory')->firstOrFail();
        $payableAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_payable')->firstOrFail();
        $clearingAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'landed_cost_clearing')->firstOrFail();

        // Line 1: Dr Inventory commercial 1000
        $this->assertSame($inventoryAccount->id, $glLines[0]->ledger_account_id);
        $this->assertSame('1000.000000', $glLines[0]->debit_base);

        // Line 2: Cr AP commercial 1000 (AP is NOT increased by landed cost!)
        $this->assertSame($payableAccount->id, $glLines[1]->ledger_account_id);
        $this->assertSame('1000.000000', $glLines[1]->credit_base);

        // Line 3: Dr Inventory landed 100
        $this->assertSame($inventoryAccount->id, $glLines[2]->ledger_account_id);
        $this->assertSame('100.000000', $glLines[2]->debit_base);
        $this->assertNull($glLines[2]->transaction_currency_code);

        // Line 4: Cr Landed Cost Clearing 100
        $this->assertSame($clearingAccount->id, $glLines[3]->ledger_account_id);
        $this->assertSame('100.000000', $glLines[3]->credit_base);
        $this->assertNull($glLines[3]->transaction_currency_code);

        // Verify clearing account net balance is now 0 (Dr 100 from expense, Cr 100 from purchase)
        $clearingDebits = DB::table('posting_lines')
            ->where('company_id', $this->company->id)
            ->where('ledger_account_id', $clearingAccount->id)
            ->sum('debit_base');

        $clearingCredits = DB::table('posting_lines')
            ->where('company_id', $this->company->id)
            ->where('ledger_account_id', $clearingAccount->id)
            ->sum('credit_base');

        $this->assertTrue(BigDecimal::of((string) $clearingDebits)->isEqualTo((string) $clearingCredits));

        // Reversal of capitalized expense must be blocked!
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Capitalized landed expense cannot be reversed.');
        app(ReverseExpenseAction::class)->execute($expense, $this->owner, 'Attempt to reverse capitalized');
    }

    public function test_chronology_blocker_purchase_date_before_expense_date(): void
    {
        $expense = $this->postLandedExpense('100.000000', '2026-10-05');

        // Purchase is dated earlier than expense!
        $purchase = $this->createPurchaseDraft([
            ['product_id' => $this->stockProduct1->id, 'quantity' => '10', 'unit_cost' => '100', 'lots' => []],
        ], '2026-10-02');

        app(AllocateLandedCostAction::class)->execute($purchase, $expense, LandedCostAllocation::METHOD_VALUE, $this->owner);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Eligible expense/chronology');
        app(PostPurchaseAction::class)->execute($purchase, $this->owner);
    }

    public function test_purchase_return_compatibility_with_landed_cost(): void
    {
        $expense = $this->postLandedExpense('100.000000', '2026-10-02');

        $purchase = $this->createPurchaseDraft([
            ['product_id' => $this->stockProduct1->id, 'quantity' => '10', 'unit_cost' => '100', 'lots' => []], // 1000 commercial + 100 landed = 1100
        ], '2026-10-03');

        app(AllocateLandedCostAction::class)->execute($purchase, $expense, LandedCostAllocation::METHOD_VALUE, $this->owner);
        $postedPurchase = app(PostPurchaseAction::class)->execute($purchase, $this->owner);

        // Return 5 pieces
        $returnDraft = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, [
            'purchase_id' => $postedPurchase->id,
            'return_date' => '2026-10-04',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'lines' => [
                [
                    'purchase_line_id' => $postedPurchase->lines->first()->id,
                    'quantity' => '5',
                    'lots' => [],
                ],
            ],
        ]);

        $postedReturn = app(PostPurchaseReturnAction::class)->execute($returnDraft, $this->owner);

        $this->assertSame('posted', $postedReturn->status);

        // Commercial AP relief is 5 * 100 = 500
        $this->assertSame('500.000000', $postedReturn->grand_total_base);

        $returnLine = $postedReturn->lines->first();
        // Inventory removed includes landed cost: 5 * 110 = 550
        $this->assertSame('550.000000', $returnLine->inventory_value_removed_base);
        // Valuation adjustment is 550 - 500 = 50
        $this->assertSame('50.000000', $returnLine->valuation_adjustment_base);

        // Verify GL batch has Dr Accounts Payable 500, Dr Valuation Adjustment 50, Cr Inventory 550
        /** @var PostingBatch $batch */
        $batch = PostingBatch::findOrFail($postedReturn->posting_batch_id);
        $lines = $batch->lines()->orderBy('line_number')->get();

        $apAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'accounts_payable')->firstOrFail();
        $invAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'inventory')->firstOrFail();
        $cogsAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'cogs')->firstOrFail();

        // Line 1: Dr Accounts Payable (A)
        $this->assertSame($apAccount->id, $lines[0]->ledger_account_id);
        $this->assertSame('500.000000', $lines[0]->debit_base);

        // Line 2: Cr Inventory commercial (H)
        $this->assertSame($invAccount->id, $lines[1]->ledger_account_id);
        $this->assertSame('500.000000', $lines[1]->credit_base);

        // Line 3: Dr COGS valuation difference (D)
        $this->assertSame($cogsAccount->id, $lines[2]->ledger_account_id);
        $this->assertSame('50.000000', $lines[2]->debit_base);

        // Line 4: Cr Inventory valuation difference (D)
        $this->assertSame($invAccount->id, $lines[3]->ledger_account_id);
        $this->assertSame('50.000000', $lines[3]->credit_base);

        // Total Inventory credited = 500 + 50 = 550
        $totalInvCredit = BigDecimal::of($lines[1]->credit_base)->plus($lines[3]->credit_base);
        $this->assertTrue($totalInvCredit->isEqualTo('550.000000'));
    }
}
