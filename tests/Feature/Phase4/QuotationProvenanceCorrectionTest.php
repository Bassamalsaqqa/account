<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\ConvertQuotationToInvoiceAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\UpdateQuotationAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Livewire\Pages\Sales\QuotationDetail;
use App\Models\CompanyDocumentSettings;
use App\Models\Customer;
use App\Models\ProductImage;
use App\Models\SalesInvoice;
use App\Models\Unit;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Sales\DocumentDataBuilder;
use App\Services\Sales\PdfRendererService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class QuotationProvenanceCorrectionTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    public function test_only_accepted_converts_once_and_provenance_is_immutable(): void
    {
        foreach (['draft', 'sent', 'rejected', 'expired'] as $status) {
            $quote = $this->quote();
            if ($status !== 'draft') {
                $quote->transition('sent', $this->owner);
            }
            if (in_array($status, ['rejected', 'expired'], true)) {
                $quote->transition($status, $this->owner);
            }
            Livewire::test(QuotationDetail::class, ['publicId' => $quote->public_id])->assertViewHas('canConvert', false);
            try {
                app(ConvertQuotationToInvoiceAction::class)->execute($quote, $this->owner);
                $this->fail('Nonaccepted conversion succeeded.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame(0, SalesInvoice::count());
            }
            try {
                $quote->transition('converted', $this->owner);
                $this->fail('Invalid model conversion succeeded.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($status, $quote->fresh()->status);
            }
        }
        $quote = $this->quote();
        $quote->transition('sent', $this->owner);
        $sent = $quote->sent_at;
        $this->travel(1)->hours();
        $quote->transition('draft', $this->owner);
        $quote->transition('sent', $this->owner);
        $this->assertTrue($sent->equalTo($quote->sent_at));
        $quote->transition('accepted', $this->owner);
        $this->assertNotNull($quote->accepted_at);
        Livewire::test(QuotationDetail::class, ['publicId' => $quote->public_id])->assertViewHas('canConvert', true);
        $invoice = app(ConvertQuotationToInvoiceAction::class)->execute($quote, $this->owner);
        $same = app(ConvertQuotationToInvoiceAction::class)->execute($quote, $this->owner);
        $this->assertSame($invoice->id, $same->id);
        $this->assertNotNull($quote->fresh()->converted_at);
        $this->assertSame($invoice->id, $quote->fresh()->converted_to_invoice_id);
        foreach ([fn () => $quote->update(['include_product_images' => true]), fn () => $invoice->update(['quotation_id' => null]), fn () => SalesInvoice::create(array_replace($invoice->fresh()->getAttributes(), ['public_id' => (string) Str::ulid()]))] as $mutation) {
            try {
                $mutation();
                $this->fail('Provenance mutation succeeded.');
            } catch (ImmutableRecordException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_standalone_cannot_forge_quote_and_reconciliation_detects_raw_link_corruption(): void
    {
        $quote = $this->quote();
        try {
            app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, ['customer_id' => $this->customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1', 'issue_date' => '2026-10-02', 'quotation_id' => $quote->id, 'lines' => [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '1']]]);
            $this->fail('Forged quotation link accepted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(0, SalesInvoice::count());
        }
        $quote->transition('sent', $this->owner);
        $quote->transition('accepted', $this->owner);
        $invoice = app(ConvertQuotationToInvoiceAction::class)->execute($quote, $this->owner);
        $before = DB::table('quotations')->where('id', $quote->id)->first();
        DB::table('quotations')->where('id', $quote->id)->update(['converted_to_invoice_id' => null]);
        $report = app(SalesReconciliationService::class)->reconcile($this->company);
        $this->assertFalse($report->isHealthy);
        $this->assertStringContainsString('reciprocal', implode(';', $report->violations));
        $this->assertNull(DB::table('quotations')->where('id', $quote->id)->value('converted_to_invoice_id'));
        DB::table('quotations')->where('id', $quote->id)->update(['converted_to_invoice_id' => $before->converted_to_invoice_id]);
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_quote_image_option_snapshots_company_default_and_safe_rendering(): void
    {
        Storage::fake('public');
        CompanyDocumentSettings::where('company_id', $this->company->id)->update(['show_product_images_on_quotes' => true]);
        $this->company->unsetRelation('documentSettings');
        $product = app(ProductCatalogService::class)->createProduct($this->company, ['name_ar' => 'منتج', 'name_en' => 'Product', 'base_unit_id' => Unit::where('code', 'piece')->value('id'), 'product_type' => 'non_stock', 'track_stock' => false, 'track_expiry' => false, 'active' => true], $this->owner->id);
        $quote = $this->quote(['lines' => [['product_id' => $product->id, 'item_description' => 'Product', 'quantity' => '1', 'unit_price' => '100']]]);
        $this->assertTrue($quote->include_product_images);
        CompanyDocumentSettings::where('company_id', $this->company->id)->update(['show_product_images_on_quotes' => false]);
        $this->assertTrue($quote->fresh()->include_product_images);
        $missing = app(DocumentDataBuilder::class)->build($quote);
        $this->assertArrayNotHasKey('image', $missing->lines[0]);
        $img = imagecreatetruecolor(30, 30);
        ob_start();
        imagewebp($img);
        $bytes = ob_get_clean();
        imagedestroy($img);
        $path = "products/{$this->company->id}/{$product->id}/approved.webp";
        Storage::disk('public')->put($path, $bytes);
        ProductImage::create(['company_id' => $this->company->id, 'product_id' => $product->id, 'disk' => 'public', 'path' => $path, 'mime_type' => 'image/webp', 'is_primary' => true, 'file_size' => strlen($bytes), 'created_by' => $this->owner->id]);
        $public = app(DocumentDataBuilder::class)->build($quote);
        $pdf = app(DocumentDataBuilder::class)->build($quote, forPdf: true);
        $this->assertStringContainsString('/storage/products/', $public->lines[0]['image']);
        $this->assertStringStartsWith('data:image/webp;base64,', $pdf->lines[0]['image']);
        $this->assertStringNotContainsString('storage/app', json_encode($public->toArray()));
        $pdfBytes = app(PdfRendererService::class)->renderQuotation($quote);
        $this->assertStringStartsWith('%PDF-', $pdfBytes);
        app(UpdateQuotationAction::class)->execute($quote, $this->owner, ['include_product_images' => false]);
        $this->assertArrayNotHasKey('image', app(DocumentDataBuilder::class)->build($quote->fresh())->lines[0]);
        $this->assertArrayNotHasKey('image', app(DocumentDataBuilder::class)->build($quote->fresh(), forPdf: true)->lines[0]);
    }

    public function test_linked_draft_rejects_wrong_company_customer_and_duplicate_quote_at_database_boundary(): void
    {
        $quote = $this->quote();
        $quote->transition('sent', $this->owner);
        $quote->transition('accepted', $this->owner);
        $standalone = $this->invoice();
        $attributes = $standalone->getAttributes();
        $attributes['status'] = 'draft';
        $attributes['posting_batch_id'] = null;
        $attributes['quotation_id'] = $quote->id;
        foreach (['company_id', 'customer_id'] as $field) {
            try {
                DB::transaction(fn () => SalesInvoice::createFromAcceptedQuotation($quote, $this->owner, array_replace($attributes, [$field => 999999])));
                $this->fail('Mismatched quotation provenance accepted.');
            } catch (ImmutableRecordException $exception) {
                $this->assertSame(1, SalesInvoice::count());
            }
        }
        $linked = app(ConvertQuotationToInvoiceAction::class)->execute($quote, $this->owner);
        $duplicate = $linked->getAttributes();
        unset($duplicate['id']);
        $duplicate['public_id'] = (string) Str::ulid();
        try {
            DB::table('sales_invoices')->insert($duplicate);
            $this->fail('Duplicate quotation link accepted.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Duplicate', $exception->getMessage());
        }
    }

    public function test_reconciliation_uses_raw_links_and_reports_customer_company_and_state_corruption(): void
    {
        $quote = $this->quote();
        $quote->transition('sent', $this->owner);
        $quote->transition('accepted', $this->owner);
        $invoice = app(ConvertQuotationToInvoiceAction::class)->execute($quote, $this->owner);
        $context = app(CompanyContext::class);
        $context->clear();
        $foreign = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Foreign company', 'base_currency_code' => 'ILS']);
        $context->setCompany($this->company, $this->owner);
        foreach (['customer_id' => 999999, 'company_id' => $foreign->id, 'status' => 'accepted'] as $field => $value) {
            $original = DB::table('quotations')->where('id', $quote->id)->value($field);
            if ($field === 'customer_id') {
                $value = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'Other customer', 'created_by' => $this->owner->id])->id;
            }
            DB::table('quotations')->where('id', $quote->id)->update([$field => $value]);
            $report = app(SalesReconciliationService::class)->reconcile($this->company);
            $this->assertFalse($report->isHealthy);
            $this->assertStringContainsString('provenance', implode(';', $report->violations));
            $this->assertEquals($value, DB::table('quotations')->where('id', $quote->id)->value($field));
            DB::table('quotations')->where('id', $quote->id)->update([$field => $original]);
        }
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['company_id' => $foreign->id]);
        $report = app(SalesReconciliationService::class)->reconcile($this->company);
        $this->assertFalse($report->isHealthy);
        $this->assertStringContainsString('linked invoice company/customer provenance', implode(';', $report->violations));
    }
}
