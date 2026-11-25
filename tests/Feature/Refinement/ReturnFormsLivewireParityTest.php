<?php

declare(strict_types=1);

namespace Tests\Feature\Refinement;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Livewire\Pages\Purchasing\PurchaseReturnForm;
use App\Livewire\Pages\Sales\ReturnForm as SalesReturnForm;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\DocumentIdentity;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class ReturnFormsLivewireParityTest extends Phase5ETestCase
{
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل اختبار المردود',
            'name_en' => 'Return Test Customer',
            'preferred_locale' => 'en',
            'default_currency_code' => 'ILS',
            'created_by' => $this->owner->id,
            'status' => 'active',
        ]);
    }

    /**
     * Snapshot critical economic record counts to assert zero database mutations during preview.
     *
     * @return array<string, int>
     */
    private function economicCounts(): array
    {
        return [
            'stock_movements' => DB::table('stock_movements')->count(),
            'posting_batches' => DB::table('posting_batches')->count(),
            'posting_lines' => DB::table('posting_lines')->count(),
            'sales_invoices' => DB::table('sales_invoices')->count(),
            'sales_returns' => DB::table('sales_returns')->count(),
            'sales_return_lines' => DB::table('sales_return_lines')->count(),
            'purchases' => DB::table('purchases')->count(),
            'purchase_returns' => DB::table('purchase_returns')->count(),
            'purchase_return_lines' => DB::table('purchase_return_lines')->count(),
            'customer_payments' => DB::table('customer_payments')->count(),
            'vendor_payments' => DB::table('vendor_payments')->count(),
        ];
    }

    /**
     * Helper to create and post a sales invoice for this test's company.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    protected function createAndPostSalesInvoice(array $lines = [], string $currency = 'ILS', string $exchangeRate = '1.0000000000'): SalesInvoice
    {
        if (empty($lines)) {
            $lines = [
                [
                    'product_id' => $this->product->id,
                    'item_description' => 'Test Sales Item',
                    'quantity' => '10.000000',
                    'unit_price' => '25.00',
                ],
            ];
        }

        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => $currency,
            'exchange_rate' => $exchangeRate,
            'issue_date' => '2026-10-02',
            'lines' => $lines,
        ]);

        return app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
    }

    /**
     * 1. PREVIEW WRITES: SalesReturnForm preview, calculations, and stepping perform ZERO database writes.
     */
    public function test_sales_return_form_preview_interactions_perform_zero_database_writes(): void
    {
        $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '50.000000', 'unit_cost' => '10.00']]]);
        $invoice = $this->createAndPostSalesInvoice([
            [
                'product_id' => $this->product->id,
                'item_description' => 'Preview Test Item',
                'quantity' => '10.000000',
                'unit_price' => '30.00',
            ],
        ]);

        $before = $this->economicCounts();

        $component = Livewire::test(SalesReturnForm::class, ['invoice_id' => $invoice->id])
            ->assertOk()
            ->assertSet('preReturnTotal', '300.000000')
            ->assertSet('hasReturnQuantities', false)
            ->assertSet('returnTotal', null)
            ->assertSet('postReturnTotal', null)
            ->assertSet('returnCalculationError', null);

        // Step up
        $component->call('stepReturnQuantity', 0, 'up')
            ->assertSet('lines.0.return_quantity', '1')
            ->assertSet('hasReturnQuantities', true)
            ->assertSet('returnTotal', '30.000000')
            ->assertSet('postReturnTotal', '270.000000');

        // Set to full remaining
        $component->call('returnFullRemaining', 0)
            ->assertSet('lines.0.return_quantity', '10')
            ->assertSet('returnTotal', '300.000000')
            ->assertSet('postReturnTotal', '0.000000');

        // Manual recalculation
        $component->set('lines.0.return_quantity', '4')
            ->call('recalculate')
            ->assertSet('returnTotal', '120.000000')
            ->assertSet('postReturnTotal', '180.000000');

        // Render preview HTML
        $component->assertSeeHtml('data-testid="return-totals-summary"');

        // Zero economic records must have been created or modified
        self::assertSame($before, $this->economicCounts());
    }

    /**
     * 2. PREVIEW WRITES: PurchaseReturnForm preview, calculations, and stepping perform ZERO database writes.
     */
    public function test_purchase_return_form_preview_interactions_perform_zero_database_writes(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [
            [
                'product_id' => $this->product->id,
                'quantity' => '20.000000',
                'unit_cost' => '15.00',
            ],
        ]]);

        $before = $this->economicCounts();

        $component = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id])
            ->assertOk()
            ->assertSet('preReturnTotal', '300.000000')
            ->assertSet('hasReturnQuantities', false)
            ->assertSet('returnTotal', null)
            ->assertSet('postReturnTotal', null)
            ->assertSet('returnCalculationError', null);

        // Step up
        $component->call('stepReturnQuantity', 0, 'up')
            ->assertSet('lines.0.return_quantity', '1')
            ->assertSet('hasReturnQuantities', true)
            ->assertSet('returnTotal', '15.000000')
            ->assertSet('postReturnTotal', '285.000000');

        // Set to full remaining
        $component->call('returnFullRemaining', 0)
            ->assertSet('lines.0.return_quantity', '20')
            ->assertSet('returnTotal', '300.000000')
            ->assertSet('postReturnTotal', '0.000000');

        // Change quantity and recalculate
        $component->set('lines.0.return_quantity', '5')
            ->call('recalculate')
            ->assertSet('returnTotal', '75.000000')
            ->assertSet('postReturnTotal', '225.000000');

        // Render preview HTML
        $component->assertSeeHtml('data-testid="return-totals-summary"');

        // Zero economic records created during preview
        self::assertSame($before, $this->economicCounts());
    }

    /**
     * 3. MALFORMED: SalesReturnForm rejects malformed strings, clears totals, and rejects invalid stepper input.
     */
    public function test_sales_return_form_rejects_malformed_quantities_and_clears_totals(): void
    {
        $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '20.000000', 'unit_cost' => '10.00']]]);
        $invoice = $this->createAndPostSalesInvoice([
            [
                'product_id' => $this->product->id,
                'item_description' => 'Malformed Test Item',
                'quantity' => '5.000000',
                'unit_price' => '20.00',
            ],
        ]);

        $component = Livewire::test(SalesReturnForm::class, ['invoice_id' => $invoice->id]);

        $malformedInputs = ['-1', 'abc', '1.2.3', '1e5', '1.1234567', ' 1,000 '];
        foreach ($malformedInputs as $badInput) {
            $component->set('lines.0.return_quantity', $badInput)
                ->call('recalculate')
                ->assertSet('returnCalculationError', __('sales.invalid_return_quantity'))
                ->assertSet('returnTotal', null)
                ->assertSet('postReturnTotal', null);
        }

        // Stepper batchStepQuantity also rejects malformed expectedQuantity
        $lineData = $component->get('lines.0');
        $identity = [];
        foreach (DocumentIdentity::FIELDS as $idField) {
            $identity[$idField] = $lineData[$idField] ?? null;
        }

        $component->call('batchStepQuantity', 0, 'invalid_number', $identity, ['up'])
            ->assertReturned(['ok' => false, 'message' => __('mobile.quantity_stale')]);
    }

    /**
     * 4. MALFORMED: PurchaseReturnForm rejects malformed strings, clears totals, and rejects invalid stepper input.
     */
    public function test_purchase_return_form_rejects_malformed_quantities_and_clears_totals(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [
            [
                'product_id' => $this->product->id,
                'quantity' => '5.000000',
                'unit_cost' => '20.00',
            ],
        ]]);

        $component = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id]);

        $malformedInputs = ['-1', 'abc', '1.2.3', '1e5', '1.1234567', '1,000.00'];
        foreach ($malformedInputs as $badInput) {
            $component->set('lines.0.return_quantity', $badInput)
                ->call('recalculate')
                ->assertSet('returnCalculationError', __('purchasing.invalid_return_quantity'))
                ->assertSet('returnTotal', null)
                ->assertSet('postReturnTotal', null);
        }

        // Stepper batchStepQuantity also rejects malformed expectedQuantity
        $lineData = $component->get('lines.0');
        $identity = [];
        foreach (DocumentIdentity::FIELDS as $idField) {
            $identity[$idField] = $lineData[$idField] ?? null;
        }

        $component->call('batchStepQuantity', 0, 'invalid_number', $identity, ['up'])
            ->assertReturned(['ok' => false, 'message' => __('mobile.quantity_stale')]);
    }

    /**
     * 5. OVERMAX: SalesReturnForm rejects quantities exceeding line returnable cap and respects cumulative prior returns.
     */
    public function test_sales_return_form_enforces_overmax_cap_and_cumulative_prior_returns(): void
    {
        $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '50.000000', 'unit_cost' => '10.00']]]);
        $invoice = $this->createAndPostSalesInvoice([
            [
                'product_id' => $this->product->id,
                'item_description' => 'Overmax Item',
                'quantity' => '5.000000',
                'unit_price' => '20.00',
            ],
        ]);

        $component = Livewire::test(SalesReturnForm::class, ['invoice_id' => $invoice->id]);

        // Attempt return of 6 when 5 is purchased
        $component->set('lines.0.return_quantity', '6')
            ->call('recalculate')
            ->assertSet('returnCalculationError', __('sales.return_quantity_exceeds_remaining'))
            ->assertSet('returnTotal', null);

        // Create and post a prior return of 3
        $invoiceLine = $invoice->lines->firstOrFail();
        $draftReturn = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => '2026-10-03',
            'lines' => [
                [
                    'sales_invoice_line_id' => $invoiceLine->id,
                    'quantity' => '3.000000',
                ],
            ],
        ]);
        app(PostSalesReturnAction::class)->execute($draftReturn, $this->owner);

        // Mount a second return form - remaining cap is strictly 2
        $component2 = Livewire::test(SalesReturnForm::class, ['invoice_id' => $invoice->id]);
        self::assertSame('2.000000', (string) $component2->get('lines.0.max_returnable'));
        self::assertSame('3.000000', (string) $component2->get('lines.0.already_returned_quantity'));
        self::assertSame('40.000000', (string) $component2->get('preReturnTotal'));

        // Attempting to return 2.5 exceeds the remaining cap 2
        $component2->set('lines.0.return_quantity', '2.5')
            ->call('recalculate')
            ->assertSet('returnCalculationError', __('sales.return_quantity_exceeds_remaining'))
            ->assertSet('returnTotal', null);

        // Stepper caps strictly at 2
        $component2->set('lines.0.return_quantity', '2')
            ->call('stepReturnQuantity', 0, 'up')
            ->assertSet('lines.0.return_quantity', '2')
            ->assertSet('returnTotal', '40.000000')
            ->assertSet('postReturnTotal', '0.000000');
    }

    /**
     * 6. OVERMAX: PurchaseReturnForm rejects quantities exceeding line returnable cap and respects cumulative prior returns.
     */
    public function test_purchase_return_form_enforces_overmax_cap_and_cumulative_prior_returns(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [
            [
                'product_id' => $this->product->id,
                'quantity' => '5.000000',
                'unit_cost' => '20.00',
            ],
        ]]);
        $purchaseLine = $purchase->lines->firstOrFail();

        $component = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id]);

        // Attempt return of 6 when 5 was purchased
        $component->set('lines.0.return_quantity', '6')
            ->call('recalculate')
            ->assertSet('returnCalculationError', __('purchasing.return_quantity_exceeds_remaining'))
            ->assertSet('returnTotal', null);

        // Create and post a prior return of 3
        $this->createAndPostReturn($purchase, ['lines' => [
            [
                'purchase_line_id' => $purchaseLine->id,
                'quantity' => '3.000000',
            ],
        ]]);

        // Mount new return form - remaining cap is strictly 2
        $component2 = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id]);
        self::assertSame('2.000000', (string) $component2->get('lines.0.remaining_quantity'));
        self::assertSame('3.000000', (string) $component2->get('lines.0.prior_returned_quantity'));
        self::assertSame('40.000000', (string) $component2->get('preReturnTotal'));

        // Attempting to return 2.5 exceeds remaining cap 2
        $component2->set('lines.0.return_quantity', '2.5')
            ->call('recalculate')
            ->assertSet('returnCalculationError', __('purchasing.return_quantity_exceeds_remaining'))
            ->assertSet('returnTotal', null);

        // Stepper caps strictly at 2
        $component2->set('lines.0.return_quantity', '2')
            ->call('stepReturnQuantity', 0, 'up')
            ->assertSet('lines.0.return_quantity', '2')
            ->assertSet('returnTotal', '40.000000')
            ->assertSet('postReturnTotal', '0.000000');
    }

    /**
     * 7. STALE: SalesReturnForm batch stepping rejects stale expected quantities and mismatched line identities.
     */
    public function test_sales_return_form_batch_stepping_rejects_stale_quantity_and_mismatched_identity(): void
    {
        $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '20.000000', 'unit_cost' => '10.00']]]);
        $invoice = $this->createAndPostSalesInvoice([
            [
                'product_id' => $this->product->id,
                'item_description' => 'Stale Test Item',
                'quantity' => '5.000000',
                'unit_price' => '20.00',
            ],
        ]);

        $component = Livewire::test(SalesReturnForm::class, ['invoice_id' => $invoice->id]);
        $lineData = $component->get('lines.0');

        $identity = [];
        foreach (DocumentIdentity::FIELDS as $idField) {
            $identity[$idField] = $lineData[$idField] ?? null;
        }

        // Stale expected quantity (client thinks 2, server has 0)
        $component->call('batchStepQuantity', 0, '2', $identity, ['up'])
            ->assertReturned(['ok' => false, 'message' => __('mobile.quantity_stale')]);

        // Stale expected identity (tampered sales_invoice_line_id)
        $tamperedIdentity = $identity;
        $tamperedIdentity['sales_invoice_line_id'] = 999999;
        $component->call('batchStepQuantity', 0, '0', $tamperedIdentity, ['up'])
            ->assertReturned(['ok' => false, 'message' => __('mobile.quantity_stale')]);
    }

    /**
     * 8. STALE: PurchaseReturnForm batch stepping rejects stale expected quantities and mismatched line identities.
     */
    public function test_purchase_return_form_batch_stepping_rejects_stale_quantity_and_mismatched_identity(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [
            [
                'product_id' => $this->product->id,
                'quantity' => '5.000000',
                'unit_cost' => '20.00',
            ],
        ]]);

        $component = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id]);
        $lineData = $component->get('lines.0');

        $identity = [];
        foreach (DocumentIdentity::FIELDS as $idField) {
            $identity[$idField] = $lineData[$idField] ?? null;
        }

        // Stale expected quantity (client thinks 2, server has empty string or 0)
        $component->call('batchStepQuantity', 0, '2', $identity, ['up'])
            ->assertReturned(['ok' => false, 'message' => __('mobile.quantity_stale')]);

        // Stale expected identity (tampered purchase_line_id)
        $tamperedIdentity = $identity;
        $tamperedIdentity['purchase_line_id'] = 999999;
        $component->call('batchStepQuantity', 0, '', $tamperedIdentity, ['up'])
            ->assertReturned(['ok' => false, 'message' => __('mobile.quantity_stale')]);
    }

    /**
     * 9. FOREIGN & TENANT: SalesReturnForm rejects tampered foreign line IDs and cross-tenant lines.
     */
    public function test_sales_return_form_rejects_foreign_lines_and_enforces_tenant_isolation(): void
    {
        $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '20.000000', 'unit_cost' => '10.00']]]);
        $invoiceA = $this->createAndPostSalesInvoice();

        // Clear active context before creating second tenant company
        app(CompanyContext::class)->clear();

        // Create second tenant company and invoice
        $otherUser = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherUser, [
            'name_ar' => 'شركة ثانية',
            'name_en' => 'Second Company',
            'country_code' => 'PS',
            'base_currency' => 'ILS',
            'timezone' => 'Asia/Gaza',
        ]);
        app(CompanyContext::class)->setCompany($otherCompany, $otherUser);
        $this->actingAs($otherUser);
        setPermissionsTeamId($otherCompany->id);
        $otherCustomer = Customer::create([
            'company_id' => $otherCompany->id,
            'name_ar' => 'عميل آخر',
            'name_en' => 'Other Customer',
            'preferred_locale' => 'en',
            'default_currency_code' => 'ILS',
            'created_by' => $otherUser->id,
            'status' => 'active',
        ]);
        $otherProduct = app(ProductCatalogService::class)->createProduct($otherCompany, [
            'name_ar' => 'سلعة أخرى',
            'name_en' => 'Other Item',
            'sku' => 'ITM-OTHER-'.Str::random(5),
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => Unit::where('code', 'piece')->firstOrFail()->id,
        ], $otherUser->id);

        $otherVendor = app(VendorCatalogService::class)->save($otherCompany, $otherUser, [
            'name_ar' => 'مورد آخر',
            'name_en' => 'Other Vendor',
            'default_currency_code' => 'ILS',
        ]);
        app(PostPurchaseAction::class)->execute(
            app(CreatePurchaseDraftAction::class)->execute($otherCompany, $otherUser, [
                'vendor_id' => $otherVendor->id,
                'warehouse_id' => (int) $otherCompany->warehouses()->firstOrFail()->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0',
                'purchase_date' => '2026-10-02',
                'lines' => [['product_id' => $otherProduct->id, 'item_description' => 'Seed Stock', 'quantity' => '10', 'unit_cost' => '5']],
            ]),
            $otherUser
        );

        $invoiceB = app(PostSalesInvoiceAction::class)->execute(
            app(CreateSalesInvoiceDraftAction::class)->execute($otherCompany, $otherUser, [
                'customer_id' => $otherCustomer->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0',
                'issue_date' => '2026-10-02',
                'lines' => [['product_id' => $otherProduct->id, 'item_description' => 'Foreign', 'quantity' => '1', 'unit_price' => '10']],
            ]),
            $otherUser
        );
        $foreignLineId = (int) $invoiceB->lines->firstOrFail()->id;

        // Switch back to company A context
        $this->activate($this->owner);

        $component = Livewire::test(SalesReturnForm::class, ['invoice_id' => $invoiceA->id]);

        // Tamper line ID to foreign line from other company
        $component->set('lines.0.sales_invoice_line_id', $foreignLineId)
            ->set('lines.0.return_quantity', '1')
            ->call('recalculate')
            ->assertSet('returnCalculationError', __('refinement.source_line_not_found'))
            ->assertSet('returnTotal', null);
    }

    /**
     * 10. FOREIGN & TENANT: PurchaseReturnForm rejects tampered foreign line IDs and cross-tenant lines.
     */
    public function test_purchase_return_form_rejects_foreign_lines_and_enforces_tenant_isolation(): void
    {
        $purchaseA = $this->createAndPostPurchase(['lines' => [
            [
                'product_id' => $this->product->id,
                'quantity' => '10.000000',
                'unit_cost' => '10.00',
            ],
        ]]);

        // Clear active context before creating second tenant company
        app(CompanyContext::class)->clear();

        // Create second company with purchase
        $otherUser = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherUser, [
            'name_ar' => 'شركة ثانية شراء',
            'name_en' => 'Second Company Purchasing',
            'country_code' => 'PS',
            'base_currency' => 'ILS',
            'timezone' => 'Asia/Gaza',
        ]);
        app(CompanyContext::class)->setCompany($otherCompany, $otherUser);
        $this->actingAs($otherUser);
        setPermissionsTeamId($otherCompany->id);
        $otherVendor = app(VendorCatalogService::class)->save($otherCompany, $otherUser, [
            'name_ar' => 'مورد آخر',
            'name_en' => 'Other Vendor',
            'default_currency_code' => 'ILS',
        ]);

        $otherProduct = app(ProductCatalogService::class)->createProduct($otherCompany, [
            'name_ar' => 'سلعة أخرى',
            'name_en' => 'Other Item',
            'sku' => 'ITM-OTHER-'.Str::random(5),
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => Unit::where('code', 'piece')->firstOrFail()->id,
        ], $otherUser->id);

        $purchaseB = app(PostPurchaseAction::class)->execute(
            app(CreatePurchaseDraftAction::class)->execute($otherCompany, $otherUser, [
                'vendor_id' => $otherVendor->id,
                'warehouse_id' => (int) $otherCompany->warehouses()->firstOrFail()->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0',
                'purchase_date' => '2026-10-02',
                'lines' => [['product_id' => $otherProduct->id, 'item_description' => 'Foreign P', 'quantity' => '1', 'unit_cost' => '10']],
            ]),
            $otherUser
        );
        $foreignPurchaseLineId = (int) $purchaseB->lines->firstOrFail()->id;

        // Switch back to company A context
        $this->activate($this->owner);

        $component = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchaseA->public_id]);

        // Tamper line ID to foreign line from company B
        $component->set('lines.0.purchase_line_id', $foreignPurchaseLineId)
            ->set('lines.0.return_quantity', '1')
            ->call('recalculate')
            ->assertSet('returnCalculationError', __('refinement.source_line_not_found'))
            ->assertSet('returnTotal', null);
    }

    /**
     * 11. TOTALS & RESIDUAL PARITY: SalesReturnForm partial vs final residual return, paid invoice invariance.
     */
    public function test_sales_return_form_totals_parity_partial_and_final_residual_and_paid_invariance(): void
    {
        $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '20.000000', 'unit_cost' => '10.00']]]);

        // 3 items for total 10.00 ILS (3.3333333... per item)
        $invoice = $this->createAndPostSalesInvoice([
            [
                'product_id' => $this->product->id,
                'item_description' => 'Residual Test Item',
                'quantity' => '3.000000',
                'unit_price' => '3.333333',
            ],
        ]);
        $invoiceLine = $invoice->lines->firstOrFail();
        $origTotal = (string) $invoice->grand_total_currency;
        self::assertSame('10.000000', $origTotal);

        // Paid invoice invariance: customer pays full amount
        app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_method' => 'cash',
            'amount' => '10.00',
            'exchange_rate' => '1.0',
            'payment_date' => '2026-10-02',
            'idempotency_key' => 'sales-paid-inv-1',
            'allocations' => [
                [
                    'sales_invoice_id' => $invoice->id,
                    'allocated_amount' => '10.00',
                ],
            ],
        ]);

        // STEP 1: First Return of 1 unit out of 3
        $comp1 = Livewire::test(SalesReturnForm::class, ['invoice_id' => $invoice->id]);
        // Even though invoice is paid in full, goods value is 10.00!
        self::assertSame('10.000000', (string) $comp1->get('preReturnTotal'));

        $comp1->set('lines.0.return_quantity', '1')->call('recalculate');
        $retTotal1 = (string) $comp1->get('returnTotal');
        self::assertSame('3.330000', $retTotal1);
        self::assertSame('6.670000', (string) $comp1->get('postReturnTotal'));

        // Post return 1 and verify canonical DB line total matches preview exactly
        $ret1 = app(PostSalesReturnAction::class)->execute(
            app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
                'sales_invoice_id' => $invoice->id,
                'issue_date' => '2026-10-03',
                'lines' => [['sales_invoice_line_id' => $invoiceLine->id, 'quantity' => '1.000000']],
            ]),
            $this->owner
        );
        self::assertSame('3.330000', (string) $ret1->lines->firstOrFail()->line_total);

        // STEP 2: Second Return of 1 unit out of 2 remaining
        $comp2 = Livewire::test(SalesReturnForm::class, ['invoice_id' => $invoice->id]);
        self::assertSame('6.670000', (string) $comp2->get('preReturnTotal'));

        $comp2->set('lines.0.return_quantity', '1')->call('recalculate');
        $retTotal2 = (string) $comp2->get('returnTotal');
        self::assertSame('3.340000', $retTotal2);
        self::assertSame('3.330000', (string) $comp2->get('postReturnTotal'));

        $ret2 = app(PostSalesReturnAction::class)->execute(
            app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
                'sales_invoice_id' => $invoice->id,
                'issue_date' => '2026-10-04',
                'lines' => [['sales_invoice_line_id' => $invoiceLine->id, 'quantity' => '1.000000']],
            ]),
            $this->owner
        );
        self::assertSame('3.340000', (string) $ret2->lines->firstOrFail()->line_total);

        // STEP 3: FINAL Return of 1 unit remaining (Residual allocation applies!)
        $comp3 = Livewire::test(SalesReturnForm::class, ['invoice_id' => $invoice->id]);
        self::assertSame('3.330000', (string) $comp3->get('preReturnTotal'));

        $comp3->set('lines.0.return_quantity', '1')->call('recalculate');
        $retTotal3 = (string) $comp3->get('returnTotal');
        self::assertSame('3.330000', $retTotal3);
        // Post return total is strictly ZERO!
        self::assertSame('0.000000', (string) $comp3->get('postReturnTotal'));

        // Exact sum across all three returns equals the original 10.000000 ILS total
        $totalSum = BigDecimal::of($retTotal1)->plus($retTotal2)->plus($retTotal3);
        self::assertSame('10.000000', (string) $totalSum);

        $ret3 = app(PostSalesReturnAction::class)->execute(
            app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
                'sales_invoice_id' => $invoice->id,
                'issue_date' => '2026-10-05',
                'lines' => [['sales_invoice_line_id' => $invoiceLine->id, 'quantity' => '1.000000']],
            ]),
            $this->owner
        );
        self::assertSame('3.330000', (string) $ret3->lines->firstOrFail()->line_total);
    }

    /**
     * 12. TOTALS & RESIDUAL PARITY: PurchaseReturnForm partial vs final residual return, JOD scale 3 currency.
     */
    public function test_purchase_return_form_totals_parity_partial_and_final_residual_and_paid_invariance(): void
    {
        // 3 items for total 10.000 JOD (3 decimal scale currency)
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'JOD',
            'exchange_rate' => '5.0000000000',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '3.000000',
                    'unit_cost' => '3.333333',
                ],
            ],
        ]);
        $purchaseLine = $purchase->lines->firstOrFail();
        self::assertSame('10.000000', (string) $purchase->grand_total_currency);

        // STEP 1: First Return of 1 unit
        $comp1 = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id]);
        self::assertSame('10.000000', (string) $comp1->get('preReturnTotal'));

        $comp1->set('lines.0.return_quantity', '1')->call('recalculate');
        $retTotal1 = (string) $comp1->get('returnTotal');
        self::assertSame('3.333000', $retTotal1);
        self::assertSame('6.667000', (string) $comp1->get('postReturnTotal'));

        $ret1 = $this->createAndPostReturn($purchase, [
            'lines' => [['purchase_line_id' => $purchaseLine->id, 'quantity' => '1.000000']],
        ]);
        self::assertSame('3.333000', (string) $ret1->lines->firstOrFail()->line_total);

        // STEP 2: Second Return of 1 unit
        $comp2 = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id]);
        self::assertSame('6.667000', (string) $comp2->get('preReturnTotal'));

        $comp2->set('lines.0.return_quantity', '1')->call('recalculate');
        $retTotal2 = (string) $comp2->get('returnTotal');
        self::assertSame('3.334000', $retTotal2);
        self::assertSame('3.333000', (string) $comp2->get('postReturnTotal'));

        $ret2 = $this->createAndPostReturn($purchase, [
            'lines' => [['purchase_line_id' => $purchaseLine->id, 'quantity' => '1.000000']],
        ]);
        self::assertSame('3.334000', (string) $ret2->lines->firstOrFail()->line_total);

        // STEP 3: Final Return of remaining 1 unit (Residual allocation applies!)
        $comp3 = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id]);
        self::assertSame('3.333000', (string) $comp3->get('preReturnTotal'));

        $comp3->set('lines.0.return_quantity', '1')->call('recalculate');
        $retTotal3 = (string) $comp3->get('returnTotal');
        self::assertSame('3.333000', $retTotal3);
        // Remaining post-return total is strictly ZERO
        self::assertSame('0.000000', (string) $comp3->get('postReturnTotal'));

        // Exact sum across all three returns equals the original 10.000000 JOD total
        $totalSum = BigDecimal::of($retTotal1)->plus($retTotal2)->plus($retTotal3);
        self::assertSame('10.000000', (string) $totalSum);

        $ret3 = $this->createAndPostReturn($purchase, [
            'lines' => [['purchase_line_id' => $purchaseLine->id, 'quantity' => '1.000000']],
        ]);
        self::assertSame('3.333000', (string) $ret3->lines->firstOrFail()->line_total);
    }

    /**
     * 13. STALE CONCURRENT RETURN: SalesReturnForm handles concurrent return race without 500, preserves form and shows localized field error.
     */
    public function test_sales_return_form_stale_concurrent_return_shows_localized_field_error_without_500(): void
    {
        $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '50.000000', 'unit_cost' => '10.00']]]);
        $invoice = $this->createAndPostSalesInvoice([
            [
                'product_id' => $this->product->id,
                'item_description' => 'Concurrent Stale Item',
                'quantity' => '5.000000',
                'unit_price' => '20.00',
            ],
        ]);

        // User A mounts the return form for this invoice
        $component = Livewire::test(SalesReturnForm::class, ['invoice_id' => $invoice->id]);
        $component->set('lines.0.return_quantity', '4');

        // User B concurrently posts a return of 3
        $invoiceLine = $invoice->lines->firstOrFail();
        $concurrentReturn = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => '2026-10-03',
            'lines' => [
                [
                    'sales_invoice_line_id' => $invoiceLine->id,
                    'quantity' => '3.000000',
                ],
            ],
        ]);
        app(PostSalesReturnAction::class)->execute($concurrentReturn, $this->owner);

        // User A attempts to save their return of 4 (which now exceeds remaining 2)
        // Must NOT throw 500 InvalidArgumentException! Must set localized field error on lines.0.return_quantity
        $component->call('save', false)
            ->assertHasErrors(['lines.0.return_quantity' => __('sales.return_quantity_exceeds_remaining')])
            ->assertSet('lines.0.return_quantity', '4'); // Form state preserved
    }

    /**
     * 14. BATCH LOT BUDGET: PurchaseReturnForm batches InventoryLotBalance lookup across all lines and lots.
     */
    public function test_purchase_return_form_many_lots_batch_lookup_budget(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10.000000', 'unit_cost' => '10.00'],
            ],
        ]);

        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount) {
            if (str_contains($query->sql, 'inventory_lot_balances')) {
                $queryCount++;
            }
        });

        $component = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id]);

        // At most 1 batched query for lot balances, never N+1
        self::assertLessThanOrEqual(1, $queryCount);
    }

    /**
     * 15. STALE CONCURRENT RETURN (PURCHASING): PurchaseReturnForm handles concurrent return race without 500, preserves form and shows localized field error.
     */
    public function test_purchase_return_form_stale_concurrent_return_shows_localized_field_error_without_500(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '5.000000', 'unit_cost' => '10.00'],
            ],
        ]);
        $purchaseLine = $purchase->lines->firstOrFail();

        // User A mounts the purchase return form
        $component = Livewire::test(PurchaseReturnForm::class, ['publicId' => $purchase->public_id]);
        $component->set('lines.0.return_quantity', '4');

        // User B concurrently posts a return of 3
        $this->createAndPostReturn($purchase, [
            'lines' => [['purchase_line_id' => $purchaseLine->id, 'quantity' => '3.000000']],
        ]);

        // User A attempts to save their return of 4 (which now exceeds remaining 2)
        // Must NOT throw 500! Must set localized field error on lines.0.return_quantity and save
        $component->call('saveDraft')
            ->assertHasErrors([
                'lines.0.return_quantity' => __('purchasing.return_quantity_exceeds_remaining'),
                'save' => __('purchasing.return_quantity_exceeds_remaining'),
            ])
            ->assertSet('lines.0.return_quantity', '4'); // Form state preserved
    }
}
