<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Actions\Sales\ConvertQuotationToInvoiceAction;
use App\Actions\Sales\CreateQuotationAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\UpdateSalesInvoiceDraftAction;
use App\Application\Commands\SalesInvoiceCommands;
use App\Domain\Sales\DocumentDiscountIntent;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Models\CommandReceipt;
use App\Models\CompanyCurrency;
use App\Models\Currency;
use App\Models\TaxRate;
use App\Services\Money\CurrencyMinorUnitsResolver;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
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
        $this->assertSame('subtotal', $invoice->discount_basis);
        $this->assertSame(DocumentDiscountIntent::VERSION_CANONICAL, $invoice->discount_calculation_version);
        $this->assertSame(2, $invoice->currency_minor_units);

        $lines = $invoice->lines()->orderBy('line_number')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('10.000000', (string) $lines[0]->allocated_document_discount);
        $this->assertSame('20.000000', (string) $lines[1]->allocated_document_discount);
        $this->assertSame('270.000000', (string) $invoice->grand_total_currency);
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
        $this->assertSame('333.000000', (string) $invoice->grand_total_currency);
        $this->assertSame('subtotal', $invoice->discount_basis);
        $this->assertSame(DocumentDiscountIntent::VERSION_CANONICAL, $invoice->discount_calculation_version);
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
        $this->assertSame('90.000000', (string) $invoice->grand_total_currency);

        $updated = app(UpdateSalesInvoiceDraftAction::class)->execute($invoice, $this->owner, [
            'discount_type' => 'fixed',
            'discount_value' => '20.00',
            'lines' => [
                ['item_description' => 'Service A', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $this->assertSame('20.000000', (string) $updated->lines()->first()->allocated_document_discount);
        $this->assertSame('80.000000', (string) $updated->grand_total_currency);
    }

    public function test_update_omitted_discount_preserves_existing_discount(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '15.00',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $this->assertSame('fixed', $invoice->discount_type);
        $this->assertSame('15.000000', (string) $invoice->discount_value);
        $this->assertSame('document', $invoice->discount_scope);

        // Update omitting discount_type and discount_value
        $updated = app(UpdateSalesInvoiceDraftAction::class)->execute($invoice, $this->owner, [
            'notes' => 'Updated notes without discount fields',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $this->assertSame('fixed', $updated->discount_type);
        $this->assertSame('15.000000', (string) $updated->discount_value);
        $this->assertSame('document', $updated->discount_scope);
        $this->assertSame('15.000000', (string) $updated->lines()->first()->allocated_document_discount);
        $this->assertSame('85.000000', (string) $updated->grand_total_currency);
    }

    public function test_update_explicit_clear_discount(): void
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '15.00',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        // Explicit clear via discount_type => none
        $updated = app(UpdateSalesInvoiceDraftAction::class)->execute($invoice, $this->owner, [
            'discount_type' => 'none',
            'discount_value' => '0',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $this->assertNull($updated->discount_type);
        $this->assertSame('0.000000', (string) $updated->discount_value);
        $this->assertSame('100.000000', (string) $updated->grand_total_currency);
        $this->assertSame('0.000000', (string) $updated->lines()->first()->allocated_document_discount);
    }

    public function test_reject_positive_header_discount_with_line_scope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('sales.invalid_discount_scope'));

        app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '10.00',
            'discount_scope' => 'line',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);
    }

    public function test_legacy_receipt_before_upgrade_retried_after_upgrade_preserves_idempotency(): void
    {
        $key = 'legacy-key-'.Str::random(12);

        // Pre-upgrade payload has NO new discount fields
        $legacyPayload = [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'lines' => [
                ['item_description' => 'Legacy Item', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ];

        // 1. Create invoice draft with legacy payload
        $commands = app(SalesInvoiceCommands::class);
        $invoice = $commands->create($this->company, $this->owner, $legacyPayload, $key);

        $receipt = CommandReceipt::where('idempotency_key', $key)->firstOrFail();
        $originalHash = $receipt->request_hash;

        // 2. Retry the EXACT same payload with the same idempotency key
        $retriedInvoice = $commands->create($this->company, $this->owner, $legacyPayload, $key);

        $this->assertSame($invoice->id, $retriedInvoice->id);
        $this->assertSame($originalHash, $receipt->fresh()->request_hash);
    }

    public function test_invoice_form_digest_versions_document_discount_and_matches_legacy_when_absent(): void
    {
        $form = new InvoiceForm;
        $form->customer_id = $this->customer->id;
        $form->currency_code = 'ILS';
        $form->exchange_rate = '1.0000000000';
        $form->issue_date = '2026-11-25';
        $form->lines = [
            [
                'product_id' => null,
                'product_unit_id' => null,
                'item_description' => 'Item 1',
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount_type' => 'none',
                'discount_value' => '0',
                'tax_rate_id' => null,
            ],
        ];

        // When discount is none: computePayloadDigest matches computeLegacyPayloadDigest
        $form->discount_type = 'none';
        $form->discount_value = '0';
        $this->assertSame($form->computeLegacyPayloadDigest(), $form->computePayloadDigest());

        // When discount is active: computePayloadDigest is versioned (different from legacy)
        $form->discount_type = 'fixed';
        $form->discount_value = '10.00';
        $this->assertNull($form->computeLegacyPayloadDigest());
        $this->assertNotEmpty($form->computePayloadDigest());
    }

    public function test_quotation_conversion_preserves_document_discount_and_frozen_precision(): void
    {
        $quote = app(CreateQuotationAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'JOD',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '10.000',
            'lines' => [
                ['item_description' => 'Quote Item 1', 'quantity' => '1', 'unit_price' => '100.000'],
                ['item_description' => 'Quote Item 2', 'quantity' => '1', 'unit_price' => '100.000'],
            ],
        ]);

        $this->assertSame(3, $quote->currency_minor_units);
        $this->assertSame('10.000000', (string) $quote->discount_value);

        $quote->status = 'accepted';
        $quote->accepted_at = now();
        $quote->save();

        $invoice = app(ConvertQuotationToInvoiceAction::class)->execute($quote, $this->owner);

        $this->assertSame('document', $invoice->discount_scope);
        $this->assertSame('fixed', $invoice->discount_type);
        $this->assertSame('10.000000', (string) $invoice->discount_value);
        $this->assertSame(3, $invoice->currency_minor_units);

        $invLines = $invoice->lines()->orderBy('line_number')->get();
        $this->assertSame('5.000000', (string) $invLines[0]->allocated_document_discount);
        $this->assertSame('5.000000', (string) $invLines[1]->allocated_document_discount);
        $this->assertSame('190.000000', (string) $invoice->grand_total_currency);
    }

    public function test_frozen_precision_preserved_across_0_2_3_6_metadata_changes_and_returns(): void
    {
        // 1. Enable BHD currency (Bahraini Dinar, standard 3 minor units) for company
        $bhd = Currency::where('code', 'BHD')->first();
        if ($bhd === null) {
            $bhd = Currency::create([
                'code' => 'BHD',
                'name' => 'Bahraini Dinar',
                'symbol' => 'BD',
                'minor_units' => 3,
                'active' => true,
            ]);
        } else {
            $bhd->update(['minor_units' => 3, 'active' => true]);
        }
        CompanyCurrency::firstOrCreate(
            ['company_id' => $this->company->id, 'currency_code' => 'BHD'],
            ['enabled' => true, 'is_base' => false]
        );
        app(CurrencyMinorUnitsResolver::class)->flush();

        // 2. Create and post a BHD sales invoice (precision = 3) with document discount
        // 3 items at 10.000 = 30.000 with 0.500 fixed document discount => grand total = 29.500
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'BHD',
            'exchange_rate' => '1.3300000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '0.500',
            'lines' => [
                ['item_description' => 'Service Unit', 'quantity' => '3', 'unit_price' => '10.000'],
            ],
        ]);

        $this->assertSame(3, $draft->currency_minor_units);
        $this->assertSame('29.500000', (string) $draft->grand_total_currency);

        $posted = app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        $this->assertSame(3, $posted->currency_minor_units);

        // 3. Mutate current BHD scale in currencies table to 2 decimal places
        $bhd->update(['minor_units' => 2]);
        app(CurrencyMinorUnitsResolver::class)->flush();
        $this->assertSame(2, app(CurrencyMinorUnitsResolver::class)->resolve('BHD'));

        // 4. Return 1 unit: must preserve frozen precision 3!
        $origLine = $posted->lines->firstOrFail();
        $returnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $posted->id,
            'issue_date' => '2026-11-25',
            'lines' => [
                ['sales_invoice_line_id' => $origLine->id, 'quantity' => '1'],
            ],
        ]);

        $this->assertSame(3, $returnDraft->currency_minor_units);
        $this->assertSame('9.833000', (string) $returnDraft->grand_total_currency);

        $postedReturn = app(PostSalesReturnAction::class)->execute($returnDraft, $this->owner);
        $this->assertSame(3, $postedReturn->currency_minor_units);
        $this->assertSame('9.833000', (string) $postedReturn->grand_total_currency);

        // 5. Return remaining 2 units: must also preserve frozen precision 3!
        $returnDraft2 = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $posted->id,
            'issue_date' => '2026-11-25',
            'lines' => [
                ['sales_invoice_line_id' => $origLine->id, 'quantity' => '2'],
            ],
        ]);

        $this->assertSame(3, $returnDraft2->currency_minor_units);
        $this->assertSame('19.667000', (string) $returnDraft2->grand_total_currency);

        $postedReturn2 = app(PostSalesReturnAction::class)->execute($returnDraft2, $this->owner);
        $this->assertSame(3, $postedReturn2->currency_minor_units);
        $this->assertSame('19.667000', (string) $postedReturn2->grand_total_currency);

        // Verify exact conservation
        $totalReturned = BigDecimal::of((string) $postedReturn->grand_total_currency)
            ->plus((string) $postedReturn2->grand_total_currency);
        $this->assertTrue($totalReturned->isEqualTo(BigDecimal::of('29.500000')));
    }

    public function test_sales_return_meaningful_fixtures_through_canonical_posting_conserves_exact_residuals(): void
    {
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '10.00',
            'lines' => [
                ['item_description' => 'Item 1', 'quantity' => '10', 'unit_price' => '10.00'],
            ],
        ]);

        $posted = app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        $this->assertSame('90.000000', (string) $posted->grand_total_currency);
        $invLine = $posted->lines->firstOrFail();

        // 1. Partial return 4 out of 10
        $retDraft1 = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $posted->id,
            'issue_date' => '2026-11-26',
            'lines' => [
                ['sales_invoice_line_id' => $invLine->id, 'quantity' => '4'],
            ],
        ]);

        $retPosted1 = app(PostSalesReturnAction::class)->execute($retDraft1, $this->owner);
        $this->assertSame('36.000000', (string) $retPosted1->grand_total_currency);

        // 2. Remaining return 6 out of 10
        $retDraft2 = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $posted->id,
            'issue_date' => '2026-11-26',
            'lines' => [
                ['sales_invoice_line_id' => $invLine->id, 'quantity' => '6'],
            ],
        ]);

        $retPosted2 = app(PostSalesReturnAction::class)->execute($retDraft2, $this->owner);
        $this->assertSame('54.000000', (string) $retPosted2->grand_total_currency);

        // Exact conservation: 36.00 + 54.00 = 90.00
        $sum = BigDecimal::of((string) $retPosted1->grand_total_currency)
            ->plus((string) $retPosted2->grand_total_currency);
        $this->assertTrue($sum->isEqualTo(BigDecimal::of('90.000000')));
    }

    public function test_fully_discounted_service_invoice_no_journal_lines_guard(): void
    {
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => '100.00',
            'lines' => [
                ['item_description' => 'Pure Service', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $this->assertSame('0.000000', (string) $draft->grand_total_base);

        // 1. Early preview guard: preview reports blocker
        $commands = app(SalesInvoiceCommands::class);
        $preview = $commands->preview($draft, $this->owner);

        $blockerCodes = array_column($preview['blockers'], 'code');
        $this->assertContains('zero_journal_lines_cannot_post', $blockerCodes);
        $this->assertFalse($preview['can_execute']);

        // 2. Early post guard: posting directly throws InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(__('sales.zero_journal_lines_cannot_post'));

        app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
    }

    public function test_migration_down_refuses_rollback_when_discount_history_exists(): void
    {
        // Create draft with document discount
        app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-11-25',
            'discount_type' => 'percent',
            'discount_value' => '10.00',
            'lines' => [
                ['item_description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00'],
            ],
        ]);

        $migration = require database_path('migrations/2026_11_25_000001_add_document_discount_to_sales_and_quotations.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot rollback migration: sales or quotation document discount history exists.');

        $migration->down();
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
        $this->assertSame('85.000000', (string) $invoice->grand_total_currency);
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
}
