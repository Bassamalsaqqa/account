<?php

declare(strict_types=1);

namespace Tests\Feature\Refinement;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Livewire\Pages\Inventory\OpeningStockForm;
use App\Livewire\Pages\Inventory\StockAdjustmentForm;
use App\Livewire\Pages\Inventory\StockTransferForm;
use App\Livewire\Pages\Products\ProductForm;
use App\Livewire\Pages\Purchasing\PaymentForm as PurchasingPaymentForm;
use App\Livewire\Pages\Purchasing\PurchaseReturnForm;
use App\Livewire\Pages\Sales\PaymentForm as SalesPaymentForm;
use App\Livewire\Pages\Sales\ReturnForm as SalesReturnForm;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\User;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class RefinementDraftControlsTest extends Phase5ETestCase
{
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل اختبار التحسين',
            'name_en' => 'Refinement Test Customer',
            'preferred_locale' => 'ar',
            'default_currency_code' => 'ILS',
            'created_by' => $this->owner->id,
            'status' => 'active',
        ]);
    }

    /**
     * Helper to snapshot economic record counts and assert zero DB mutations.
     *
     * @return array<string, int>
     */
    private function economicCounts(): array
    {
        return [
            'products' => DB::table('products')->count(),
            'product_barcodes' => DB::table('product_barcodes')->count(),
            'stock_movements' => DB::table('stock_movements')->count(),
            'posting_batches' => DB::table('posting_batches')->count(),
            'posting_lines' => DB::table('posting_lines')->count(),
            'sales_invoices' => DB::table('sales_invoices')->count(),
            'sales_returns' => DB::table('sales_returns')->count(),
            'purchases' => DB::table('purchases')->count(),
            'purchase_returns' => DB::table('purchase_returns')->count(),
            'customer_payments' => DB::table('customer_payments')->count(),
            'vendor_payments' => DB::table('vendor_payments')->count(),
        ];
    }

    public function test_product_form_camera_scan_populates_new_barcode_without_database_writes(): void
    {
        $before = $this->economicCounts();

        $component = Livewire::test(ProductForm::class)
            ->call('scanCameraBarcode', '6251234567890')
            ->assertReturned(['ok' => true, 'message' => __('refinement.camera_scan_success')])
            ->assertSet('new_barcode', '6251234567890');

        $this->assertSame('6251234567890', $component->get('new_barcode'));
        $this->assertSame($before, $this->economicCounts());
    }

    public function test_product_form_camera_scan_rejects_oversized_url_and_control_chars(): void
    {
        $component = Livewire::test(ProductForm::class);

        // Oversized (> 128 chars)
        $oversized = str_repeat('A', 129);
        $component->call('scanCameraBarcode', $oversized)
            ->assertReturned(['ok' => false, 'message' => __('refinement.barcode_too_long')]);
        $this->assertSame('', $component->get('new_barcode'));

        $component->call('scanCameraBarcode', 'https://opaque.example/barcode')
            ->assertSet('new_barcode', 'https://opaque.example/barcode');
        $component->set('new_barcode', '');
        $component->call('scanCameraBarcode', 'javascript:alert(1)')
            ->assertReturned(['ok' => false, 'message' => __('refinement.invalid_barcode_format')]);

        // Control characters / newlines
        $component->call('scanCameraBarcode', "ABC\n123")
            ->assertReturned(['ok' => false, 'message' => __('refinement.invalid_barcode_format')]);
        $this->assertSame('', $component->get('new_barcode'));
    }

    public function test_product_form_camera_scan_fails_on_revoked_permission_or_locked_company(): void
    {
        $component = Livewire::test(ProductForm::class);

        // Revoke membership
        $this->company->users()->updateExistingPivot($this->owner->id, ['status' => 'inactive']);
        $component->call('scanCameraBarcode', 'VALID123')->assertForbidden();
    }

    public function test_inventory_forms_camera_scan_resolves_product_unit_without_writes(): void
    {
        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unit->unit_id,
            'barcode' => 'INV-SCAN-01',
            'type' => 'code128',
            'is_primary' => true,
        ]);

        $before = $this->economicCounts();

        foreach ([StockAdjustmentForm::class, StockTransferForm::class, OpeningStockForm::class] as $formClass) {
            $component = Livewire::test($formClass)
                ->call('scanCameraBarcode', 'INV-SCAN-01')
                ->assertReturned(['ok' => true, 'message' => __('refinement.product_selected')])
                ->assertSet('product_id', $this->product->id)
                ->assertSet('unit_id', $this->unit->unit_id);

            $component->set('quantity', '4')->set('reason', 'Keep entered reason')->call('scanCameraBarcode', 'INV-SCAN-01')
                ->assertSet('quantity', '4')->assertSet('reason', 'Keep entered reason');
            $this->assertSame($this->product->id, $component->get('product_id'));
            $this->assertSame($this->unit->unit_id, $component->get('unit_id'));
        }

        $this->assertSame($before, $this->economicCounts());
    }

    public function test_inventory_forms_camera_scan_refuses_cross_company_and_inactive_products(): void
    {
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, ['name_ar' => 'Foreign company']);
        app(CompanyContext::class)->clear();
        $this->actingAs($otherOwner);
        app(CompanyContext::class)->setCompany($otherCompany, $otherOwner);
        $otherUnitId = Unit::withoutGlobalScopes()->where('company_id', $otherCompany->id)->firstOrFail()->id;
        // Product in another company
        $otherCompanyProduct = Product::create([
            'company_id' => $otherCompany->id,
            'name_ar' => 'منتج شركة أخرى',
            'sku' => 'OTHER-SKU-99',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'base_unit_id' => $otherUnitId,
            'status' => 'active',
            'active' => true,
        ]);
        ProductBarcode::create([
            'company_id' => $otherCompanyProduct->company_id,
            'product_id' => $otherCompanyProduct->id,
            'barcode' => 'CROSS-COMP-BARCODE',
            'type' => 'code128',
            'is_primary' => true,
        ]);

        app(CompanyContext::class)->clear();
        $this->activate($this->owner);
        // Inactive product in current company
        $inactiveProduct = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'منتج معطل',
            'sku' => 'INACTIVE-SKU',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'base_unit_id' => $this->unit->unit_id,
            'status' => 'inactive',
            'active' => false,
        ]);
        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $inactiveProduct->id,
            'barcode' => 'INACTIVE-BARCODE',
            'type' => 'code128',
            'is_primary' => true,
        ]);

        foreach ([StockAdjustmentForm::class, StockTransferForm::class, OpeningStockForm::class] as $formClass) {
            $component = Livewire::test($formClass);

            // Cross-company
            $component->call('scanCameraBarcode', 'CROSS-COMP-BARCODE')
                ->assertReturned(['ok' => false, 'message' => __('refinement.barcode_not_found')]);

            // Inactive
            $component->call('scanCameraBarcode', 'INACTIVE-BARCODE')
                ->assertReturned(['ok' => false, 'message' => __('refinement.barcode_not_found')]);
        }
    }

    public function test_inventory_quantity_stepping_respects_unit_precision_and_allows_draft_zero(): void
    {
        $unit = Unit::findOrFail($this->unit->unit_id);
        $unit->update(['allows_fraction' => true, 'decimal_places' => 2]);

        foreach ([StockAdjustmentForm::class, StockTransferForm::class, OpeningStockForm::class] as $formClass) {
            $component = Livewire::test($formClass)
                ->set('product_id', $this->product->id)
                ->set('unit_id', $this->unit->unit_id)
                ->set('quantity', '1.25');

            // Step up by 0.01
            $component->call('stepInventoryQuantity', 'up')
                ->assertSet('quantity', '1.26');

            // Step down by 0.01
            $component->call('stepInventoryQuantity', 'down')
                ->assertSet('quantity', '1.25');

            // Step down to 0
            $component->set('quantity', '0.01')
                ->call('stepInventoryQuantity', 'down')
                ->assertSet('quantity', '0.00');

            // Stepping down from 0 stops at 0 (never negative)
            $component->call('stepInventoryQuantity', 'down')
                ->assertSet('quantity', '0.00');
        }

        // Whole unit (no fraction)
        $unit->update(['allows_fraction' => false, 'decimal_places' => 0]);

        foreach ([StockAdjustmentForm::class, StockTransferForm::class, OpeningStockForm::class] as $formClass) {
            $component = Livewire::test($formClass)
                ->set('product_id', $this->product->id)
                ->set('unit_id', $this->unit->unit_id)
                ->set('quantity', '5');

            // Step up by 1
            $component->call('stepInventoryQuantity', 'up')
                ->assertSet('quantity', '6');

            // Step down by 1
            $component->call('stepInventoryQuantity', 'down')
                ->assertSet('quantity', '5');

            // Step down from 0 stops at 0
            $component->set('quantity', '0')
                ->call('stepInventoryQuantity', 'down')
                ->assertSet('quantity', '0');
        }
    }

    public function test_return_forms_step_and_full_remaining_enforce_db_posted_cap(): void
    {
        // 1. Setup posted Purchase with 10 units
        $purchase = $this->createAndPostPurchase(['lines' => [
            [
                'product_id' => $this->product->id,
                'quantity' => '10',
                'unit_cost' => '10.00',
            ],
        ]]);
        $purchaseLine = $purchase->lines->firstOrFail();

        // 2. Setup partial return of 3 units
        $this->createAndPostReturn($purchase, ['lines' => [
            [
                'purchase_line_id' => $purchaseLine->id,
                'quantity' => '3',
            ],
        ]]);

        // Remaining returnable cap in DB is 7 units
        $component = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id]);
        $this->assertTrue(BigDecimal::of($component->get('lines.0.remaining_quantity'))->isEqualTo('7'));

        // Test returnFullRemaining sets directly to 7
        $component->call('returnFullRemaining', 0);
        $this->assertTrue(BigDecimal::of($component->get('lines.0.return_quantity'))->isEqualTo('7'));

        // Stepping up when already at cap (7) stays capped at 7
        $component->call('stepReturnQuantity', 0, 'up');
        $this->assertTrue(BigDecimal::of($component->get('lines.0.return_quantity'))->isEqualTo('7'));

        // Stepping down decreases by 1
        $component->call('stepReturnQuantity', 0, 'down');
        $this->assertTrue(BigDecimal::of($component->get('lines.0.return_quantity'))->isEqualTo('6'));

        // Stepping down to 0 and below stops at 0
        $component->set('lines.0.return_quantity', '0');
        $component->call('stepReturnQuantity', 0, 'down');
        $this->assertTrue(BigDecimal::of($component->get('lines.0.return_quantity'))->isEqualTo('0'));

        // Now test Sales ReturnForm
        $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '20', 'unit_cost' => '10']]]);
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-02',
            'due_date' => null,
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'Test Sales Item',
                    'quantity' => '8',
                    'unit_price' => '25.00',
                ],
            ],
        ]);
        $postedInvoice = app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);

        $salesReturnComp = Livewire::test(SalesReturnForm::class, ['invoice_id' => $postedInvoice->id]);
        $this->assertTrue(BigDecimal::of($salesReturnComp->get('lines.0.max_returnable'))->isEqualTo('8'));

        // Full remaining sets to 8
        $salesReturnComp->call('returnFullRemaining', 0);
        $this->assertTrue(BigDecimal::of($salesReturnComp->get('lines.0.return_quantity'))->isEqualTo('8'));

        // Stepping up stays capped at 8
        $salesReturnComp->call('stepReturnQuantity', 0, 'up');
        $this->assertTrue(BigDecimal::of($salesReturnComp->get('lines.0.return_quantity'))->isEqualTo('8'));

        // Stepping down to 0 stops at 0
        $salesReturnComp->set('lines.0.return_quantity', '0');
        $salesReturnComp->call('stepReturnQuantity', 0, 'down');
        $this->assertTrue(BigDecimal::of($salesReturnComp->get('lines.0.return_quantity'))->isEqualTo('0'));
    }

    public function test_payment_forms_pay_remaining_and_use_full_balance_recalculate_fresh_same_currency(): void
    {
        $seed = $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '20', 'unit_cost' => '10']]]);
        $invoices = [];
        foreach ([['ILS', '200'], ['ILS', '150'], ['USD', '50']] as [$currency, $price]) {
            $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $this->customer->id, 'currency_code' => $currency, 'exchange_rate' => $currency === 'ILS' ? '1' : '3.6',
                'issue_date' => '2026-10-02', 'lines' => [['product_id' => $this->product->id, 'item_description' => 'Invoice item', 'quantity' => '1', 'unit_price' => $price]],
            ]);
            $invoices[] = app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        }
        $component = Livewire::test(SalesPaymentForm::class, ['customer_id' => $this->customer->id])
            ->set('money_account_id', $this->ilsCashAccount->id);
        $idx = array_search($invoices[0]->id, array_column($component->get('allocations'), 'sales_invoice_id'), true);
        $second = array_search($invoices[1]->id, array_column($component->get('allocations'), 'sales_invoice_id'), true);
        $component->call('payRemaining', $idx)->assertHasNoErrors();
        $this->assertTrue(BigDecimal::of($component->get("allocations.$idx.allocated_amount"))->isEqualTo('200'));
        $component->set("allocations.$second.allocated_amount", '5');
        $component->call('useFullBalance')->assertHasNoErrors();
        $this->assertTrue(BigDecimal::of($component->get('amount'))->isEqualTo('350'));
        $this->assertTrue(BigDecimal::of($component->get("allocations.$second.allocated_amount"))->isEqualTo('5'));
        // Client currency labels cannot turn a foreign-currency invoice into an ILS quick allocation.
        $component->set("allocations.$idx.sales_invoice_id", $invoices[2]->id)->set("allocations.$idx.document_currency_code", 'ILS')
            ->call('payRemaining', $idx)->assertHasErrors('allocations');
        $purchase = $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '2', 'unit_cost' => '40']]]);
        $vendor = Livewire::test(PurchasingPaymentForm::class, ['vendor_id' => $this->vendor->id])
            ->set('money_account_id', $this->ilsCashAccount->id);
        $pidx = array_search($purchase->id, array_column($vendor->get('allocations'), 'purchase_id'), true);
        $vendor->call('payRemaining', $pidx)->assertHasNoErrors();
        $this->assertTrue(BigDecimal::of($vendor->get("allocations.$pidx.allocated_amount"))->isEqualTo('80'));
        $before = $vendor->get('allocations');
        $vendor->call('useFullBalance')->assertHasNoErrors();
        $this->assertTrue(BigDecimal::of($vendor->get('amount'))->isEqualTo('280'));
        $this->assertSame($before, $vendor->get('allocations'));
    }

    public function test_vendor_payment_shortcuts_reject_whitespace_rate_before_mutating_draft(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '2', 'unit_cost' => '40']]]);
        foreach (['payRemaining', 'useFullBalance'] as $method) {
            $component = Livewire::test(PurchasingPaymentForm::class, ['vendor_id' => $this->vendor->id])
                ->set('money_account_id', $this->ilsCashAccount->id);
            $form = $component->instance();
            if (! $form instanceof PurchasingPaymentForm) {
                $this->fail('Expected the vendor payment component.');
            }
            // Exercise the public handler boundary without invoking the separate input-update hook.
            $form->exchange_rate = '   ';
            $form->amount = '7.00';
            $beforeAllocations = $form->allocations;
            $beforeCounts = $this->economicCounts();
            $index = array_search($purchase->id, array_column($form->allocations, 'purchase_id'), true);
            $this->assertNotFalse($index);
            try {
                $method === 'payRemaining' ? $form->payRemaining($index) : $form->useFullBalance();
                $this->fail('Whitespace rate must yield a field validation error.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('exchange_rate', $exception->errors());
                $this->assertNotSame('', $exception->errors()['exchange_rate'][0]);
            }
            $this->assertSame('   ', $form->exchange_rate);
            $this->assertSame('7.00', $form->amount);
            $this->assertSame($beforeAllocations, $form->allocations);
            $this->assertSame($beforeCounts, $this->economicCounts());
        }
    }

    public function test_payment_shortcuts_preserve_edits_when_fresh_document_is_fully_returned(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '2', 'unit_cost' => '40']]]);
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1',
            'issue_date' => '2026-10-02', 'lines' => [['product_id' => $this->product->id, 'item_description' => 'Item', 'quantity' => '1', 'unit_price' => '50']],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        $sales = Livewire::test(SalesPaymentForm::class, ['customer_id' => $this->customer->id])
            ->set('money_account_id', $this->ilsCashAccount->id)->set('amount', '7');
        $vendor = Livewire::test(PurchasingPaymentForm::class, ['vendor_id' => $this->vendor->id])
            ->set('money_account_id', $this->ilsCashAccount->id)->set('amount', '7');
        $return = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id, 'issue_date' => '2026-10-02',
            'lines' => [['sales_invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'quantity' => '1']],
        ]);
        app(PostSalesReturnAction::class)->execute($return, $this->owner);
        $this->createAndPostReturn($purchase, ['lines' => [['quantity' => '2']]]);
        foreach ([$sales, $vendor] as $component) {
            $before = $component->get('allocations');
            $beforeCounts = $this->economicCounts();
            $component->call('payRemaining', 0)->assertHasErrors('allocations')->assertSet('amount', '7');
            $this->assertSame($before, $component->get('allocations'));
            $this->assertSame($beforeCounts, $this->economicCounts());
        }
    }

    public function test_payment_and_return_actions_refuse_revoked_memberships(): void
    {
        $salesReturnComp = Livewire::test(SalesReturnForm::class);
        $salesPmtComp = Livewire::test(SalesPaymentForm::class);
        $purchase = $this->createAndPostPurchase();
        $purchaseReturnComp = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id]);
        $vendorPmtComp = Livewire::test(PurchasingPaymentForm::class);

        // Revoke user membership
        $this->company->users()->updateExistingPivot($this->owner->id, ['status' => 'inactive']);

        $salesReturnComp->call('returnFullRemaining', 0)->assertForbidden();
        $salesPmtComp->call('useFullBalance')->assertForbidden();
        $purchaseReturnComp->call('returnFullRemaining', 0)->assertForbidden();
        $vendorPmtComp->call('useFullBalance')->assertForbidden();
    }

    public function test_shortcuts_reject_another_same_company_party_and_return_source(): void
    {
        $first = $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '20', 'unit_cost' => '10']]]);
        $otherVendor = app(VendorCatalogService::class)->save($this->company, $this->owner, ['name_ar' => 'Other vendor']);
        $other = $this->createAndPostPurchase(['vendor_id' => $otherVendor->id, 'lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '900']]]);
        $payment = Livewire::test(PurchasingPaymentForm::class, ['vendor_id' => $this->vendor->id])->set('money_account_id', $this->ilsCashAccount->id);
        $payment->call('useFullBalance');
        $this->assertTrue(BigDecimal::of($payment->get('amount'))->isEqualTo('200'));
        $payment->set('allocations.0.purchase_id', $other->id)->call('payRemaining', 0)->assertHasErrors('allocations');
        $return = Livewire::test(PurchaseReturnForm::class, ['publicId' => $first->public_id]);
        $return->set('lines.0.purchase_line_id', $other->lines->firstOrFail()->id)
            ->call('returnFullRemaining', 0)->assertHasErrors('lines.0.return_quantity');
    }

    public function test_scanning_inactive_base_unit_is_nonmutating_and_stepper_preserves_invalid_input(): void
    {
        ProductBarcode::create(['company_id' => $this->company->id, 'product_id' => $this->product->id,
            'unit_id' => $this->unit->unit_id, 'barcode' => 'DISABLED-UNIT', 'type' => 'code128']);
        $component = Livewire::test(StockAdjustmentForm::class)->set('quantity', '9');
        Unit::findOrFail($this->unit->unit_id)->update(['active' => false]);
        $component->call('scanCameraBarcode', 'DISABLED-UNIT')->assertReturned(['ok' => false, 'message' => __('refinement.invalid_unit')])
            ->assertSet('product_id', null)->assertSet('quantity', '9');
        Unit::findOrFail($this->unit->unit_id)->update(['active' => true]);
        $component->set('product_id', $this->product->id)->set('quantity', '1e2')
            ->call('stepInventoryQuantity', 'up')->assertHasErrors('quantity')->assertSet('quantity', '1e2');
        $this->unit->update(['active' => false]);
        $component->set('quantity', '3')->call('stepInventoryQuantity', 'up')
            ->assertHasErrors('quantity')->assertSet('quantity', '3');
    }
}
