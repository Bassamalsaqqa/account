<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Actions\Purchasing\PostPurchaseAction;
use App\Application\AI\TransactionAssistant;
use App\Application\Commands\SalesInvoiceCommands;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Models\AssistantRequest;
use App\Models\InventoryBalance;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\TaxRate;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class FinalAssistantEconomicJourneyTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    public function test_current_unsaved_purchase_and_sales_forms_preserve_canonical_fefo_tax_and_six_reconciliations(): void
    {
        $this->finalAiFixtures();
        Http::preventStrayRequests();
        $this->aiProduct->update(['product_type' => 'stock', 'track_stock' => true, 'track_expiry' => true]);
        $this->aiCarton->update(['is_default_purchase' => true, 'default_purchase_price_base' => '24.000000']);
        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner,
            ['name_ar' => 'مورد الرحلة المعزولة', 'name_en' => 'Supplier']);

        $purchaseInput = $this->prepare('buy from "Supplier": 3 "carton" "SOAP-3L" at 24 ILS', 'purchase');
        $purchaseForm = Livewire::withQueryParams(['assistant' => $purchaseInput['token']])->test(PurchaseForm::class)
            ->assertSet('vendor_id', $vendor->id)
            ->assertSet('lines.0.unit_cost', '24')
            ->assertSet('lines.0.quantity', '3')
            ->assertSee('supplier invoice number');
        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, DB::table('posting_batches')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        // These supplier facts are entered by the human, never fabricated by the assistant.
        $purchaseForm->set('vendor_invoice_number', 'SYNTHETIC-JOURNEY-001')
            ->set('lines.0.lots', [
                ['product_unit_id' => $this->aiCarton->id, 'quantity' => '1', 'lot_number' => 'P12-EARLY', 'expiry_date' => now()->addMonth()->toDateString()],
                ['product_unit_id' => $this->aiCarton->id, 'quantity' => '2', 'lot_number' => 'P12-LATE', 'expiry_date' => now()->addMonths(2)->toDateString()],
            ])->call('save')->assertHasNoErrors()->assertRedirect();
        $purchase = Purchase::sole();
        $this->assertSame('draft', $purchase->status);
        $this->assertSame('72.000000', $purchase->grand_total_currency);
        $this->assertSame('36.000000', $purchase->lines()->sole()->quantity_base);
        $this->assertSame(1, DB::table('ai_purchase_draft_receipts')->count());
        $this->assertSame(0, DB::table('posting_batches')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $purchaseSource = AssistantRequest::where('intent_kind', 'purchase')->sole();
        $this->assertSame('accepted', $purchaseSource->status);
        $this->assertSame($purchase->id, $purchaseSource->purchase_id);
        $purchaseApproved = $purchaseSource->getAttribute('approved_command');
        $this->assertIsArray($purchaseApproved);
        $this->assertSame('SYNTHETIC-JOURNEY-001', $purchaseApproved['vendor_invoice_number']);

        $postedPurchase = app(PostPurchaseAction::class)->execute($purchase, $this->owner);
        $this->assertSame('posted', $postedPurchase->status);
        $this->assertSame('36.000000', (string) InventoryBalance::where('product_id', $this->aiProduct->id)->sum('quantity_base'));
        $vendorSnapshot = $postedPurchase->vendor_snapshot;
        $purchaseHistory = $postedPurchase->lines()->with('lots')->get()->toArray();
        $beforeSale = [DB::table('posting_batches')->count(), DB::table('stock_movements')->count()];

        $tax = TaxRate::create(['company_id' => $this->company->id, 'code' => 'VAT-P12',
            'name_ar' => 'ضريبة الرحلة', 'name_en' => 'Journey tax', 'rate' => '16', 'calculation' => 'exclusive', 'active' => true]);
        $salesInput = $this->prepare('sell to "Ahmed": 2 "carton" "SOAP-3L" at 80 ILS', 'sales');
        Livewire::withQueryParams(['assistant' => $salesInput['token']])->test(InvoiceForm::class)
            ->assertSet('lines.0.quantity', '2')
            ->set('lines.0.discount_type', 'percent')->set('lines.0.discount_value', '10')
            ->set('lines.0.tax_rate_id', $tax->id)->call('save', false)->assertHasNoErrors()->assertRedirect();
        $invoice = SalesInvoice::sole();
        $this->assertTrue($invoice->isDraft());
        $this->assertSame('160.000000', $invoice->subtotal_currency);
        $this->assertSame('16.000000', $invoice->discount_total_currency);
        $this->assertSame('23.040000', $invoice->tax_total_currency);
        $this->assertSame('167.040000', $invoice->grand_total_currency);
        $this->assertSame($beforeSale, [DB::table('posting_batches')->count(), DB::table('stock_movements')->count()]);
        $salesSource = AssistantRequest::where('intent_kind', 'sales')->sole();
        $this->assertSame('accepted', $salesSource->status);
        $this->assertSame($invoice->id, $salesSource->sales_invoice_id);
        $salesApproved = $salesSource->getAttribute('approved_command');
        $this->assertIsArray($salesApproved);
        $this->assertSame('10.000000', $salesApproved['lines'][0]['discount_value']);

        $commands = app(SalesInvoiceCommands::class);
        $preview = $commands->preview($invoice, $this->owner);
        $this->assertTrue($preview['can_execute']);
        $this->assertSame($beforeSale, [DB::table('posting_batches')->count(), DB::table('stock_movements')->count()]);
        $executionKey = (string) Str::uuid();
        $posted = $commands->execute($invoice, $this->owner, $preview['confirmation_token'], $executionKey);
        $this->assertTrue($posted->isPosted());
        $this->assertSame('12.000000', (string) InventoryBalance::where('product_id', $this->aiProduct->id)->sum('quantity_base'));
        $this->assertSame(['P12-EARLY', 'P12-LATE'], $posted->lotAllocations()->with('lot')->get()
            ->map(fn ($allocation) => $allocation->lot->lot_number)->all());
        $afterPost = [DB::table('posting_batches')->count(), DB::table('stock_movements')->count()];
        $replay = $commands->execute($invoice, $this->owner, $preview['confirmation_token'], $executionKey);
        $this->assertSame($posted->id, $replay->id);
        $this->assertSame($afterPost, [DB::table('posting_batches')->count(), DB::table('stock_movements')->count()]);

        $customerSnapshot = $posted->customer_snapshot;
        $salesHistory = $posted->lines()->get()->toArray();
        $this->customer->update(['name_en' => 'Changed after posting']);
        $vendor->update(['name_en' => 'Changed supplier after posting']);
        $this->aiCarton->update(['default_sale_price_base' => '999', 'default_purchase_price_base' => '999']);
        $tax->update(['rate' => '17']);
        $this->assertSame($customerSnapshot, $posted->fresh()->customer_snapshot);
        $this->assertSame($vendorSnapshot, $postedPurchase->fresh()->vendor_snapshot);
        $this->assertSame($salesHistory, $posted->fresh()->lines()->get()->toArray());
        $this->assertSame($purchaseHistory, $postedPurchase->fresh()->lines()->with('lots')->get()->toArray());
        foreach (['accounting:reconcile', 'inventory:reconcile', 'sales:reconcile', 'money:reconcile', 'phase7:reconcile'] as $command) {
            $this->assertSame(0, Artisan::call($command, ['companyPublicId' => $this->company->public_id]), Artisan::output());
        }
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function prepare(string $text, string $intent): array
    {
        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner, $text, $intent,
            (string) Str::uuid(), (string) Str::uuid(), session()->getId());
        $this->assertSame('handoff', $result['state']);

        return $result;
    }
}
