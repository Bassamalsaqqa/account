<?php

declare(strict_types=1);

namespace Tests\Feature\Phase10;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateQuotationAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Domain\Sales\Documents\DocumentData;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\PurchasingDocumentBuilder;
use App\Services\Purchasing\PurchasingDocumentRenderer;
use App\Services\Purchasing\VendorCatalogService;
use App\Services\Sales\PdfRendererService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Role;
use Tests\Feature\Phase5E\Phase5ETestCase;

/**
 * P10-A2 focused authenticated financial-document security test suite.
 *
 * Exercises tenant isolation, server-side redactions, pre-rendering authorization bounds,
 * cross-company rejection, and mid-render downgrade/revocation defenses across
 * Purchasing and Sales document endpoints.
 */
class DocumentAuthorizationTest extends Phase5ETestCase
{
    protected function tearDown(): void
    {
        app()->forgetInstance(PurchasingDocumentRenderer::class);
        app()->forgetInstance(PdfRendererService::class);
        parent::tearDown();
    }

    public function test_default_viewer_is_denied_vendor_payment_and_vendor_statement_with_no_document_bytes(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '15.000000'],
            ],
        ]);

        $payment = $this->createVendorPayment($purchase, '150.00');

        $viewer = $this->createViewerUser();
        $this->activate($viewer);

        $formats = [
            ['format' => 'print', 'locale' => 'ar'],
            ['format' => 'print', 'locale' => 'en'],
            ['format' => 'pdf', 'locale' => 'ar'],
            ['format' => 'pdf', 'locale' => 'en'],
        ];

        foreach ($formats as $opt) {
            $query = http_build_query($opt);

            // Vendor Payment delivery must be refused without bytes
            $response = $this->get(route('pdf.vendor-payment', $payment->public_id).'?'.$query);
            $response->assertForbidden();
            $this->assertNoDocumentBytes($response->getContent(), [
                '%PDF-',
                'window.print()',
                $payment->payment_number,
                '150.00',
            ]);

            // Vendor Statement delivery must be refused without bytes
            $responseStmt = $this->get(route('pdf.vendor-statement', $this->vendor->public_id).'?'.$query);
            $responseStmt->assertForbidden();
            $this->assertNoDocumentBytes($responseStmt->getContent(), [
                '%PDF-',
                'window.print()',
                $this->vendor->name_ar,
                $this->vendor->name_en,
            ]);
        }

        // Default Viewer also lacks purchasing.document.pdf for purchase
        $this->get(route('pdf.purchase', $purchase->public_id))->assertForbidden();
    }

    public function test_explicitly_authorized_purchase_and_return_reader_lacking_cost_gets_permitted_server_redacted_projection(): void
    {
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '25.000000'],
            ],
        ]);

        $return = $this->createAndPostReturn($purchase, [
            'lines' => [
                ['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '2'],
            ],
        ]);

        $payment = $this->createVendorPayment($purchase, '50.00');

        // Actor has purchasing.purchase.view and purchasing.document.pdf, but strictly lacks purchasing.cost.view
        $reader = $this->customActor([
            'purchasing.purchase.view',
            'purchasing.document.pdf',
        ], 'WarehouseReceiver');

        $this->activate($reader);

        // 1. Purchase: Print AR & EN server-redacted
        $enPrint = $this->get(route('pdf.purchase', $purchase->public_id).'?format=print&locale=en')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Quantity Only', $enPrint);
        $this->assertStringNotContainsString('Unit Cost', $enPrint);
        $this->assertStringNotContainsString('Grand Total', $enPrint);
        $this->assertStringNotContainsString('25.000000', $enPrint);
        $this->assertStringNotContainsString('125.000000', $enPrint);

        $arPrint = $this->get(route('pdf.purchase', $purchase->public_id).'?format=print&locale=ar')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('كميات فقط', $arPrint);
        $this->assertStringNotContainsString('25.000000', $arPrint);
        $this->assertStringNotContainsString('125.000000', $arPrint);

        // Purchase: PDF AR & EN delivery permitted
        $enPdf = $this->get(route('pdf.purchase', $purchase->public_id).'?locale=en')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $enPdf->getContent());

        $arPdf = $this->get(route('pdf.purchase', $purchase->public_id).'?locale=ar')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $arPdf->getContent());

        // 2. Purchase Return: Print AR & EN server-redacted
        $returnEnPrint = $this->get(route('pdf.purchase-return', $return->public_id).'?format=print&locale=en')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Quantity Only', $returnEnPrint);
        $this->assertStringNotContainsString('50.000000', $returnEnPrint);

        $returnArPrint = $this->get(route('pdf.purchase-return', $return->public_id).'?format=print&locale=ar')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('كميات فقط', $returnArPrint);
        $this->assertStringNotContainsString('50.000000', $returnArPrint);

        // Purchase Return: PDF delivery permitted
        $returnPdf = $this->get(route('pdf.purchase-return', $return->public_id).'?locale=en')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $returnPdf->getContent());

        // 3. Verify DTO structure directly: absence of cost keys
        $builder = app(PurchasingDocumentBuilder::class);
        $dto = $builder->build($purchase, 'en');
        $this->assertFalse($dto['with_cost']);
        $this->assertArrayNotHasKey('grand_total', $dto['document']);
        $this->assertArrayNotHasKey('subtotal', $dto['document']);
        $this->assertArrayNotHasKey('exchange_rate', $dto['document']);
        $this->assertArrayNotHasKey('unit_cost', $dto['lines'][0]);
        $this->assertArrayNotHasKey('total', $dto['lines'][0]);

        // 4. CRITICAL NEGATIVE CHECK: reader lacking cost CANNOT access Vendor Payment or Vendor Statement
        $pmtResponse = $this->get(route('pdf.vendor-payment', $payment->public_id));
        $pmtResponse->assertForbidden();
        $this->assertNoDocumentBytes($pmtResponse->getContent(), ['%PDF-', $payment->payment_number]);

        $stmtResponse = $this->get(route('pdf.vendor-statement', $this->vendor->public_id));
        $stmtResponse->assertForbidden();
        $this->assertNoDocumentBytes($stmtResponse->getContent(), ['%PDF-', $this->vendor->name_ar]);
    }

    public function test_pdf_only_vendor_statement_missing_statement_or_cost_fails_before_expensive_rendering(): void
    {
        // Provision purchase & payment while owner is still active
        $purchase = $this->createAndPostPurchase();
        $payment = $this->createVendorPayment($purchase, '20.00');

        $instrumented = new HookablePurchasingDocumentRenderer;
        app()->instance(PurchasingDocumentRenderer::class, $instrumented);

        // Case A: User has ONLY purchasing.document.pdf (lacks vendors.statement.view and purchasing.cost.view)
        $pdfOnlyActor = $this->customActor(['purchasing.document.pdf'], 'PdfOnlyActor');
        $this->activate($pdfOnlyActor);

        $responseA = $this->get(route('pdf.vendor-statement', $this->vendor->public_id));
        $responseA->assertForbidden();
        $this->assertSame(0, $instrumented->renderCalls, 'Expensive rendering must not execute for pdf-only actor.');
        $this->assertNoDocumentBytes($responseA->getContent(), ['%PDF-', $this->vendor->name_ar]);

        $responseAPrint = $this->get(route('pdf.vendor-statement', $this->vendor->public_id).'?format=print');
        $responseAPrint->assertForbidden();
        $this->assertSame(0, $instrumented->renderCalls, 'Expensive HTML rendering must not execute for pdf-only actor.');

        // Case B: User has purchasing.document.pdf and vendors.statement.view, but lacks purchasing.cost.view
        $missingCostActor = $this->customActor([
            'purchasing.document.pdf',
            'vendors.statement.view',
        ], 'MissingCostActor');
        $this->activate($missingCostActor);

        $responseB = $this->get(route('pdf.vendor-statement', $this->vendor->public_id));
        $responseB->assertForbidden();
        $this->assertSame(0, $instrumented->renderCalls, 'Expensive rendering must not execute when missing cost authority.');

        // Case C: User has purchasing.document.pdf and purchasing.cost.view, but lacks vendors.statement.view
        $missingStmtActor = $this->customActor([
            'purchasing.document.pdf',
            'purchasing.cost.view',
        ], 'MissingStmtActor');
        $this->activate($missingStmtActor);

        $responseC = $this->get(route('pdf.vendor-statement', $this->vendor->public_id));
        $responseC->assertForbidden();
        $this->assertSame(0, $instrumented->renderCalls, 'Expensive rendering must not execute when missing statement authority.');

        // Case D: Valid combined grants: actor has all 3 required permissions
        $validActor = $this->customActor([
            'purchasing.document.pdf',
            'vendors.statement.view',
            'purchasing.cost.view',
        ], 'ValidStatementActor');
        $this->activate($validActor);

        $responseD = $this->get(route('pdf.vendor-statement', $this->vendor->public_id));
        $responseD->assertOk();
        $this->assertSame(1, $instrumented->renderCalls, 'Rendering must execute exactly once for fully authorized actor.');
        $this->assertStringStartsWith('%PDF-', $responseD->getContent());

        // Case E: Vendor Payment also checks authority before expensive rendering
        $this->activate($pdfOnlyActor);
        $responseE = $this->get(route('pdf.vendor-payment', $payment->public_id));
        $responseE->assertForbidden();
        $this->assertSame(1, $instrumented->renderCalls, 'Expensive rendering must not execute for payment without financial read authority.');
    }

    public function test_foreign_company_subjects_fail_and_leak_no_document_bytes(): void
    {
        $companyBFixtures = $this->setupCompanyB();

        // Authenticated in Company A as Owner
        $this->activate($this->owner);

        // Purchasing routes: Company B subjects requested under Company A context
        $endpoints = [
            'pdf.purchase' => $companyBFixtures['purchaseB']->public_id,
            'pdf.purchase-return' => $companyBFixtures['returnB']->public_id,
            'pdf.vendor-payment' => $companyBFixtures['paymentB']->public_id,
            'pdf.vendor-statement' => $companyBFixtures['vendorB']->public_id,
        ];

        foreach ($endpoints as $routeName => $publicId) {
            $response = $this->get(route($routeName, $publicId));
            $response->assertNotFound();
            $this->assertNoDocumentBytes($response->getContent(), [
                '%PDF-',
                'window.print()',
                $publicId,
            ]);
        }

        // Sales analog routes: Company B subjects requested under Company A context
        $salesEndpoints = [
            'pdf.invoice' => $companyBFixtures['invoiceB']->public_id,
            'pdf.quotation' => $companyBFixtures['quoteB']->public_id,
            'pdf.return' => $companyBFixtures['salesReturnB']->public_id,
            'pdf.payment' => $companyBFixtures['customerPaymentB']->public_id,
            'pdf.statement' => $companyBFixtures['customerB']->public_id,
        ];

        foreach ($salesEndpoints as $routeName => $publicId) {
            $response = $this->get(route($routeName, $publicId));
            $response->assertNotFound();
            $this->assertNoDocumentBytes($response->getContent(), [
                '%PDF-',
                'window.print()',
                $publicId,
            ]);
        }

        // Direct builder / query contract boundaries: mismatched company context rejects immediately
        $builder = app(PurchasingDocumentBuilder::class);

        try {
            $builder->build($companyBFixtures['purchaseB']);
            $this->fail('PurchasingDocumentBuilder::build accepted foreign company purchase.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('matching company context', $e->getMessage());
        }

        try {
            $builder->statement($companyBFixtures['vendorB']);
            $this->fail('PurchasingDocumentBuilder::statement accepted foreign company vendor.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('matching company context', $e->getMessage());
        }

        try {
            app(VendorStatementQuery::class)->execute($companyBFixtures['vendorB']);
            $this->fail('VendorStatementQuery::execute accepted foreign company vendor.');
        } catch (NoActiveCompanyException $e) {
            $this->assertStringContainsString('matching company context', $e->getMessage());
        }
    }

    public function test_mid_render_downgrade_or_revocation_refuses_purchasing_document_delivery(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '4', 'unit_cost' => '30.000000'],
            ],
        ]);
        $payment = $this->createVendorPayment($purchase, '60.00');

        $actor = $this->customActor([
            'purchasing.purchase.view',
            'purchasing.document.pdf',
            'purchasing.cost.view',
            'money.vendor_payment.create',
        ], 'PurchasingAuditor');
        $role = $actor->roles()->firstOrFail();

        $hooked = new HookablePurchasingDocumentRenderer;
        app()->instance(PurchasingDocumentRenderer::class, $hooked);

        // Case A: Mid-render revocation of purchasing.cost.view during Purchase PDF rendering
        $this->activate($actor);
        $hooked->onRender = function (array $data, string $format) use ($role): void {
            // Document was prepared with cost; downgrade occurs while mPDF runs
            $role->revokePermissionTo('purchasing.cost.view');
        };

        $responseA = $this->get(route('pdf.purchase', $purchase->public_id));
        $responseA->assertForbidden();
        $this->assertSame(1, $hooked->renderCalls, 'Renderer executed, proving actual rendering occurred.');
        $this->assertNoDocumentBytes($responseA->getContent(), ['%PDF-', '30.000000']);

        // Case B: Mid-render membership revocation during Purchase PDF rendering
        $role->givePermissionTo('purchasing.cost.view');
        $this->activate($actor);
        $hooked->onRender = function (array $data, string $format) use ($actor): void {
            DB::table('company_user')
                ->where('company_id', $this->company->id)
                ->where('user_id', $actor->id)
                ->update(['status' => 'inactive']);
        };

        $responseB = $this->get(route('pdf.purchase', $purchase->public_id));
        $this->assertTrue(in_array($responseB->getStatusCode(), [403, 302], true), 'Delivery must be refused upon membership inactivation.');
        $this->assertSame(2, $hooked->renderCalls, 'Renderer executed before fresh membership check refused delivery.');
        $this->assertNoDocumentBytes($responseB->getContent(), ['%PDF-', $purchase->purchase_number]);

        // Case C: Mid-render revocation of cost permission during Vendor Payment print rendering
        DB::table('company_user')
            ->where('company_id', $this->company->id)
            ->where('user_id', $actor->id)
            ->update(['status' => 'active']);
        $this->activate($actor);

        $hooked->onRender = function (array $data, string $format) use ($role): void {
            $role->revokePermissionTo('purchasing.cost.view');
        };

        $responseC = $this->get(route('pdf.vendor-payment', $payment->public_id).'?format=print');
        $responseC->assertForbidden();
        $this->assertSame(3, $hooked->renderCalls);
        $this->assertNoDocumentBytes($responseC->getContent(), ['window.print()', $payment->payment_number]);

        // Case D: Mid-render membership revocation during Vendor Payment PDF rendering
        $role->givePermissionTo('purchasing.cost.view');
        $this->activate($actor);

        $hooked->onRender = function (array $data, string $format) use ($actor): void {
            DB::table('company_user')
                ->where('company_id', $this->company->id)
                ->where('user_id', $actor->id)
                ->update(['status' => 'inactive']);
        };

        $responseD = $this->get(route('pdf.vendor-payment', $payment->public_id));
        $this->assertTrue(in_array($responseD->getStatusCode(), [403, 302], true));
        $this->assertSame(4, $hooked->renderCalls);
        $this->assertNoDocumentBytes($responseD->getContent(), ['%PDF-', $payment->payment_number]);
    }

    public function test_mid_render_downgrade_or_revocation_refuses_sales_document_delivery(): void
    {
        $salesFixtures = $this->createSalesFixtures();
        $invoice = $salesFixtures['invoice'];
        $customer = $salesFixtures['customer'];

        $actor = $this->customActor([
            'sales.invoice.view',
            'sales.document.pdf',
            'sales.statement.view',
            'customers.statement.view',
        ], 'SalesAuditor');
        $role = $actor->roles()->firstOrFail();

        $hookedSales = new HookablePdfRendererService;
        app()->instance(PdfRendererService::class, $hookedSales);

        // Case A: Mid-render revocation of sales.invoice.view during Invoice PDF rendering
        $this->activate($actor);
        $hookedSales->onRender = function (DocumentData $data) use ($role): void {
            $role->revokePermissionTo('sales.invoice.view');
        };

        $responseA = $this->get(route('pdf.invoice', $invoice->public_id));
        $responseA->assertForbidden();
        $this->assertSame(1, $hookedSales->renderCalls, 'Sales PDF renderer executed before middleware refused delivery.');
        $this->assertNoDocumentBytes($responseA->getContent(), ['%PDF-', $invoice->invoice_number]);

        // Case B: Mid-render membership revocation during Invoice PDF rendering
        $role->givePermissionTo('sales.invoice.view');
        $this->activate($actor);
        $hookedSales->onRender = function (DocumentData $data) use ($actor): void {
            DB::table('company_user')
                ->where('company_id', $this->company->id)
                ->where('user_id', $actor->id)
                ->update(['status' => 'inactive']);
        };

        $responseB = $this->get(route('pdf.invoice', $invoice->public_id));
        $this->assertTrue(in_array($responseB->getStatusCode(), [403, 302], true));
        $this->assertSame(2, $hookedSales->renderCalls);
        $this->assertNoDocumentBytes($responseB->getContent(), ['%PDF-', $invoice->invoice_number]);

        // Case C: Mid-render revocation of customers.statement.view during Customer Statement PDF rendering
        DB::table('company_user')
            ->where('company_id', $this->company->id)
            ->where('user_id', $actor->id)
            ->update(['status' => 'active']);
        $this->activate($actor);

        $hookedSales->onRender = function (DocumentData $data) use ($role): void {
            $role->revokePermissionTo('customers.statement.view');
        };

        $responseC = $this->get(route('pdf.statement', $customer->public_id));
        $responseC->assertForbidden();
        $this->assertSame(3, $hookedSales->renderCalls, 'Customer statement renderer executed before middleware refused delivery.');
        $this->assertNoDocumentBytes($responseC->getContent(), ['%PDF-', $customer->name_ar]);

        // Case D: AR/EN print timing coverage through lower DocumentRenderer view composer hook
        $role->givePermissionTo('customers.statement.view');
        $this->activate($actor);

        $printViewComposed = 0;
        View::composer('pdf.document', function () use (&$printViewComposed, $role): void {
            $printViewComposed++;
            $role->revokePermissionTo('sales.invoice.view');
        });

        $responseD = $this->get(route('pdf.invoice', $invoice->public_id).'?format=print&locale=ar');
        $responseD->assertForbidden();
        $this->assertSame(1, $printViewComposed, 'Print view composer executed before middleware refused delivery.');
        $this->assertNoDocumentBytes($responseD->getContent(), ['window.print()', $invoice->invoice_number]);
    }

    public function test_cached_in_memory_role_and_membership_relations_do_not_bypass_delivery_guards(): void
    {
        $purchase = $this->createAndPostPurchase();
        $salesFixtures = $this->createSalesFixtures();
        $invoice = $salesFixtures['invoice'];

        $actor = $this->customActor([
            'purchasing.purchase.view',
            'purchasing.document.pdf',
            'sales.invoice.view',
            'sales.document.pdf',
        ], 'CachedRelationActor');
        $role = $actor->roles()->firstOrFail();

        $this->activate($actor);

        // Pre-load in-memory Spatie relations on model
        $actor->load('roles.permissions');
        $this->assertTrue($actor->roles->isNotEmpty());

        // Invalidate in DB: revoke purchasing.document.pdf
        $role->revokePermissionTo('purchasing.document.pdf');

        // Request must refuse delivery despite any stale model references
        $this->get(route('pdf.purchase', $purchase->public_id))->assertForbidden();

        // Invalidate in DB: revoke sales.document.pdf
        $role->revokePermissionTo('sales.document.pdf');
        $this->get(route('pdf.invoice', $invoice->public_id))->assertForbidden();

        // Invalidate in DB: set membership inactive
        DB::table('company_user')
            ->where('company_id', $this->company->id)
            ->where('user_id', $actor->id)
            ->update(['status' => 'inactive']);

        $response = $this->get(route('pdf.purchase', $purchase->public_id));
        $this->assertTrue(in_array($response->getStatusCode(), [403, 302], true));
    }

    public function test_customer_statement_and_vendor_statement_parity_under_permission_discipline(): void
    {
        $salesFixtures = $this->createSalesFixtures();
        $customer = $salesFixtures['customer'];

        // Customer Statement requires: sales.document.pdf + sales.statement.view + customers.statement.view
        // Vendor Statement requires: purchasing.document.pdf + vendors.statement.view + purchasing.cost.view

        // 1. Default Viewer role check
        $viewer = $this->createViewerUser();
        $this->activate($viewer);

        // Viewer has sales.statement.view and customers.statement.view, but lacks sales.document.pdf
        $this->get(route('pdf.statement', $customer->public_id))->assertForbidden();
        // Viewer lacks purchasing.document.pdf, vendors.statement.view, and purchasing.cost.view
        $this->get(route('pdf.vendor-statement', $this->vendor->public_id))->assertForbidden();

        // 2. Partial grants test
        $salesPartial = $this->customActor([
            'sales.document.pdf',
            'sales.statement.view',
            // missing customers.statement.view
        ], 'SalesPartial');
        $this->activate($salesPartial);
        $this->get(route('pdf.statement', $customer->public_id))->assertForbidden();

        $vendorPartial = $this->customActor([
            'purchasing.document.pdf',
            'vendors.statement.view',
            // missing purchasing.cost.view
        ], 'VendorPartial');
        $this->activate($vendorPartial);
        $this->get(route('pdf.vendor-statement', $this->vendor->public_id))->assertForbidden();

        // 3. Fully authorized actors succeed
        $salesFull = $this->customActor([
            'sales.document.pdf',
            'sales.statement.view',
            'customers.statement.view',
        ], 'SalesFull');
        $this->activate($salesFull);
        $this->get(route('pdf.statement', $customer->public_id))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $vendorFull = $this->customActor([
            'purchasing.document.pdf',
            'vendors.statement.view',
            'purchasing.cost.view',
        ], 'VendorFull');
        $this->activate($vendorFull);
        $this->get(route('pdf.vendor-statement', $this->vendor->public_id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    /**
     * @param  list<string>  $forbiddenFragments
     */
    private function assertNoDocumentBytes(string $content, array $forbiddenFragments): void
    {
        $this->assertStringNotContainsString('application/pdf', $content);
        foreach ($forbiddenFragments as $fragment) {
            $this->assertStringNotContainsString($fragment, $content, "Response must not leak document fragment [{$fragment}].");
        }
    }

    private function createViewerUser(): User
    {
        $viewer = User::factory()->create(['locale' => 'ar']);
        $this->company->users()->attach($viewer->id, [
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        setPermissionsTeamId($this->company->id);
        $viewerRole = Role::where('company_id', $this->company->id)->where('name', 'Viewer')->firstOrFail();
        $viewer->assignRole($viewerRole);

        return $viewer;
    }

    private function createVendorPayment(Purchase $purchase, string $amount = '50.00'): VendorPayment
    {
        return app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03',
            'payment_method' => 'cash',
            'amount' => $amount,
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-doc-auth-'.uniqid(),
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => $amount],
            ],
        ]);
    }

    /**
     * @return array{
     *     customer: Customer,
     *     invoice: SalesInvoice,
     *     quote: Quotation,
     *     salesReturn: SalesReturn,
     *     customerPayment: CustomerPayment
     * }
     */
    private function createSalesFixtures(): array
    {
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل مبيعات أمني',
            'name_en' => 'Secure Sales Customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $invoiceDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'issue_date' => '2026-10-01',
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'Service Item for Auth Test',
                    'quantity' => '2',
                    'unit_price' => '100',
                ],
            ],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($invoiceDraft, $this->owner);

        $quote = app(CreateQuotationAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'issue_date' => '2026-10-01',
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'Quotation Service Item for Auth Test',
                    'quantity' => '1',
                    'unit_price' => '100',
                ],
            ],
        ]);

        $salesReturnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => '2026-10-02',
            'lines' => [
                [
                    'sales_invoice_line_id' => $invoice->lines->first()->id,
                    'quantity' => '1',
                ],
            ],
        ]);
        $salesReturn = app(PostSalesReturnAction::class)->execute($salesReturnDraft, $this->owner);

        $customerPayment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1',
            'idempotency_key' => 'cp-auth-'.uniqid(),
            'allocations' => [
                ['sales_invoice_id' => $invoice->id, 'allocated_amount' => '100.00', 'payment_currency_amount' => '100.00'],
            ],
        ]);

        return [
            'customer' => $customer,
            'invoice' => $invoice,
            'quote' => $quote,
            'salesReturn' => $salesReturn,
            'customerPayment' => $customerPayment,
        ];
    }

    /**
     * Provision independent foreign company fixture.
     *
     * @return array<string, mixed>
     */
    private function setupCompanyB(): array
    {
        $context = app(CompanyContext::class);
        $context->clear();

        $ownerB = User::factory()->create(['locale' => 'en']);
        $companyB = app(CreateCompanyAction::class)->execute($ownerB, [
            'name_ar' => 'شركة أجنبية باء',
            'name_en' => 'Foreign Company B',
            'base_currency_code' => 'ILS',
        ]);

        $context->setCompany($companyB, $ownerB);
        $this->actingAs($ownerB);
        setPermissionsTeamId($companyB->id);

        $warehouseB = Warehouse::where('company_id', $companyB->id)->firstOrFail();
        $vendorB = app(VendorCatalogService::class)->save($companyB, $ownerB, [
            'name_ar' => 'مورد أجنبي باء',
            'name_en' => 'Foreign Vendor B',
            'default_currency_code' => 'ILS',
        ]);

        $productB = app(ProductCatalogService::class)->createProduct($companyB, [
            'name_ar' => 'سلعة باء',
            'name_en' => 'Product B',
            'sku' => 'ITM-B-01',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => Unit::where('code', 'piece')->firstOrFail()->id,
        ], $ownerB->id);

        $cashAccountB = app(CreateMoneyAccountAction::class)->execute($companyB, $ownerB, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'ILS',
            'name_ar' => 'صندوق باء النقدي',
            'name_en' => 'Company B Cash Box',
        ]);

        $purchaseDraftB = app(CreatePurchaseDraftAction::class)->execute($companyB, $ownerB, [
            'vendor_id' => $vendorB->id,
            'warehouse_id' => $warehouseB->id,
            'purchase_date' => '2026-10-01',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'lines' => [
                ['product_id' => $productB->id, 'quantity' => '5', 'unit_cost' => '20'],
            ],
        ]);
        $purchaseB = app(PostPurchaseAction::class)->execute($purchaseDraftB, $ownerB);

        $returnDraftB = app(CreatePurchaseReturnDraftAction::class)->execute($companyB, $ownerB, [
            'purchase_id' => $purchaseB->id,
            'return_date' => '2026-10-02',
            'reason' => 'Defective shipment',
            'lines' => [
                ['purchase_line_id' => $purchaseB->lines->first()->id, 'quantity' => '1'],
            ],
        ]);
        $returnB = app(PostPurchaseReturnAction::class)->execute($returnDraftB, $ownerB);

        $paymentB = app(PostVendorPaymentAction::class)->execute($companyB, $ownerB, [
            'vendor_id' => $vendorB->id,
            'money_account_id' => $cashAccountB->id,
            'payment_date' => '2026-10-03',
            'payment_method' => 'cash',
            'amount' => '40.00',
            'exchange_rate' => '1',
            'idempotency_key' => 'pmt-company-b-auth',
            'allocations' => [
                ['purchase_id' => $purchaseB->id, 'allocated_amount' => '40.00'],
            ],
        ]);

        $customerB = Customer::create([
            'company_id' => $companyB->id,
            'name_ar' => 'عميل باء',
            'name_en' => 'Customer B',
            'active' => true,
            'created_by' => $ownerB->id,
        ]);

        $invoiceDraftB = app(CreateSalesInvoiceDraftAction::class)->execute($companyB, $ownerB, [
            'customer_id' => $customerB->id,
            'warehouse_id' => $warehouseB->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'issue_date' => '2026-10-01',
            'lines' => [
                ['product_id' => null, 'item_description' => 'Item B', 'quantity' => '2', 'unit_price' => '40'],
            ],
        ]);
        $invoiceB = app(PostSalesInvoiceAction::class)->execute($invoiceDraftB, $ownerB);

        $quoteB = app(CreateQuotationAction::class)->execute($companyB, $ownerB, [
            'customer_id' => $customerB->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'issue_date' => '2026-10-01',
            'lines' => [
                ['product_id' => null, 'item_description' => 'Quote B', 'quantity' => '1', 'unit_price' => '40'],
            ],
        ]);

        $salesReturnDraftB = app(CreateSalesReturnDraftAction::class)->execute($companyB, $ownerB, [
            'sales_invoice_id' => $invoiceB->id,
            'issue_date' => '2026-10-02',
            'lines' => [
                ['sales_invoice_line_id' => $invoiceB->lines->first()->id, 'quantity' => '1'],
            ],
        ]);
        $salesReturnB = app(PostSalesReturnAction::class)->execute($salesReturnDraftB, $ownerB);

        $customerPaymentB = app(PostCustomerPaymentAction::class)->execute($companyB, $ownerB, [
            'customer_id' => $customerB->id,
            'money_account_id' => $cashAccountB->id,
            'payment_date' => '2026-10-03',
            'payment_method' => 'cash',
            'amount' => '40.00',
            'exchange_rate' => '1',
            'idempotency_key' => 'cp-b-auth',
            'allocations' => [
                ['sales_invoice_id' => $invoiceB->id, 'allocated_amount' => '40.00', 'payment_currency_amount' => '40.00'],
            ],
        ]);

        return [
            'companyB' => $companyB,
            'ownerB' => $ownerB,
            'warehouseB' => $warehouseB,
            'vendorB' => $vendorB,
            'productB' => $productB,
            'purchaseB' => $purchaseB,
            'returnB' => $returnB,
            'paymentB' => $paymentB,
            'customerB' => $customerB,
            'invoiceB' => $invoiceB,
            'quoteB' => $quoteB,
            'salesReturnB' => $salesReturnB,
            'customerPaymentB' => $customerPaymentB,
        ];
    }
}

