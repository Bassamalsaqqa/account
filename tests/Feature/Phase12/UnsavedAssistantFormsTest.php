<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Application\AI\TransactionAssistant;
use App\Livewire\AI\AssistantLauncher;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Models\AssistantRequest;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Services\Purchasing\VendorCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class UnsavedAssistantFormsTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
    }

    public function test_launcher_opens_the_existing_unsaved_sales_form_without_an_apply_step(): void
    {
        Livewire::test(AssistantLauncher::class)
            ->call('showAssistant')
            ->set('text', 'sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS')
            ->call('prepare')
            ->assertHasNoErrors()
            ->assertRedirect();
        $this->assertSame(0, SalesInvoice::count());
        $this->assertSame(0, DB::table('posting_batches')->count());
        $this->assertSame('form_ready', AssistantRequest::firstOrFail()->status);
        Http::assertNothingSent();
    }

    public function test_sales_form_is_editable_unsaved_and_save_audits_the_human_final_fields(): void
    {
        $before = DB::table('posting_batches')->count();
        $result = $this->handoff('sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS', 'sales');
        $form = Livewire::withQueryParams(['assistant' => $result['token']])->test(InvoiceForm::class)
            ->assertSet('customer_id', $this->customer->id)
            ->assertSet('lines.0.quantity', '5')
            ->assertSet('lines.0.unit_price', '80')
            ->assertSee('Unsaved editable form');
        $this->assertSame(0, SalesInvoice::count());
        $form->set('lines.0.quantity', '7')->call('save', false)->assertRedirect();
        $invoice = SalesInvoice::firstOrFail();
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('7.000000', $invoice->lines()->firstOrFail()->quantity);
        $source = AssistantRequest::firstOrFail();
        $this->assertSame('accepted', $source->status);
        $this->assertSame($invoice->id, $source->sales_invoice_id);
        $this->assertSame('7.000000', $source->approved_command['lines'][0]['quantity']);
        $this->assertSame($before, DB::table('posting_batches')->count());
        $this->assertNull(Cache::get('ai-form-state:'.hash('sha256', $result['token'])));
        Http::assertNothingSent();
    }

    public function test_purchase_form_is_unsaved_and_save_uses_the_idempotent_canonical_draft_facade(): void
    {
        $this->aiProduct->update(['product_type' => 'stock', 'track_stock' => true, 'track_expiry' => false]);
        $this->aiCarton->update(['is_default_purchase' => true]);
        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, ['name_ar' => 'مورد', 'name_en' => 'Supplier']);
        $result = $this->handoff('buy from "Supplier": 2 "carton" "SOAP-3L" at 24 ILS', 'purchase');
        $form = Livewire::withQueryParams(['assistant' => $result['token']])->test(PurchaseForm::class)
            ->assertSet('vendor_id', $vendor->id)
            ->assertSet('lines.0.unit_cost', '24')
            ->assertSee('supplier invoice number');
        $this->assertSame(0, Purchase::count());
        $form->set('vendor_invoice_number', 'SYNTHETIC-12')->set('lines.0.unit_cost', '25')->call('save')->assertRedirect();
        $purchase = Purchase::firstOrFail();
        $this->assertSame('draft', $purchase->status);
        $this->assertSame('50.000000', $purchase->grand_total_currency);
        $this->assertSame(1, DB::table('ai_purchase_draft_receipts')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame('25', AssistantRequest::firstOrFail()->approved_command['lines'][0]['unit_cost']);
        $this->assertSame($purchase->id, AssistantRequest::firstOrFail()->purchase_id);
        Http::assertNothingSent();
    }

    public function test_discard_keeps_manual_fields_but_forgets_assistant_memory(): void
    {
        $result = $this->handoff('sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS', 'sales');
        Livewire::withQueryParams(['assistant' => $result['token']])->test(InvoiceForm::class)
            ->set('notes', 'Manual note retained')
            ->call('discardAiInput')
            ->assertSet('aiFormToken', null)
            ->assertSet('notes', 'Manual note retained')
            ->assertSet('lines.0.quantity', '5');
        $this->assertSame(0, SalesInvoice::count());
        $this->assertNull(Cache::get('ai-form-state:'.hash('sha256', $result['token'])));
    }

    public function test_global_feature_disabled_preserves_the_manual_form_without_assistant_controls(): void
    {
        config(['ai_assistant.enabled' => false]);
        Livewire::test(InvoiceForm::class)->assertDontSee('Create with voice or text')->assertSet('aiFormToken', null);
        Livewire::test(PurchaseForm::class)->assertDontSee('Create with voice or text')->assertSet('aiFormToken', null);
        Http::assertNothingSent();
    }

    public function test_reload_preserves_human_edits_and_obsolete_tab_cannot_save(): void
    {
        $result = $this->handoff('sell to "Ahmed": 5 "carton" "SOAP-3L" at 80 ILS', 'sales');
        $first = Livewire::withQueryParams(['assistant' => $result['token']])->test(InvoiceForm::class)
            ->set('notes', 'Human note before language switch')
            ->set('lines.0.quantity', '8');
        Livewire::withQueryParams(['assistant' => $result['token']])->test(InvoiceForm::class)
            ->assertSet('notes', 'Human note before language switch')
            ->assertSet('lines.0.quantity', '8');
        $first->call('save', false)->assertStatus(409);
        $this->assertSame(0, SalesInvoice::count());
        $this->assertSame(0, DB::table('command_receipts')->count());
        Http::assertNothingSent();
    }

    private function handoff(string $text, string $intent): array
    {
        return app(TransactionAssistant::class)->prepare($this->company, $this->owner, $text, $intent,
            (string) Str::uuid(), (string) Str::uuid(), session()->getId());
    }
}
