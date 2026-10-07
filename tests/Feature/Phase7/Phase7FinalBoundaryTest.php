<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Expenses\ReverseExpenseAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Purchasing\AllocateLandedCostAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\UpdatePurchaseDraftAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Livewire\Pages\Expenses\ExpenseCategoryIndex;
use App\Livewire\Pages\Expenses\ExpenseDetail;
use App\Models\Expense;
use App\Models\LandedCostAllocation;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Expenses\ExpenseAttachment;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Phase7\Phase7History;
use App\Services\Phase7\Phase7SettlementAccounts;
use App\Services\Purchasing\PurchasePostingCommandBuilder;
use App\Services\Purchasing\VendorCatalogService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Livewire;

class Phase7FinalBoundaryTest extends Phase7TestCase
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

    private function draftData(): array
    {
        return ['vendor_id' => $this->vendor->id, 'warehouse_id' => $this->warehouse->id, 'purchase_date' => '2026-10-03', 'currency_code' => 'ILS', 'exchange_rate' => '1',
            'lines' => [['product_id' => $this->stockProduct1->id, 'quantity' => '1', 'unit_cost' => '15'], ['product_id' => $this->stockProduct2->id, 'quantity' => '1', 'unit_cost' => '25']]];
    }

    public function test_manual_plan_replacement_uses_new_line_numbers_and_recomputes_exactly(): void
    {
        $expense = $this->postLandedExpense('10');
        $purchase = $this->createPurchaseDraft($this->draftData()['lines']);
        $ids = $purchase->lines()->orderBy('line_number')->pluck('id');
        app(AllocateLandedCostAction::class)->execute($purchase, $expense, 'manual', $this->owner, [$ids[0] => '7', $ids[1] => '3']);
        $updated = app(UpdatePurchaseDraftAction::class)->execute($purchase, $this->owner, $this->draftData() + ['landed_cost_manual_allocations' => [$expense->id => [1 => '6', 2 => '4']]]);
        $rows = LandedCostAllocation::where('expense_id', $expense->id)->orderBy('purchase_line_id')->get();
        $this->assertSame(['6.000000', '4.000000'], $rows->pluck('allocated_base')->all());
        $this->assertSame($updated->lines()->orderBy('line_number')->pluck('id')->all(), $rows->pluck('purchase_line_id')->all());
        $this->assertEmpty(array_intersect($ids->all(), $rows->pluck('purchase_line_id')->all()));
    }

    public function test_missing_manual_replacement_rolls_back_header_lines_and_plan(): void
    {
        $expense = $this->postLandedExpense('10');
        $p = $this->createPurchaseDraft($this->draftData()['lines']);
        $ids = $p->lines()->pluck('id');
        app(AllocateLandedCostAction::class)->execute($p, $expense, 'manual', $this->owner, [$ids[0] => '10']);
        $before = LandedCostAllocation::where('expense_id', $expense->id)->get()->toJson();
        try {
            app(UpdatePurchaseDraftAction::class)->execute($p, $this->owner, $this->draftData());
            $this->fail('Expected manual plan rejection');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame($ids->all(), $p->lines()->pluck('id')->all());
        $this->assertSame($before, LandedCostAllocation::where('expense_id', $expense->id)->get()->toJson());
    }

    public function test_cancelled_draft_plan_does_not_block_line_replacement(): void
    {
        $e = $this->postLandedExpense('10');
        $p = $this->createPurchaseDraft($this->draftData()['lines']);
        app(AllocateLandedCostAction::class)->execute($p, $e, 'quantity', $this->owner);
        app(ReverseExpenseAction::class)->execute($e, $this->owner, 'Cancelled freight', '2026-10-03');
        $this->assertSame(2, LandedCostAllocation::where('expense_id', $e->id)->where('status', 'cancelled')->count());
        $updated = app(UpdatePurchaseDraftAction::class)->execute($p, $this->owner, $this->draftData());
        $this->assertCount(2, $updated->lines);
        $this->assertSame(0, LandedCostAllocation::where('expense_id', $e->id)->count());
        $this->assertSame('reversed', $e->fresh()->status);
    }

    public function test_tiny_weighted_parts_never_become_negative_and_multiple_costs_capitalize_exactly(): void
    {
        $lines = array_fill(0, 8, ['product_id' => $this->stockProduct1->id, 'quantity' => '1', 'unit_cost' => '1']);
        $p = $this->createPurchaseDraft($lines);
        $e = $this->postLandedExpense('0.01');
        $rows = app(AllocateLandedCostAction::class)->execute($p, $e, 'quantity', $this->owner);
        $this->assertTrue($rows->every(fn ($r) => ! BigDecimal::of($r->allocated_base)->isNegative()));
        $sum = BigDecimal::zero();
        foreach ($rows as $r) {
            $sum = $sum->plus($r->allocated_base);
        }$this->assertTrue($sum->isEqualTo('0.01'));
        $other = $this->postLandedExpense('0.02');
        app(AllocateLandedCostAction::class)->execute($p, $other, 'value', $this->owner);
        $posted = app(PostPurchaseAction::class)->execute($p, $this->owner);
        $sum = BigDecimal::zero();
        foreach ($posted->lines as $l) {
            $sum = $sum->plus($l->landed_cost_allocated_base);
        }
        $this->assertTrue($sum->isEqualTo('0.03'));
        app(PurchasePostingCommandBuilder::class)->validatePosted($posted);
        $this->assertSame(16, LandedCostAllocation::where('purchase_id', $p->id)->where('status', 'locked')->count());
    }

    public function test_foreign_line_allocation_corruption_blocks_purchase_without_effects(): void
    {
        $e = $this->postLandedExpense('10');
        $p = $this->createPurchaseDraft($this->draftData()['lines']);
        $other = $this->createPurchaseDraft($this->draftData()['lines']);
        app(AllocateLandedCostAction::class)->execute($p, $e, 'quantity', $this->owner);
        DB::table('landed_cost_allocations')->where('purchase_id', $p->id)->limit(1)->update(['purchase_line_id' => $other->lines()->first()->id]);
        $counts = [PostingBatch::count(), DB::table('stock_movements')->count(), DB::table('document_sequences')->get()->toJson()];
        try {
            app(PostPurchaseAction::class)->execute($p, $this->owner);
            $this->fail('Expected corrupt plan rejection');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame($counts, [PostingBatch::count(), DB::table('stock_movements')->count(), DB::table('document_sequences')->get()->toJson()]);
        $this->assertSame('draft', $p->fresh()->status);
    }

    public function test_locked_plan_cannot_be_rewritten_or_quietly_deleted(): void
    {
        $e = $this->postLandedExpense('10');
        $p = $this->createPurchaseDraft($this->draftData()['lines']);
        app(AllocateLandedCostAction::class)->execute($p, $e, 'quantity', $this->owner);
        app(PostPurchaseAction::class)->execute($p, $this->owner);
        $row = LandedCostAllocation::where('purchase_id', $p->id)->first();
        $row->status = 'draft';
        foreach (['saveQuietly', 'deleteQuietly'] as $method) {
            try {
                $row->$method();
                $this->fail('Expected immutable allocation');
            } catch (ImmutableRecordException) {
            }
        }
        $this->assertSame('locked', $row->fresh()->status);
    }

    public function test_canonical_expense_cannot_be_quietly_deleted(): void
    {
        $e = $this->postLandedExpense('10');
        try {
            $e->deleteQuietly();
            $this->fail('Expected immutable financial source');
        } catch (ImmutableRecordException) {
        }
        $this->assertNotNull($e->fresh());
        app(Phase7History::class)->validate($e);
    }

    public function test_expense_category_page_only_offers_active_operating_children(): void
    {
        Livewire::test(ExpenseCategoryIndex::class)->assertSuccessful()->assertViewHas('accounts', fn ($accounts) => $accounts->isNotEmpty() && $accounts->every(fn ($a) => ! $a->is_control && $a->active && $a->normal_balance === 'debit'));
    }

    public function test_attachment_path_cannot_cross_company_or_escape_private_folder(): void
    {
        $guard = app(ExpenseAttachment::class);
        foreach (['.env', 'expenses/999/file.pdf', 'expenses/'.$this->company->id.'/../secret.pdf'] as $path) {
            try {
                $guard->assertPath((int) $this->company->id, $path);
                $this->fail('Expected private path rejection');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $guard->validate((int) $this->company->id, 'expenses/'.$this->company->id.'/private-file.pdf', 'receipt.pdf', 'application/pdf', 128);
        $this->addToAssertionCount(1);
    }

    public function test_rollback_refuses_before_first_ddl_when_only_payroll_history_exists(): void
    {
        app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, ['employee_id' => $this->employee->id, 'advance_date' => '2026-10-01', 'currency_code' => 'ILS', 'amount' => '1', 'exchange_rate' => '1', 'payment_method' => 'cash', 'money_account_id' => $this->cashAccount->id, 'idempotency_key' => 'rollback-probe']);
        $migration = require database_path('migrations/2026_10_07_200002_create_landed_cost_and_purchase_lines_tables.php');
        try {
            $migration->down();
            $this->fail('Expected rollback refusal');
        } catch (\RuntimeException) {
        }
        $this->assertTrue(Schema::hasTable('landed_cost_allocations'));
        $this->assertTrue(Schema::hasColumn('purchase_lines', 'landed_cost_allocated_base'));
        $this->assertSame(1, DB::table('employee_advances')->count());
    }

    public function test_new_selection_excludes_retired_ledger_without_deleting_account(): void
    {
        DB::table('ledger_accounts')->where('id', $this->cashAccount->ledger_account_id)->update(['active' => false]);
        $this->assertFalse(app(Phase7SettlementAccounts::class)->query((int) $this->company->id)->whereKey($this->cashAccount->id)->exists());
        $this->assertTrue($this->cashAccount->fresh()->is_active);
    }

    public function test_capitalized_expense_detail_shows_actual_amount_unit_and_translated_state(): void
    {
        $e = $this->postLandedExpense('10');
        $p = $this->createPurchaseDraft($this->draftData()['lines']);
        app(AllocateLandedCostAction::class)->execute($p, $e, 'quantity', $this->owner);
        app(PostPurchaseAction::class)->execute($p, $this->owner);
        $name = $e->category_snapshot['name_ar'];
        DB::table('expense_categories')->where('id', $e->category_id)->update(['name_ar' => 'MUTATED_CATEGORY']);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(ExpenseDetail::class, ['publicId' => $e->public_id])->assertSee('5.000000 ILS')->assertSee(__('expenses.allocation_locked'))->assertDontSee('purchasing.item')->assertDontSee('MUTATED_CATEGORY');
        }
        app()->setLocale('ar');
        $this->assertNotEmpty($name);
    }
}
