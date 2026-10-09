<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\Vendor;
use App\Services\Purchasing\PurchasingDocumentBuilder;
use App\Services\Purchasing\PurchasingDocumentRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\Feature\Phase5E\Phase5ETestCase;

class PurchasingDocumentsTest extends Phase5ETestCase
{
    public function test_routes_reauthorize_redacted_output_and_preserve_native_currency_payment_legs(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code'=>'USD','exchange_rate'=>'3.5',
            'lines'=>[['product_id'=>$this->product->id,'quantity'=>'10','unit_cost'=>'10']]]);
        $payment = app(PostVendorPaymentAction::class)->execute($this->company,$this->owner,[
            'vendor_id'=>$this->vendor->id,'money_account_id'=>$this->ilsCashAccount->id,'payment_date'=>'2026-10-03',
            'payment_method'=>'cash','amount'=>'330','exchange_rate'=>'1','idempotency_key'=>'p9-b-cross-currency',
            'allocations'=>[['purchase_id'=>$purchase->id,'allocated_amount'=>'100','payment_currency_amount'=>'330']]]);
        $dto = app(PurchasingDocumentBuilder::class)->build($payment,'en');
        $this->assertSame('USD',$dto['lines'][0]['currency_code']);
        $this->assertSame('100.000000',$dto['lines'][0]['allocated_amount']);
        $this->assertSame('ILS',$dto['lines'][0]['payment_currency_code']);
        $this->assertSame('330.000000',$dto['lines'][0]['payment_currency_amount']);
        $this->assertSame('330.000000',$dto['lines'][0]['settlement_base_value']);
        $before = $this->economicSnapshot();
        $this->get(route('pdf.vendor-payment',$payment->public_id).'?format=print&locale=en')->assertOk()->assertSee('USD')->assertSee('ILS');
        $this->get(route('pdf.purchase',$purchase->public_id).'?download=1&locale=en')->assertOk()->assertHeader('Content-Type','application/pdf')->assertHeader('X-Robots-Tag','noindex, nofollow');
        $jod = $this->createAndPostPurchase(['currency_code'=>'JOD','exchange_rate'=>'5',
            'lines'=>[['product_id'=>$this->product->id,'quantity'=>'1','unit_cost'=>'12.345']]]);
        $jodDto = app(PurchasingDocumentBuilder::class)->build($jod,'ar');
        $this->assertSame('12.345000',$jodDto['document']['grand_total']);
        $this->assertSame('JOD',$jodDto['document']['currency_code']);
        File::put(base_path('.ai/delegations/phase9-implementation/pdf/purchase-jod-ar.pdf'),app(PurchasingDocumentRenderer::class)->pdf($jodDto));
        $before = $this->economicSnapshot();
        $this->get(route('pdf.vendor-statement',$this->vendor->public_id).'?format=print&locale=en')->assertOk()->assertSee('USD')->assertSee('JOD');
        $this->assertSame($before,$this->economicSnapshot());
        $actor = $this->customActor(['purchasing.purchase.view','purchasing.document.pdf']);
        $this->activate($actor);
        $this->get(route('pdf.purchase',$purchase->public_id).'?format=print&locale=en')->assertOk()->assertSee('Quantity Only')->assertDontSee('Grand Total')->assertDontSee('3.500000');
        $this->get(route('pdf.vendor-payment',$payment->public_id))->assertForbidden();
        $this->get(route('pdf.vendor-statement',$this->vendor->public_id))->assertForbidden();
    }

    public function test_purchase_exact_values_and_posted_snapshots_survive_master_changes(): void
    {
        $cartonUnit = Unit::where('company_id', $this->company->id)->where('code', 'carton')->first();
        if ($cartonUnit === null) {
            $cartonUnit = Unit::create([
                'company_id' => $this->company->id,
                'name_ar' => 'كرتونة',
                'name_en' => 'Carton',
                'code' => 'carton',
            ]);
        }

        $cartonProductUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $cartonUnit->id,
            'conversion_to_base' => '12',
            'active' => true,
        ]);

        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $cartonProductUnit->id,
                    'quantity' => '5',
                    'unit_cost' => '24.000000',
                    'discount_type' => 'percent',
                    'discount_value' => '10',
                ],
            ],
        ]);

        $builder = app(PurchasingDocumentBuilder::class);
        $before = $builder->build($purchase, 'en');

        $this->assertTrue($before['with_cost']);
        $this->assertSame('purchase', $before['type']);
        $this->assertSame('en', $before['locale']);
        $this->assertSame($purchase->purchase_number, $before['document']['number']);
        $this->assertSame('posted', $before['document']['status']);
        $this->assertSame('ILS', $before['document']['currency_code']);
        $this->assertSame('1.0000000000', $before['document']['exchange_rate']);
        $this->assertSame('108.000000', $before['document']['grand_total']);
        $this->assertSame('12.000000', $before['document']['discount_total']);

        // Check line conversion snapshots
        $this->assertSame('5.000000', $before['lines'][0]['quantity']);
        $this->assertSame('60.000000', $before['lines'][0]['quantity_base']);
        $this->assertSame('Carton', $before['lines'][0]['unit_name']);
        $this->assertSame('24.000000', $before['lines'][0]['unit_cost']);

        // Update master records in database: vendor, product, unit
        $this->vendor->update([
            'name_ar' => 'اسم معدل',
            'name_en' => 'RENAMED MASTER VENDOR',
        ]);
        $this->product->update([
            'name_en' => 'RENAMED MASTER PRODUCT',
            'sku' => 'MODIFIED-SKU',
        ]);
        $cartonUnit->update([
            'name_en' => 'MODIFIED UNIT NAME',
        ]);

        // Re-read document: historical snapshots must not be rewritten by master data edits
        $after = $builder->build($purchase->fresh(), 'en');
        $this->assertSame('Main Vendor', $after['vendor']['name']);
        $this->assertSame('Carton', $after['lines'][0]['unit_name']);
        $this->assertSame($before['lines'][0]['item_description'], $after['lines'][0]['item_description']);
        $this->assertSame('ITM-01', $after['lines'][0]['sku']);

        // Missing snapshot on posted purchase must throw InvalidArgumentException
        $purchaseNoSnapshot = clone $purchase;
        $purchaseNoSnapshot->vendor_snapshot = null;
        $purchaseNoSnapshot->saveQuietly();

        $this->expectException(InvalidArgumentException::class);
        $builder->build($purchaseNoSnapshot->fresh());
    }

    public function test_quantity_only_dto_and_html_omit_all_cost_and_financial_keys(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '50.000000',
                ],
            ],
        ]);

        // Actor without purchasing.cost.view
        $restrictedActor = $this->customActor([
            'purchasing.purchase.view',
            'purchasing.document.pdf',
        ], 'WarehouseClerk');

        $this->activate($restrictedActor);

        $builder = app(PurchasingDocumentBuilder::class);
        $dto = $builder->build($purchase, 'en');

        $this->assertFalse($dto['with_cost']);

        // Document keys check: NO monetary/cost/FX/tax keys
        $financialDocKeys = [
            'subtotal', 'discount_total', 'tax_total', 'grand_total', 'amount_base',
            'currency_code', 'base_currency_code', 'exchange_rate', 'total_landed_cost_base',
        ];
        foreach ($financialDocKeys as $key) {
            $this->assertArrayNotHasKey($key, $dto['document'], "Key {$key} must be absent in quantity-only document");
        }

        // Line keys check: NO cost/price/discount/tax/total keys
        $financialLineKeys = [
            'unit_cost', 'discount', 'tax', 'total', 'landed_cost_allocated_base', 'inventory_unit_cost_base',
        ];
        foreach ($financialLineKeys as $key) {
            $this->assertArrayNotHasKey($key, $dto['lines'][0], "Key {$key} must be absent in quantity-only line");
        }

        // Render HTML and assert truthful labelling + absence of price columns and totals
        $renderer = app(PurchasingDocumentRenderer::class);
        $html = $renderer->html($dto, printControls: true);

        $this->assertStringContainsString('Quantity Only', $html);
        $this->assertStringContainsString('window.print()', $html);
        $this->assertStringNotContainsString('Unit Cost', $html);
        $this->assertStringNotContainsString('50.000000', $html);
        $this->assertStringNotContainsString('Grand Total', $html);
    }

    public function test_purchase_return_preserves_original_purchase_number_and_debit_meaning(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '15.000000',
                ],
            ],
        ]);

        $return = $this->createAndPostReturn($purchase, [
            'reason' => 'Damaged in transit',
            'lines' => [
                [
                    'purchase_line_id' => $purchase->lines->first()->id,
                    'quantity' => '2',
                ],
            ],
        ]);

        $builder = app(PurchasingDocumentBuilder::class);
        $dto = $builder->build($return, 'en');

        $this->assertSame('purchase_return', $dto['type']);
        $this->assertSame($purchase->purchase_number, $dto['document']['original_reference']);
        $this->assertSame('Damaged in transit', $dto['document']['reason']);
        $this->assertSame('30.000000', $dto['document']['grand_total']);
        $this->assertTrue($dto['with_cost']);

        // Check line allocations
        $this->assertNotEmpty($dto['lines'][0]['allocations']);
        $this->assertSame('2.000000', $dto['lines'][0]['allocations'][0]['quantity']);

        // Verify quantity-only mode for return
        $restrictedActor = $this->customActor([
            'purchasing.purchase.view',
            'purchasing.document.pdf',
        ], 'ReturnReceiver');

        $this->activate($restrictedActor);

        $restrictedDto = $builder->build($return, 'ar');
        $this->assertFalse($restrictedDto['with_cost']);
        $this->assertArrayNotHasKey('grand_total', $restrictedDto['document']);
        $this->assertArrayNotHasKey('unit_cost', $restrictedDto['lines'][0]);

        $html = app(PurchasingDocumentRenderer::class)->html($restrictedDto);
        $this->assertStringContainsString('كميات فقط', $html);
        $this->assertStringNotContainsString('30.000000', $html);
    }

    public function test_vendor_payment_allocations_currencies_and_later_application_appendix(): void
    {
        $postAction = app(PostVendorPaymentAction::class);
        $applyAction = app(ApplyVendorPaymentCreditAction::class);
        $reverseAction = app(ReverseVendorPaymentAction::class);

        // 1. Initial Purchase of 100 ILS
        $purchase1 = $this->createAndPostPurchase([
            'purchase_date' => '2026-10-01',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10.000000'],
            ],
        ]);

        // 2. Vendor Payment with initial allocation
        $payment = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '200.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-p9-test-01',
            'allocations' => [
                ['purchase_id' => $purchase1->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        $builder = app(PurchasingDocumentBuilder::class);
        $dtoBefore = $builder->build($payment, 'en');

        $this->assertSame('vendor_payment', $dtoBefore['type']);
        $this->assertSame('posted', $dtoBefore['document']['status']);
        $this->assertSame('cash', $dtoBefore['document']['payment_method']);
        $this->assertNull($dtoBefore['document']['money_account']); // Omitted when no stored snapshot
        $this->assertCount(1, $dtoBefore['lines']);
        $this->assertSame($purchase1->purchase_number, $dtoBefore['lines'][0]['item_description']);
        $this->assertSame('100.000000', $dtoBefore['lines'][0]['allocated_amount']);
        $this->assertSame([], $dtoBefore['applications']);

        // 3. Second purchase of 50 ILS
        $purchase2 = $this->createAndPostPurchase([
            'purchase_date' => '2026-10-03',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10.000000'],
            ],
        ]);

        // 4. Later application of credit
        $applyAction->execute($payment, $this->owner, [
            'application_date' => '2026-10-04',
            'idempotency_key' => 'apply-p9-test-01',
            'allocations' => [
                ['purchase_id' => $purchase2->id, 'allocated_amount' => '50.00'],
            ],
        ]);

        $dtoAfter = $builder->build($payment->fresh(), 'en');
        $this->assertCount(1, $dtoAfter['lines']); // Initial allocation
        $this->assertCount(1, $dtoAfter['applications']); // Later application appendix
        $this->assertSame($purchase2->purchase_number, $dtoAfter['applications'][0]['item_description']);
        $this->assertSame('2026-10-04', $dtoAfter['applications'][0]['application_date']);
        $this->assertSame('posted', $dtoAfter['applications'][0]['status']);
        $this->assertSame('50.000000', $dtoAfter['applications'][0]['allocated_amount']);

        // 5. Reversal of payment
        $reverseAction->execute($payment->fresh(), $this->owner, 'Reversal test for PDF document');

        $dtoReversed = $builder->build($payment->fresh(), 'en');
        $this->assertSame('reversed', $dtoReversed['document']['status']);
        $this->assertTrue($dtoReversed['document']['is_reversed']);
        $this->assertSame('Reversal test for PDF document', $dtoReversed['document']['reversal_reason']);

        // 6. Permission check: requires purchasing.document.pdf AND VendorFinancialRead
        $unauthorizedActor = $this->customActor([
            'purchasing.document.pdf',
            // missing purchasing.cost.view
        ], 'PaymentViewerNoCost');

        $this->activate($unauthorizedActor);
        $this->expectException(AuthorizationException::class);
        $builder->build($payment->fresh());
    }

    public function test_vendor_statement_parity_date_range_and_zero_side_effects(): void
    {
        $postAction = app(PostVendorPaymentAction::class);

        $purchase = $this->createAndPostPurchase([
            'purchase_date' => '2026-10-01',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10.000000'],
            ],
        ]);

        $payment = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-stmt-parity',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        $snapshotBefore = $this->economicSnapshot();

        $builder = app(PurchasingDocumentBuilder::class);
        $stmtDto = $builder->statement($this->vendor, '2026-10-01', '2026-10-10', 'ar');

        $this->assertSame('vendor_statement', $stmtDto['type']);
        $this->assertSame('ar', $stmtDto['locale']);
        $this->assertNotEmpty($stmtDto['statement']['currencies']['ILS']['entries']);

        // Assert economic invariants unchanged (no financial or inventory writes)
        $this->assertSame($snapshotBefore, $this->economicSnapshot());

        // Validate date order validation
        $this->expectException(InvalidArgumentException::class);
        $builder->statement($this->vendor, '2026-10-20', '2026-10-10');
    }

    public function test_render_caps_reject_over_limit_and_never_leave_db_locks(): void
    {
        $transactionLevel = DB::transactionLevel();
        $this->assertSame(1, $transactionLevel); // Harness-owned RefreshDatabase transaction.

        $builder = app(PurchasingDocumentBuilder::class);

        // Lines over cap
        $oversizedData = [
            'type' => 'purchase',
            'locale' => 'ar',
            'company' => ['name' => 'Company'],
            'vendor' => ['name' => 'Vendor'],
            'document' => ['number' => 'P-01'],
            'lines' => array_fill(0, 501, ['line_number' => '1', 'item_description' => 'item']),
            'applications' => [],
            'statement' => null,
            'presentation' => [],
            'with_cost' => false,
        ];

        try {
            $builder->assertLimits($oversizedData);
            $this->fail('Over-limit document accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame($transactionLevel, DB::transactionLevel());
        }
    }

    public function test_actual_ar_en_four_outputs_exported_for_inspection(): void
    {
        $postAction = app(PostVendorPaymentAction::class);

        // 1. Purchase
        $purchase = $this->createAndPostPurchase([
            'purchase_date' => '2026-10-01',
            'vendor_invoice_number' => 'INV-VND-99',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '20', 'unit_cost' => '15.500000', 'discount_type' => 'fixed', 'discount_value' => '10'],
            ],
        ]);

        // 2. Purchase Return
        $return = $this->createAndPostReturn($purchase, [
            'return_date' => '2026-10-02',
            'reason' => 'Excess shipment return',
            'lines' => [
                ['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '5'],
            ],
        ]);

        // 3. Vendor Payment
        $payment = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03',
            'payment_method' => 'cash',
            'amount' => '150.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-export-01',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '150.00'],
            ],
        ]);

        $builder = app(PurchasingDocumentBuilder::class);
        $renderer = app(PurchasingDocumentRenderer::class);

        $directory = base_path('.ai/delegations/phase9-implementation/pdf');
        File::ensureDirectoryExists($directory);

        foreach (['ar', 'en'] as $locale) {
            // Purchase
            $purchaseData = $builder->build($purchase, $locale);
            $purchasePdf = $renderer->pdf($purchaseData);
            $this->assertStringStartsWith('%PDF-', $purchasePdf);
            File::put($directory.'/purchase-'.$locale.'.pdf', $purchasePdf);

            // Purchase Return
            $returnData = $builder->build($return, $locale);
            $returnPdf = $renderer->pdf($returnData);
            $this->assertStringStartsWith('%PDF-', $returnPdf);
            File::put($directory.'/purchase-return-'.$locale.'.pdf', $returnPdf);

            // Vendor Payment
            $paymentData = $builder->build($payment, $locale);
            $paymentPdf = $renderer->pdf($paymentData);
            $this->assertStringStartsWith('%PDF-', $paymentPdf);
            File::put($directory.'/vendor-payment-'.$locale.'.pdf', $paymentPdf);

            // Vendor Statement
            $stmtData = $builder->statement($this->vendor, '2026-10-01', '2026-10-10', $locale);
            $stmtPdf = $renderer->pdf($stmtData);
            $this->assertStringStartsWith('%PDF-', $stmtPdf);
            File::put($directory.'/vendor-statement-'.$locale.'.pdf', $stmtPdf);
        }

        // Verify all 8 files exist
        foreach (['purchase', 'purchase-return', 'vendor-payment', 'vendor-statement'] as $docType) {
            foreach (['ar', 'en'] as $locale) {
                $this->assertFileExists($directory.'/'.$docType.'-'.$locale.'.pdf');
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function economicSnapshot(): array
    {
        $result = [];
        $tables = [
            'posting_batches', 'posting_lines', 'stock_movements',
            'purchases', 'purchase_lines', 'purchase_returns', 'purchase_return_lines',
            'vendor_payments', 'vendor_payment_allocations', 'document_sequences',
        ];

        foreach ($tables as $table) {
            $result[$table] = hash('sha256', json_encode(
                DB::table($table)->where('company_id', $this->company->id)->orderBy('id')->get()->all(),
                JSON_THROW_ON_ERROR
            ));
        }

        return $result;
    }
}
