<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\ConvertQuotationToInvoiceAction;
use App\Actions\Sales\CreateQuotationAction;
use App\Livewire\Pages\Sales\QuotationDetail;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Livewire\Pages\Sales\QuotationIndex;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QuotationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $companyA;

    protected Company $companyB;

    protected Customer $customerA;

    protected Warehouse $warehouseA;

    protected Product $product;

    protected ProductUnit $productUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);

        $this->companyA = $creator->execute($this->user, [
            'name_ar' => 'شركة عروض الأسعار أ',
            'base_currency_code' => 'ILS',
        ]);

        $this->companyB = $creator->execute($this->user, [
            'name_ar' => 'شركة عروض الأسعار ب',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->companyA, $this->user);
        $this->actingAs($this->user);

        $this->customerA = Customer::create([
            'company_id' => $this->companyA->id,
            'name_ar' => 'عميل العروض أ',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->warehouseA = Warehouse::where('company_id', $this->companyA->id)->where('is_default', true)->firstOrFail();
        $unitPiece = Unit::where('company_id', $this->companyA->id)->where('code', 'piece')->firstOrFail();

        $this->product = Product::create([
            'company_id' => $this->companyA->id,
            'name_ar' => 'منتج تجريبي للعرض',
            'sku' => 'QUOTE-PROD-01',
            'base_unit_id' => $unitPiece->id,
            'product_type' => 'stock',
            'track_stock' => true,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->productUnit = ProductUnit::create([
            'company_id' => $this->companyA->id,
            'product_id' => $this->product->id,
            'unit_id' => $unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'active' => true,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_creating_quotation_allocates_sequence_number_and_is_side_effect_free(): void
    {
        $createAction = app(CreateQuotationAction::class);

        $data = [
            'customer_id' => $this->customerA->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'expiry_date' => Carbon::now()->addDays(14)->toDateString(),
            'notes' => 'عرض تجريبي خاص',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->productUnit->id,
                    'item_description' => 'منتج تجريبي للعرض',
                    'quantity' => '2.000000',
                    'unit_price' => '150.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ];

        $quotation = $createAction->execute($this->companyA, $this->user, $data);

        // Sequence number allocated on save
        $this->assertNotNull($quotation->quotation_number);
        $this->assertStringContainsString('QTN-', $quotation->quotation_number);

        // Totals calculated deterministically
        $this->assertSame('300.000000', $quotation->subtotal);
        $this->assertSame('0.000000', $quotation->tax_total);
        $this->assertSame('300.000000', $quotation->grand_total);
        $this->assertSame(Quotation::STATUS_DRAFT, $quotation->status);

        // Assert strictly side-effect free:
        // No stock movements
        $movements = StockMovement::where('company_id', $this->companyA->id)->count();
        $this->assertSame(0, $movements);

        // No invoices created yet
        $invoices = SalesInvoice::where('company_id', $this->companyA->id)->count();
        $this->assertSame(0, $invoices);
    }

    public function test_quotation_status_lifecycle_and_conversion_to_invoice(): void
    {
        $createAction = app(CreateQuotationAction::class);

        $data = [
            'customer_id' => $this->customerA->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->productUnit->id,
                    'item_description' => 'منتج تجريبي للتحويل',
                    'quantity' => '3.000000',
                    'unit_price' => '100.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ];

        $quotation = $createAction->execute($this->companyA, $this->user, $data);

        // 1. Mark as sent
        Livewire::test(QuotationDetail::class, ['publicId' => $quotation->public_id])
            ->call('markAsSent');

        $quotation->refresh();
        $this->assertSame(Quotation::STATUS_SENT, $quotation->status);

        // 2. Mark as accepted
        Livewire::test(QuotationDetail::class, ['publicId' => $quotation->public_id])
            ->call('markAsAccepted');

        $quotation->refresh();
        $this->assertSame(Quotation::STATUS_ACCEPTED, $quotation->status);

        // 3. Convert quotation to invoice draft
        $convertAction = app(ConvertQuotationToInvoiceAction::class);
        $invoice = $convertAction->execute($quotation, $this->user);

        // Verify quote state
        $quotation->refresh();
        $this->assertSame(Quotation::STATUS_CONVERTED, $quotation->status);
        $this->assertSame($invoice->id, $quotation->converted_to_invoice_id);

        // Verify invoice draft
        $this->assertSame(SalesInvoice::STATUS_DRAFT, $invoice->status);
        $this->assertNull($invoice->invoice_number); // Draft invoice has no sequence number
        $this->assertSame($quotation->id, $invoice->quotation_id);
        $this->assertSame($this->customerA->id, $invoice->customer_id);
        $this->assertSame('300.000000', $invoice->grand_total);
        $this->assertCount(1, $invoice->lines);

        // Converting again returns the exact same invoice (idempotent retry)
        $secondInvoice = $convertAction->execute($quotation, $this->user);
        $this->assertSame($invoice->id, $secondInvoice->id);
        $this->assertSame(1, SalesInvoice::where('quotation_id', $quotation->id)->count());
    }

    public function test_tenant_isolation_on_quotation(): void
    {
        $createAction = app(CreateQuotationAction::class);

        $data = [
            'customer_id' => $this->customerA->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'lines' => [
                [
                    'product_id' => null,
                    'product_unit_id' => null,
                    'item_description' => 'خدمة استشارية',
                    'quantity' => '1.000000',
                    'unit_price' => '500.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ];

        $quotationA = $createAction->execute($this->companyA, $this->user, $data);

        // Switch to Company B
        app(CompanyContext::class)->setCompany($this->companyB, $this->user);

        // Should not see Quotation A in Company B index
        Livewire::test(QuotationIndex::class)
            ->assertDontSee($quotationA->quotation_number);

        // Cannot view or edit
        Livewire::test(QuotationDetail::class, ['publicId' => $quotationA->public_id])
            ->assertStatus(404);

        Livewire::test(QuotationForm::class, ['publicId' => $quotationA->public_id])
            ->assertStatus(404);
    }
}
