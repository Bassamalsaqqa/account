<?php

declare(strict_types=1);

namespace Tests\Feature\Refinement;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Livewire\Pages\Customers\CustomerIndex;
use App\Livewire\Pages\Products\ProductIndex;
use App\Livewire\Pages\Purchasing\PaymentDetail as PurchasingPaymentDetail;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Purchasing\PurchaseIndex;
use App\Livewire\Pages\Purchasing\PurchaseReturnIndex;
use App\Livewire\Pages\Purchasing\VendorIndex;
use App\Livewire\Pages\Sales\PaymentDetail as SalesPaymentDetail;
use App\Livewire\Pages\Sales\ReturnForm;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\User;
use App\Models\VendorPayment;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Phase6\Phase6TestCase;

class MerchantUxBoundedGapsRegressionTest extends Phase6TestCase
{
    public function test_sales_return_form_renders_one_interactive_stepper_tree_and_line_cap_errors_in_ar_and_en(): void
    {
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل المرتجع',
            'name_en' => 'Return Customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-01',
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'Service Line',
                    'quantity' => '3',
                    'unit_price' => '25.00',
                ],
            ],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->owner->update(['locale' => $locale]);
            $this->activate($this->owner->fresh());

            $component = Livewire::test(ReturnForm::class, ['invoice_id' => $invoice->id])
                ->assertStatus(200);

            $initialHtml = $component->html(false);
            $this->assertSame(1, substr_count($initialHtml, 'id="sales-return-quantity-0"'));
            $this->assertStringContainsString('for="sales-return-quantity-0"', $initialHtml);
            $this->assertStringContainsString('for="sales-return-date"', $initialHtml);
            $this->assertStringContainsString('id="sales-return-date"', $initialHtml);
            $this->assertStringContainsString('for="sales-return-reason"', $initialHtml);
            $this->assertStringContainsString('id="sales-return-reason"', $initialHtml);
            $this->assertStringContainsString('for="sales-return-notes"', $initialHtml);
            $this->assertStringContainsString('id="sales-return-notes"', $initialHtml);

            $component->call('returnFullRemaining', 0)
                ->assertSet('lines.0.return_quantity', '3')
                ->assertHasNoErrors();

            $component->set('lines.0.return_quantity', '5')
                ->assertHasErrors(['lines.0.return_quantity']);

            $errorHtml = $component->html(false);
            $this->assertStringContainsString('id="sales-return-quantity-0-error"', $errorHtml);
            $this->assertStringContainsString('aria-invalid="true"', $errorHtml);
            $this->assertStringContainsString('aria-describedby="sales-return-quantity-0-error"', $errorHtml);
            $this->assertNotNull($component->get('returnCalculationError'));

            $component->call('save', true)
                ->assertHasErrors(['lines.0.return_quantity']);
            $this->assertSame(0, SalesReturn::where('company_id', $this->company->id)->count());
        }

        // Valid save still creates the canonical sales return cleanly.
        Livewire::test(ReturnForm::class, ['invoice_id' => $invoice->id])
            ->set('lines.0.return_quantity', '2')
            ->assertHasNoErrors()
            ->call('save', true)
            ->assertHasNoErrors();

        $this->assertSame(1, SalesReturn::where('company_id', $this->company->id)->count());
        /** @var SalesReturn $savedReturn */
        $savedReturn = SalesReturn::where('company_id', $this->company->id)->with('lines')->firstOrFail();
        $this->assertSame('posted', $savedReturn->status);
        $this->assertSame('2.000000', (string) $savedReturn->lines->firstOrFail()->quantity);
        /** @var SalesInvoice $reloaded */
        $reloaded = $invoice->fresh();
        $this->assertSame('posted', $reloaded->status);
    }

    public function test_vendor_product_and_customer_indexes_enforce_parity_gates_active_checks_and_party_context(): void
    {
        $inactiveVendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد متوقف',
            'name_en' => 'Inactive Vendor',
            'default_currency_code' => 'ILS',
            'active' => false,
        ]);

        $ownerVendors = Livewire::test(VendorIndex::class)->assertStatus(200)->html(false);
        ['desktop' => $vendorDesktop, 'mobile' => $vendorMobile] = $this->extractDesktopAndMobileRowActionHrefs($ownerVendors);
        $this->assertCount(2, $vendorDesktop);
        $this->assertSame($vendorDesktop, $vendorMobile);

        $allVendorDesktopHrefs = array_merge(...$vendorDesktop);
        $this->assertContains(route('vendors.show', $this->vendor->public_id), $allVendorDesktopHrefs);
        $this->assertContains(route('purchases.create', ['vendor_id' => $this->vendor->id]), $allVendorDesktopHrefs);
        $this->assertContains(route('vendor-payments.create', ['vendor_id' => $this->vendor->id]), $allVendorDesktopHrefs);
        $this->assertContains(route('vendors.edit', $this->vendor->public_id), $allVendorDesktopHrefs);
        $this->assertContains(route('vendors.show', $inactiveVendor->public_id), $allVendorDesktopHrefs);
        $this->assertNotContains(route('purchases.create', ['vendor_id' => $inactiveVendor->id]), $allVendorDesktopHrefs);
        $this->assertNotContains(route('vendor-payments.create', ['vendor_id' => $inactiveVendor->id]), $allVendorDesktopHrefs);

        // Missing purchasing.cost.view suppresses both purchases.create and vendor-payments.create even if create perm exists.
        $noCostActor = $this->customActor(['vendors.view', 'purchasing.purchase.create', 'money.vendor_payment.create']);
        $this->activate($noCostActor);
        $noCostHtml = Livewire::test(VendorIndex::class)->assertStatus(200)->html(false);
        $this->assertStringNotContainsString(route('purchases.create', ['vendor_id' => $this->vendor->id]), $noCostHtml);
        $this->assertStringNotContainsString(route('vendor-payments.create', ['vendor_id' => $this->vendor->id]), $noCostHtml);
        $this->assertStringNotContainsString(route('vendors.edit', $this->vendor->public_id), $noCostHtml);

        $this->activate($this->owner);
        $inactiveProduct = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج متوقف',
            'name_en' => 'Inactive Product',
            'sku' => 'ITM-INACT',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'active' => false,
            'base_unit_id' => $this->product->base_unit_id,
        ], $this->owner->id);

        $ownerProducts = Livewire::test(ProductIndex::class)->assertStatus(200)->html(false);
        ['desktop' => $productDesktop, 'mobile' => $productMobile] = $this->extractDesktopAndMobileRowActionHrefs($ownerProducts);
        $this->assertCount(2, $productDesktop);
        $this->assertSame($productDesktop, $productMobile);

        $allProductDesktopHrefs = array_merge(...$productDesktop);
        $this->assertContains(route('products.show', $this->product->public_id), $allProductDesktopHrefs);
        $this->assertContains(route('labels.index', ['search' => 'ITM-01']), $allProductDesktopHrefs);
        $this->assertContains(route('products.edit', $this->product->public_id), $allProductDesktopHrefs);
        $this->assertContains(route('products.show', $inactiveProduct->public_id), $allProductDesktopHrefs);
        $this->assertNotContains(route('labels.index', ['search' => 'ITM-INACT']), $allProductDesktopHrefs);

        // Missing barcode_labels.print suppresses barcode label links even with stock.view.
        $noBarcodePrintActor = $this->customActor(['inventory.stock.view']);
        $this->activate($noBarcodePrintActor);
        $noBarcodeHtml = Livewire::test(ProductIndex::class)->assertStatus(200)->html(false);
        $this->assertStringNotContainsString(route('labels.index', ['search' => 'ITM-01']), $noBarcodeHtml);
        $this->assertStringContainsString(route('products.show', $this->product->public_id), $noBarcodeHtml);

        $this->activate($this->owner);
        $activeCustomer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل نشط',
            'name_en' => 'Active Customer',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);
        $inactiveCustomer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل موقوف',
            'name_en' => 'Inactive Customer',
            'status' => 'inactive',
            'created_by' => $this->owner->id,
        ]);

        $ownerCustomers = Livewire::test(CustomerIndex::class)->assertStatus(200)->html(false);
        ['desktop' => $customerDesktop, 'mobile' => $customerMobile] = $this->extractDesktopAndMobileRowActionHrefs($ownerCustomers);
        $this->assertCount(2, $customerDesktop);
        $this->assertSame($customerDesktop, $customerMobile);

        $allCustomerDesktopHrefs = array_merge(...$customerDesktop);
        $this->assertContains(route('customers.show', $activeCustomer->public_id), $allCustomerDesktopHrefs);
        $this->assertContains(route('invoices.create', ['customer_id' => $activeCustomer->id]), $allCustomerDesktopHrefs);
        $this->assertContains(route('payments.create', ['customer_id' => $activeCustomer->id]), $allCustomerDesktopHrefs);
        $this->assertContains(route('customers.edit', $activeCustomer->public_id), $allCustomerDesktopHrefs);
        $this->assertContains(route('customers.show', $inactiveCustomer->public_id), $allCustomerDesktopHrefs);
        $this->assertNotContains(route('invoices.create', ['customer_id' => $inactiveCustomer->id]), $allCustomerDesktopHrefs);
        $this->assertNotContains(route('payments.create', ['customer_id' => $inactiveCustomer->id]), $allCustomerDesktopHrefs);

        $viewOnlyCustomerActor = $this->customActor(['customers.view']);
        $this->activate($viewOnlyCustomerActor);
        $viewOnlyCustomerHtml = Livewire::test(CustomerIndex::class)->assertStatus(200)->html(false);
        $this->assertStringNotContainsString(route('invoices.create', ['customer_id' => $activeCustomer->id]), $viewOnlyCustomerHtml);
        $this->assertStringNotContainsString(route('payments.create', ['customer_id' => $activeCustomer->id]), $viewOnlyCustomerHtml);
        $this->assertStringNotContainsString(route('customers.edit', $activeCustomer->public_id), $viewOnlyCustomerHtml);
    }

    public function test_purchase_and_purchase_return_vendor_links_and_purchase_form_vendor_id_initialization(): void
    {
        $purchase = $this->createAndPostPurchase();
        $this->createAndPostReturn($purchase);

        $purchaseHtml = Livewire::test(PurchaseIndex::class)->assertStatus(200)->html(false);
        $this->assertSame(2, substr_count($purchaseHtml, 'href="'.e(route('vendors.show', $this->vendor->public_id)).'"'));

        $returnHtml = Livewire::test(PurchaseReturnIndex::class)->assertStatus(200)->html(false);
        $this->assertSame(2, substr_count($returnHtml, 'href="'.e(route('vendors.show', $this->vendor->public_id)).'"'));

        $withoutVendorView = $this->customActor(['purchasing.purchase.view', 'purchasing.cost.view', 'purchasing.return.view']);
        $this->activate($withoutVendorView);
        $this->assertStringNotContainsString(
            route('vendors.show', $this->vendor->public_id),
            Livewire::test(PurchaseIndex::class)->assertStatus(200)->html(false),
        );
        $this->assertStringNotContainsString(
            route('vendors.show', $this->vendor->public_id),
            Livewire::test(PurchaseReturnIndex::class)->assertStatus(200)->html(false),
        );

        $this->activate($this->owner);
        $usdVendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد دولار',
            'name_en' => 'USD Vendor',
            'default_currency_code' => 'USD',
        ]);
        $inactiveVendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد غير نشط',
            'name_en' => 'Inactive Vendor',
            'default_currency_code' => 'USD',
            'active' => false,
        ]);

        // Foreign company vendor must fail closed and stay unselected.
        $foreignOwner = User::factory()->create();
        app(CompanyContext::class)->clear();
        $foreignCompany = app(CreateCompanyAction::class)->execute($foreignOwner, [
            'name_ar' => 'شركة أخرى',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($foreignCompany, $foreignOwner);
        $this->actingAs($foreignOwner);
        setPermissionsTeamId($foreignCompany->id);
        $foreignVendor = app(VendorCatalogService::class)->save($foreignCompany, $foreignOwner, [
            'name_ar' => 'مورد أجنبي',
            'default_currency_code' => 'ILS',
        ]);

        $this->activate($this->owner);

        Livewire::withQueryParams(['vendor_id' => (string) $usdVendor->id])
            ->test(PurchaseForm::class)
            ->assertStatus(200)
            ->assertSet('vendor_id', $usdVendor->id)
            ->assertSet('currency_code', 'USD');

        Livewire::withQueryParams(['vendor_id' => (string) $inactiveVendor->id])
            ->test(PurchaseForm::class)
            ->assertStatus(200)
            ->assertSet('vendor_id', null);

        Livewire::withQueryParams(['vendor_id' => (string) $foreignVendor->id])
            ->test(PurchaseForm::class)
            ->assertStatus(200)
            ->assertSet('vendor_id', null);
    }

    public function test_payment_detail_fixes_and_eager_loading_query_counts(): void
    {
        app()->setLocale('en');
        $this->owner->update(['locale' => 'en']);
        $this->activate($this->owner->fresh());

        $purchase1 = $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10']]]);
        $purchase2 = $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10']]]);
        $purchase3 = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10']],
        ]);

        $advancePayment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '300.00',
            'exchange_rate' => '1.0000000000',
            'reference_number' => 'REF-VP-884',
            'notes' => 'Vendor advance note 884',
            'idempotency_key' => 'vp-advance-884',
            'allocations' => [],
        ]);

        // Apply once -> 1 applicationEvent.
        app(ApplyVendorPaymentCreditAction::class)->execute($advancePayment, $this->owner, [
            'application_date' => '2026-10-03',
            'idempotency_key' => 'apply-p1',
            'allocations' => [['purchase_id' => $purchase1->id, 'allocated_amount' => '40.00']],
        ]);

        $queriesSingleEvent = $this->captureQueries(function () use ($advancePayment): void {
            Livewire::test(PurchasingPaymentDetail::class, ['publicId' => $advancePayment->public_id])
                ->assertStatus(200);
        });

        // Apply a second time -> 2 distinct applicationEvents, open USD purchase #3 still present.
        app(ApplyVendorPaymentCreditAction::class)->execute($advancePayment->fresh(), $this->owner, [
            'application_date' => '2026-10-04',
            'idempotency_key' => 'apply-p2',
            'allocations' => [['purchase_id' => $purchase2->id, 'allocated_amount' => '60.00']],
        ]);

        $twoEventsComponent = null;
        $queriesTwoEvents = $this->captureQueries(function () use ($advancePayment, &$twoEventsComponent): void {
            $twoEventsComponent = Livewire::test(PurchasingPaymentDetail::class, ['publicId' => $advancePayment->fresh()->public_id])
                ->assertStatus(200);
        });
        $htmlTwoEvents = '';
        $creditQueries = $this->captureQueries(function () use ($twoEventsComponent, &$htmlTwoEvents): void {
            $htmlTwoEvents = $twoEventsComponent->call('openCreditForm')->assertStatus(200)->html(false);
        });
        $refreshQueries = $this->captureQueries(function () use ($twoEventsComponent): void {
            $twoEventsComponent->call('$refresh')->assertStatus(200);
        });
        foreach ([$creditQueries, $refreshQueries] as $subsequentQueries) {
            $this->assertSame(1, $this->countQueriesReferencing($subsequentQueries, '`vendor_payment_application_events`.`id` in'));
            // Settlement-target EXISTS clauses legitimately compare event IDs;
            // count direct belongsTo fetches, not correlated subqueries.
            $lazyEventQueries = array_filter($subsequentQueries, static fn (string $sql): bool => preg_match('/^select .* from `vendor_payment_application_events` where `vendor_payment_application_events`\.`id` = \?/i', $sql) === 1
            );
            $this->assertCount(0, $lazyEventQueries);
        }

        // Header placement & corrected labels/metadata assertions.
        $this->assertStringContainsString(__('purchasing.back'), $htmlTwoEvents);
        $this->assertStringNotContainsString('>'.e(__('purchasing.cancel')).'<', $htmlTwoEvents);
        $this->assertStringContainsString(route('pdf.vendor-payment', ['publicId' => $advancePayment->public_id]), $htmlTwoEvents);
        $this->assertStringContainsString('REF-VP-884', $htmlTwoEvents);
        $this->assertStringContainsString('Vendor advance note 884', $htmlTwoEvents);
        $this->assertStringContainsString($purchase3->purchase_number, $htmlTwoEvents);
        $this->assertStringContainsString('for="vendor-payment-application-date"', $htmlTwoEvents);
        $this->assertStringContainsString('id="vendor-payment-application-date"', $htmlTwoEvents);
        $this->assertStringContainsString('for="vendor-credit-amount-'.$purchase3->id.'"', $htmlTwoEvents);
        $this->assertStringContainsString('id="vendor-credit-amount-'.$purchase3->id.'"', $htmlTwoEvents);
        $this->assertStringContainsString('for="vendor-credit-payment-amount-'.$purchase3->id.'"', $htmlTwoEvents);
        $this->assertStringContainsString('id="vendor-credit-payment-amount-'.$purchase3->id.'"', $htmlTwoEvents);
        $this->assertStringContainsString(__('purchasing.outstanding_balance'), $htmlTwoEvents);
        $this->assertStringNotContainsString(
            '<span class="text-text-muted block text-sm uppercase">'.e(__('purchasing.total')).'</span>',
            $htmlTwoEvents,
        );

        // Eager-load proof for allocations.applicationEvent: 1 batched IN query, 0 per-row lazy queries, 0 dead applicationEvents query, constant across events.
        $this->assertSame(1, $this->countQueriesReferencing($queriesSingleEvent, '`vendor_payment_application_events`.`id` in'));
        $this->assertSame(1, $this->countQueriesReferencing($queriesTwoEvents, '`vendor_payment_application_events`.`id` in'));
        $this->assertSame(0, $this->countQueriesReferencing($queriesTwoEvents, '`vendor_payment_application_events`.`id` ='));
        $this->assertSame(0, $this->countQueriesReferencing($queriesTwoEvents, '`vendor_payment_application_events`.`vendor_payment_id` in'));
        $this->assertSame(
            $this->countQueriesReferencing($queriesSingleEvent, 'vendor_payment_application_events'),
            $this->countQueriesReferencing($queriesTwoEvents, 'vendor_payment_application_events'),
        );

        // Outgoing check payment proves single eager query on checks when rendering VendorPayment check block.
        $outgoingCheck = $this->check('outgoing');
        $checkVendorPayment = VendorPayment::where('check_id', $outgoingCheck->id)->firstOrFail();
        $vendorCheckQueries = $this->captureQueries(function () use ($checkVendorPayment, $outgoingCheck): void {
            $html = Livewire::test(PurchasingPaymentDetail::class, ['publicId' => $checkVendorPayment->public_id])
                ->assertStatus(200)
                ->html(false);
            $this->assertStringContainsString($outgoingCheck->check_number, $html);
        });
        $this->assertSame(1, $this->countQueriesReferencing($vendorCheckQueries, 'from `checks`'));

        // Incoming check payment proves single eager query on checks in Sales\PaymentDetail as well.
        $incomingCheck = $this->check('incoming');
        $customerCheckPayment = CustomerPayment::where('check_id', $incomingCheck->id)->firstOrFail();
        $customerCheckQueries = $this->captureQueries(function () use ($customerCheckPayment, $incomingCheck): void {
            $html = Livewire::test(SalesPaymentDetail::class, ['publicId' => $customerCheckPayment->public_id])
                ->assertStatus(200)
                ->html(false);
            $this->assertStringContainsString($incomingCheck->check_number, $html);
        });
        $this->assertSame(1, $this->countQueriesReferencing($customerCheckQueries, 'from `checks`'));

        // Also ensure Sales\PaymentDetail passes currency formatting cleanly on invoice allocations.
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل الدفعة',
            'name_en' => 'Receipt Customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute(
            app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $customer->id,
                'currency_code' => 'USD',
                'exchange_rate' => '3.5000000000',
                'issue_date' => '2026-10-01',
                'lines' => [['item_description' => 'Item', 'quantity' => '1', 'unit_price' => '100']],
            ]),
            $this->owner,
        );
        $customerPayment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'money_account_id' => $this->usdCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '3.6000000000',
            'idempotency_key' => 'cp-alloc-1',
            'allocations' => [['sales_invoice_id' => $invoice->id, 'allocated_amount' => '100.00']],
        ]);

        Livewire::test(SalesPaymentDetail::class, ['publicId' => $customerPayment->public_id])
            ->assertStatus(200)
            ->assertSee($invoice->invoice_number)
            ->assertSee('100.00')
            ->assertSee('USD');
    }

    /**
     * @return array{desktop: list<list<string>>, mobile: list<list<string>>}
     */
    private function extractDesktopAndMobileRowActionHrefs(string $html): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $extract = static function (string $prefix) use ($xpath): array {
            $groups = [];
            $nodes = $xpath->query($prefix.'//*[contains(concat(" ", normalize-space(@class), " "), " row-action-group ")]');
            if ($nodes === false) {
                return [];
            }
            foreach ($nodes as $group) {
                $hrefs = [];
                $links = $xpath->query('.//a[@href]', $group);
                if ($links !== false) {
                    foreach ($links as $link) {
                        if ($link instanceof \DOMElement) {
                            $hrefs[] = $link->getAttribute('href');
                        }
                    }
                }
                $groups[] = $hrefs;
            }

            return $groups;
        };

        return [
            'desktop' => $extract('//table'),
            'mobile' => $extract('//*[contains(concat(" ", normalize-space(@class), " "), " lg:hidden ")]'),
        ];
    }

    /**
     * @return list<string>
     */
    private function captureQueries(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();
            /** @var list<array{query?: string}> $log */
            $log = DB::getQueryLog();

            return array_values(array_map(
                static fn (array $entry): string => (string) ($entry['query'] ?? ''),
                $log,
            ));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    /**
     * @param  list<string>  $queries
     */
    private function countQueriesReferencing(array $queries, string $needle): int
    {
        $count = 0;
        foreach ($queries as $sql) {
            if (stripos($sql, $needle) !== false) {
                $count++;
            }
        }

        return $count;
    }
}
