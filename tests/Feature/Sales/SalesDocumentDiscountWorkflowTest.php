<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Actions\Sales\ConvertQuotationToInvoiceAction;
use App\Actions\Sales\CreateQuotationAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\UpdateSalesInvoiceDraftAction;
use App\Domain\Sales\DocumentDiscountIntent;
use App\Models\Quotation;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\TaxRate;
use App\Services\Sales\SalesReturnAmounts;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class SalesDocumentDiscountWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    public function test_create_sales_invoice_draft_with_percentage_document_discount(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'percent',
            'discount_value' => '10.00',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '100.00'],
                ['item_description' => 'Item 2', 'quantity' => '1', 'unit_price' => '200.00'],
            ],
        ]);

        $this->assertSame('document', $invoice->discount_scope);
        $this->assertSame('percent', $invoice->discount_type);
        $this->assertSame('10.000000', (string) $invoice->discount_value);
        $this->assertSame('300.000000', (string) $invoice->discount_basis);
        $this->assertSame(DocumentDiscountIntent::VERSION_V1, $invoice->discount_calculation_version);
        $this->assertSame(2, $invoice->currency_minor_units);

        $lines = $invoice->lines()->orderBy('line_number')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('10.000000', (string) $lines[0]->allocated_document_discount);
        $this->assertSame('20.000000', (string) $lines[1]->allocated_document_discount);
        $this->assertSame('270.000000', (string) $invoice->total_amount);
    }

    public function test_create_sales_invoice_draft_with_fixed_document_discount_vector_1(): void
    {
        // Vector 1 Benchmark: 350.00 ILS total basis, 17.00 ILS fixed discount
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '17.00',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '100.00'],
                ['item_description' => 'Item 2', 'quantity' => '1', 'unit_price' => '200.00'],
                ['item_description' => 'Item 3', 'quantity' => '1', 'unit_price' => '50.00'],
            ],
        ]);

        $lines = $invoice->lines()->orderBy('line_number')->get();
        $this->assertSame('4.860000', (string) $lines[0]->allocated_document_discount);
        $this->assertSame('9.710000', (string) $lines[1]->allocated_document_discount);
        $this->assertSame('2.430000', (string) $lines[2]->allocated_document_discount);

        $totalAllocated = BigDecimal::of($lines[0]->allocated_document_discount)
            ->plus($lines[1]->allocated_document_discount)
            ->plus($lines[2]->allocated_document_discount);
        $this->assertSame('17.000000', (string) $totalAllocated);
        $this->assertSame('333.000000', (string) $invoice->total_amount);
    }

    public function test_update_sales_invoice_draft_modifying_document_discount(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '10.00',
            'lines' => [
                ['item_description' => 'Service A', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $this->assertSame('10.000000', (string) $invoice->lines()->first()->allocated_document_discount);
        $this->assertSame('90.000000', (string) $invoice->total_amount);

        // Update to 20% discount
        $updated = app(UpdateSalesInvoiceDraftAction::class)->execute($invoice, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'percent',
            'discount_value' => '20.00',
            'lines' => [
                ['item_description' => 'Service A', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $this->assertSame('percent', $updated->discount_type);
        $this->assertSame('20.000000', (string) $updated->discount_value);
        $this->assertSame('20.000000', (string) $updated->lines()->first()->allocated_document_discount);
        $this->assertSame('80.000000', (string) $updated->total_amount);
    }

    public function test_quotation_creation_and_conversion_carries_exact_document_discount_intent_once(): void
    {
        $quotation = app(CreateQuotationAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '25.00',
            'lines' => [
                ['item_description' => 'Project Phase 1', 'quantity' => '1', 'unit_price' => '150.00'],
                ['item_description' => 'Project Phase 2', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $this->assertSame('document', $quotation->discount_scope);
        $this->assertSame('fixed', $quotation->discount_type);
        $this->assertSame('25.000000', (string) $quotation->discount_value);
        $this->assertSame('250.000000', (string) $quotation->discount_basis);

        $qLines = $quotation->lines()->orderBy('line_number')->get();
        $this->assertSame('15.000000', (string) $qLines[0]->allocated_document_discount);
        $this->assertSame('10.000000', (string) $qLines[1]->allocated_document_discount);

        // Accept and convert quotation to invoice
        $quotation->update(['status' => Quotation::STATUS_ACCEPTED]);
        $converter = app(ConvertQuotationToInvoiceAction::class);
        $invoice = $converter->createFromAcceptedQuotation($quotation, $this->owner);

        $this->assertSame('document', $invoice->discount_scope);
        $this->assertSame('fixed', $invoice->discount_type);
        $this->assertSame('25.000000', (string) $invoice->discount_value);
        $this->assertSame('250.000000', (string) $invoice->discount_basis);
        $this->assertSame($quotation->currency_minor_units, $invoice->currency_minor_units);
        $this->assertSame($quotation->discount_calculation_version, $invoice->discount_calculation_version);

        $invLines = $invoice->lines()->orderBy('line_number')->get();
        $this->assertSame('15.000000', (string) $invLines[0]->allocated_document_discount);
        $this->assertSame('10.000000', (string) $invLines[1]->allocated_document_discount);
        $this->assertSame('225.000000', (string) $invoice->total_amount);
    }

    public function test_legacy_line_discount_scope_preserved_when_no_document_discount_provided(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'lines' => [
                ['item_description' => 'Item with line discount', 'quantity' => '1', 'unit_price' => '100.00', 'discount_amount' => '15.00'],
            ],
        ]);

        $this->assertSame('line', $invoice->discount_scope);
        $this->assertSame('none', $invoice->discount_type);
        $line = $invoice->lines()->first();
        $this->assertSame('15.000000', (string) $line->manual_discount_amount);
        $this->assertSame('0.000000', (string) $line->allocated_document_discount);
        $this->assertSame('85.000000', (string) $invoice->total_amount);
    }

    public function test_tax_inclusive_line_fails_closed_when_document_discount_specified(): void
    {
        $tax = TaxRate::create([
            'company_id' => $this->company->id,
            'name_ar' => 'شامل ضريبة',
            'name_en' => 'Inclusive Tax',
            'rate' => '16.000000',
            'tax_type' => 'vat',
            'calculation' => 'inclusive',
            'is_default' => false,
            'active' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('sales.tax_inclusive_global_discount_forbidden'));

        app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'percent',
            'discount_value' => '10.00',
            'lines' => [
                [
                    'item_description' => 'Inclusive item',
                    'quantity' => '1',
                    'unit_price' => '116.00',
                    'tax_rate_id' => $tax->id,
                ],
            ],
        ]);
    }

    public function test_sales_return_amounts_conserves_residuals_with_document_discount(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '10.00',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '10', 'unit_price' => '10.00'], // 100 basis, 10 discount
            ],
        ]);

        $invoiceLine = $invoice->lines()->first();
        $this->assertSame('10.000000', (string) $invoiceLine->allocated_document_discount);

        // Create return for partial quantity (5 out of 10)
        $return = new SalesReturn([
            'company_id' => $this->company->id,
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'return_date' => '2026-11-26',
            'status' => 'draft',
            'return_reason' => 'Defective',
        ]);
        $return->save();

        $returnLine = new SalesReturnLine([
            'sales_return_id' => $return->id,
            'sales_invoice_line_id' => $invoiceLine->id,
            'line_number' => 1,
            'item_description' => 'Item 1',
            'quantity' => '5.000000',
            'unit_price' => '10.000000',
            'tax_rate_id' => null,
            'tax_inclusive' => false,
            'tax_rate' => '0.000000',
            'tax_amount' => '0.000000',
            'discount_amount' => '5.000000',
            'subtotal' => '50.000000',
            'line_total' => '45.000000',
            'quantity_returned' => '5.000000',
        ]);
        $return->lines()->save($returnLine);

        $returnAmounts = app(SalesReturnAmounts::class);
        $returnAmounts->refresh($return);

        $this->assertSame('5.000000', (string) $return->discount_amount);
        $this->assertSame('45.000000', (string) $return->total_amount);
        $this->assertSame(2, $return->currency_minor_units);
    }
}