/**
 * Controlled mock renderer instrumentation for Purchasing document outputs.
 */
class HookablePurchasingDocumentRenderer extends PurchasingDocumentRenderer
{
    /** @var (\Closure(array<string, mixed>, string): void)|null */
    public ?\Closure $onRender = null;

    public int $renderCalls = 0;

    protected bool $inRender = false;

    public function html(array $data, bool $printControls = false): string
    {
        $isTopLevel = ! $this->inRender;
        if ($isTopLevel) {
            $this->inRender = true;
            $this->renderCalls++;
            if ($this->onRender !== null) {
                ($this->onRender)($data, 'html');
            }
        }

        try {
            return '<!DOCTYPE html><html><body>mock print</body></html>';
        } finally {
            if ($isTopLevel) {
                $this->inRender = false;
            }
        }
    }

    public function pdf(array $data): string
    {
        $isTopLevel = ! $this->inRender;
        if ($isTopLevel) {
            $this->inRender = true;
            $this->renderCalls++;
            if ($this->onRender !== null) {
                ($this->onRender)($data, 'pdf');
            }
        }

        try {
            return "%PDF-1.4\n%mock-purchasing-pdf-bytes\n";
        } finally {
            if ($isTopLevel) {
                $this->inRender = false;
            }
        }
    }
}

/**
 * Controlled mock renderer instrumentation for Sales document outputs.
 */
class HookablePdfRendererService extends PdfRendererService
{
    /** @var (\Closure(DocumentData, ?string, bool): void)|null */
    public ?\Closure $onRender = null;

    public int $renderCalls = 0;

    public function renderDocument(DocumentData $data, ?string $qrUrl = null, bool $guest = false): string
    {
        $this->renderCalls++;
        if ($this->onRender !== null) {
            ($this->onRender)($data, $qrUrl, $guest);
        }

        return "%PDF-1.4\n%mock-sales-pdf-bytes\n";
    }
}
