<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Application\AI\AiFormCorrections;
use App\Application\AI\AiFormEditing;
use App\Application\AI\AiFormState;
use App\Application\AI\AssistantUnavailable;
use App\Application\AI\TransactionAssistant;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Models\AssistantRequest;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\TaxRate;
use App\Models\Vendor;
use App\Services\Purchasing\VendorCatalogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Phase12\FinalAiFixtures;
use Tests\TestCase;

class PurchaseFormCorrectionsTest extends TestCase
{
    use FinalAiFixtures;
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finalAiFixtures();
        Http::preventStrayRequests();
        config(['ai_assistant.provider_ipv4' => ['deepseek' => ['global' => '8.8.8.8'], 'openai' => ['global' => '8.8.8.8']]]);
    }

    public function test_exact_unit_cost_and_quantity_correction_preserves_human_fields_and_undoes_without_database_writes(): void
    {
        [$token, $state] = $this->editingPurchase();
        $payload = $state['data']['payload'];
        $payload['notes'] = 'Human purchase note';
        $payload['lines'][0]['unit_cost'] = '24.500000';

        $resultQty = $this->apply($token, $state, $payload, 'غير الكمية من اثنين لستة');
        $this->assertSame('6', $resultQty['payload']['lines'][0]['quantity']);
        $this->assertSame('24.500000', $resultQty['payload']['lines'][0]['unit_cost']);
        $this->assertSame('Human purchase note', $resultQty['payload']['notes']);
        $this->assertSame(['lines.0.quantity'], $resultQty['changed_fields']);
        $this->assertTrue($resultQty['can_undo']);

        $freshState = app(AiFormState::class)->read($this->company, $this->owner, $token, 'session');
        $resultCost = $this->apply($token, $freshState, $resultQty['payload'], 'change cost to 28.500000');
        $this->assertSame('6', $resultCost['payload']['lines'][0]['quantity']);
        $this->assertSame('28.500000', $resultCost['payload']['lines'][0]['unit_cost']);
        $this->assertSame('Human purchase note', $resultCost['payload']['notes']);
        $this->assertSame(['lines.0.unit_cost'], $resultCost['changed_fields']);
        $this->assertTrue($resultCost['can_undo']);

        $undo = app(AiFormCorrections::class)->undo($this->company, $this->owner, $token, 'session',
            $freshState['form_key'], $resultCost['revision'], $resultCost['payload']);
        $this->assertSame($resultQty['payload'], $undo['payload']);
        $this->assertFalse($undo['can_undo']);

        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, DB::table('posting_batches')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('ai_purchase_draft_receipts')->count());
        Http::assertNothingSent();
    }

    public function test_purchase_patch_rejects_sales_price_field_with_intent_mismatch(): void
    {
        [$token, $state] = $this->editingPurchase();
        try {
            $this->apply($token, $state, $state['data']['payload'], 'change price to 30');
            $this->fail('unit_price patch on purchase form must fail with intent_mismatch.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('intent_mismatch', $e->reason);
        }

        $fresh = app(AiFormState::class)->read($this->company, $this->owner, $token, 'session');
        $this->assertSame('24', $fresh['data']['payload']['lines'][0]['unit_cost']);
        $request = AssistantRequest::where('purpose', 'patch')->firstOrFail();
        $this->assertSame('failed', $request->status);
        $this->assertSame('intent_mismatch', $request->failure_code);
        $this->assertSame(0, Purchase::count());
        Http::assertNothingSent();
    }

    public function test_add_and_remove_line_patches_operate_on_canonical_purchase_lines(): void
    {
        [$token, $state] = $this->editingPurchase();
        $text = 'Please add 3 carton SOAP-3L at 22';
        $proposal = [
            'version' => 1,
            'operations' => [[
                'op' => 'add_line',
                'line' => null,
                'field' => null,
                'value' => '3',
                'product' => 'SOAP-3L',
                'unit' => 'carton',
                'amount' => '22',
                'quote' => 'add 3 carton SOAP-3L at 22',
            ]],
        ];
        Http::fake(['*' => Http::response($this->responsePayload($proposal))]);

        $added = $this->apply($token, $state, $state['data']['payload'], $text);
        $this->assertCount(2, $added['payload']['lines']);
        $this->assertSame(['lines'], $added['changed_fields']);
        $this->assertSame($this->aiProduct->id, $added['payload']['lines'][1]['product_id']);
        $this->assertSame($this->aiCarton->id, $added['payload']['lines'][1]['product_unit_id']);
        $this->assertSame('3', $added['payload']['lines'][1]['quantity']);
        $this->assertSame('22', $added['payload']['lines'][1]['unit_cost']);

        $freshState = app(AiFormState::class)->read($this->company, $this->owner, $token, 'session');
        $removed = $this->apply($token, $freshState, $added['payload'], 'remove line 1');
        $this->assertCount(1, $removed['payload']['lines']);
        $this->assertSame('3', $removed['payload']['lines'][0]['quantity']);
        $this->assertSame('22', $removed['payload']['lines'][0]['unit_cost']);
        $this->assertSame(['lines'], $removed['changed_fields']);

        $singleState = app(AiFormState::class)->read($this->company, $this->owner, $token, 'session');
        try {
            $this->apply($token, $singleState, $removed['payload'], 'remove line 1');
            $this->fail('Removing the only line must be refused.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('choice_invalid', $e->reason);
        }

        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_unit_conversion_patch_preserves_authoritative_product_identity_and_cost(): void
    {
        [$token, $state] = $this->editingPurchase();
        $text = 'Please change unit to piece';
        $proposal = [
            'version' => 1,
            'operations' => [[
                'op' => 'set',
                'line' => 1,
                'field' => 'unit',
                'value' => 'piece',
                'product' => null,
                'unit' => null,
                'amount' => null,
                'quote' => 'change unit to piece',
            ]],
        ];

        $invalidText = 'Please change unit to liter';
        $invalidProposal = [
            'version' => 1,
            'operations' => [[
                'op' => 'set',
                'line' => 1,
                'field' => 'unit',
                'value' => 'liter',
                'product' => null,
                'unit' => null,
                'amount' => null,
                'quote' => 'change unit to liter',
            ]],
        ];

        Http::fakeSequence()
            ->push($this->responsePayload($proposal))
            ->push($this->responsePayload($invalidProposal));

        $result = $this->apply($token, $state, $state['data']['payload'], $text);
        $baseUnit = $this->aiProduct->baseUnit;
        $this->assertNotNull($baseUnit);
        $baseProductUnit = $this->aiProduct->productUnits()->where('unit_id', $baseUnit->id)->firstOrFail();

        $this->assertSame($baseProductUnit->id, $result['payload']['lines'][0]['product_unit_id']);
        $this->assertSame($this->aiProduct->id, $result['payload']['lines'][0]['product_id']);
        $this->assertSame('24', $result['payload']['lines'][0]['unit_cost']);
        $this->assertSame('2', $result['payload']['lines'][0]['quantity']);
        $this->assertSame('12.000000', $this->aiCarton->conversion_to_base);
        $this->assertSame('1.000000', $baseProductUnit->conversion_to_base);
        $this->assertSame(['lines.0.product_unit_id'], $result['changed_fields']);

        $freshState = app(AiFormState::class)->read($this->company, $this->owner, $token, 'session');
        try {
            $this->apply($token, $freshState, $result['payload'], $invalidText);
            $this->fail('Unattached product unit must refuse with choice_invalid.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('choice_invalid', $e->reason);
        }

        $this->assertSame(0, Purchase::count());
    }

    public function test_purchase_tax_discount_and_foreign_currency_fx_patches(): void
    {
        [$token, $state] = $this->editingPurchase();
        $tax = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT-16',
            'name_ar' => 'ضريبة القيمة المضافة',
            'name_en' => 'VAT',
            'rate' => '16.0000',
            'calculation' => TaxRate::CALC_EXCLUSIVE,
            'active' => true,
        ]);

        $taxText = 'Please apply VAT';
        $taxProposal = [
            'version' => 1,
            'operations' => [[
                'op' => 'set',
                'line' => 1,
                'field' => 'tax',
                'value' => 'VAT',
                'product' => null,
                'unit' => null,
                'amount' => null,
                'quote' => 'apply VAT',
            ]],
        ];

        $fxText = 'Please set exchange rate to 3.6500000000';
        $fxProposal = [
            'version' => 1,
            'operations' => [[
                'op' => 'set',
                'line' => null,
                'field' => 'exchange_rate',
                'value' => '3.6500000000',
                'product' => null,
                'unit' => null,
                'amount' => null,
                'quote' => 'set exchange rate to 3.6500000000',
            ]],
        ];

        Http::fakeSequence()
            ->push($this->responsePayload($taxProposal))
            ->push($this->responsePayload($fxProposal));

        $taxResult = $this->apply($token, $state, $state['data']['payload'], $taxText);
        $this->assertSame($tax->id, $taxResult['payload']['lines'][0]['tax_rate_id']);
        $this->assertSame(['lines.0.tax_rate_id'], $taxResult['changed_fields']);

        $freshState1 = app(AiFormState::class)->read($this->company, $this->owner, $token, 'session');
        $discountPercentResult = $this->apply($token, $freshState1, $taxResult['payload'], 'change discount to 10%');
        $this->assertSame('percent', $discountPercentResult['payload']['lines'][0]['discount_type']);
        $this->assertSame('10', $discountPercentResult['payload']['lines'][0]['discount_value']);
        $this->assertSame(['lines.0.discount_value'], $discountPercentResult['changed_fields']);

        $freshState2 = app(AiFormState::class)->read($this->company, $this->owner, $token, 'session');
        $discountFixedResult = $this->apply($token, $freshState2, $discountPercentResult['payload'], 'change discount to 5');
        $this->assertSame('fixed', $discountFixedResult['payload']['lines'][0]['discount_type']);
        $this->assertSame('5', $discountFixedResult['payload']['lines'][0]['discount_value']);

        $freshState3 = app(AiFormState::class)->read($this->company, $this->owner, $token, 'session');
        $foreignPayload = $discountFixedResult['payload'];
        $foreignPayload['currency_code'] = 'USD'; // The human selects a foreign currency; ILS must retain rate one.
        $fxResult = $this->apply($token, $freshState3, $foreignPayload, $fxText);
        $this->assertSame('USD', $fxResult['payload']['currency_code']);
        $this->assertSame('3.6500000000', $fxResult['payload']['exchange_rate']);
        $this->assertSame(['exchange_rate'], $fxResult['changed_fields']);

        $this->assertSame(0, Purchase::count());
    }

    public function test_missing_vendor_invoice_and_expiry_lot_fields_remain_unresolved_and_reject_hallucinations(): void
    {
        [$token, $state] = $this->editingPurchase(trackExpiry: true);
        $unresolved = $state['data']['resolved']['unresolved'];
        $this->assertContains('vendor_invoice_number', $unresolved);
        $this->assertContains('lines.0.lots', $unresolved);
        $this->assertNull($state['data']['payload']['vendor_invoice_number']);
        $this->assertSame([], $state['data']['payload']['lines'][0]['lots']);

        $costResult = $this->apply($token, $state, $state['data']['payload'], 'change cost to 28');
        $this->assertSame('28', $costResult['payload']['lines'][0]['unit_cost']);
        $this->assertNull($costResult['payload']['vendor_invoice_number']);
        $this->assertSame([], $costResult['payload']['lines'][0]['lots']);

        $freshState = app(AiFormState::class)->read($this->company, $this->owner, $token, 'session');
        $this->assertContains('vendor_invoice_number', $freshState['data']['resolved']['unresolved']);
        $this->assertContains('lines.0.lots', $freshState['data']['resolved']['unresolved']);

        $hallucinatedLotProposal = [
            'version' => 1,
            'operations' => [[
                'op' => 'set',
                'line' => 1,
                'field' => 'lots',
                'value' => 'LOT-2026-X',
                'product' => null,
                'unit' => null,
                'amount' => null,
                'quote' => 'set lot to LOT-2026-X',
            ]],
        ];

        $hallucinatedInvoiceProposal = [
            'version' => 1,
            'operations' => [[
                'op' => 'set',
                'line' => null,
                'field' => 'vendor_invoice_number',
                'value' => 'HALLUCINATED-INV',
                'product' => null,
                'unit' => null,
                'amount' => null,
                'quote' => 'change invoice number',
            ]],
        ];

        Http::fakeSequence()
            ->push($this->responsePayload($hallucinatedLotProposal))
            ->push($this->responsePayload($hallucinatedInvoiceProposal));

        try {
            $this->apply($token, $freshState, $costResult['payload'], 'set lot to LOT-2026-X');
            $this->fail('Unsupported lot fields must be rejected by patch schema.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->reason);
        }

        try {
            $this->apply($token, $freshState, $costResult['payload'], 'change invoice number');
            $this->fail('Vendor invoice number without evidence in quote must be rejected.');
        } catch (AssistantUnavailable $e) {
            $this->assertSame('provider_invalid', $e->reason);
        }

        $this->assertSame(0, Purchase::count());
    }

    public function test_purchase_corrections_require_cost_view_and_fresh_domain_permissions(): void
    {
        [$token, $state] = $this->editingPurchase();
        $ownerRole = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();

        $ownerRole->revokePermissionTo('purchasing.cost.view');
        try {
            app(AiFormCorrections::class)->prepare($this->company, $this->owner, $token, 'session',
                $state['form_key'], $state['revision'], $state['data']['payload'],
                'change cost to 30', (string) Str::uuid());
            $this->fail('Purchase correction prepare must require purchasing.cost.view.');
        } catch (AuthorizationException) {
            $this->assertSame(0, AssistantRequest::where('purpose', 'patch')->count());
        }

        $ownerRole->givePermissionTo('purchasing.cost.view');
        $service = app(AiFormCorrections::class);
        $prepared = $service->prepare($this->company, $this->owner, $token, 'session',
            $state['form_key'], $state['revision'], $state['data']['payload'],
            'change cost to 30', (string) Str::uuid());
        $this->assertNotSame('', $prepared);

        $ownerRole->revokePermissionTo('purchasing.cost.view');
        try {
            $service->commit($this->company, $this->owner, $token, 'session',
                $state['form_key'], $prepared, $state['data']['payload']);
            $this->fail('Purchase correction commit must recheck purchasing.cost.view at acknowledgement.');
        } catch (AuthorizationException) {
            $this->assertSame('pending', AssistantRequest::where('purpose', 'patch')->firstOrFail()->status);
        }

        $ownerRole->givePermissionTo('purchasing.cost.view');
        $this->assertSame(0, Purchase::count());
        Http::assertNothingSent();
    }

    public function test_livewire_purchase_form_lifecycle_highlights_refuses_overwriting_manual_edits_and_saves_explicitly(): void
    {
        $this->aiProduct->update([
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'default_purchase_cost_base' => '2.000000',
        ]);
        $this->aiCarton->update([
            'is_default_purchase' => true,
            'default_purchase_price_base' => '24.000000',
        ]);
        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد التوريد',
            'name_en' => 'Test Supplier',
            'code' => 'V-SUPP-LIVE',
        ]);

        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner,
            'buy from "Test Supplier": 2 "carton" "SOAP-3L" at 24 ILS', 'purchase',
            (string) Str::uuid(), (string) Str::uuid(), session()->getId());

        $form = Livewire::withQueryParams(['assistant' => $result['token']])->test(PurchaseForm::class)
            ->assertSet('vendor_id', $vendor->id)
            ->assertSet('lines.0.quantity', '2')
            ->assertSet('lines.0.unit_cost', '24')
            ->assertSet('aiUnresolvedFields', ['vendor_invoice_number'])
            ->set('notes', 'Supplier note from phone call')
            ->set('aiCorrectionText', 'change cost to 30')
            ->call('correctAiInput')
            ->assertSet('lines.0.unit_cost', '24');

        $effects = $form->__get('effects');
        $prepared = $effects['returns'][0];
        $this->assertIsString($prepared);

        $form->call('applyPreparedAiInput', $prepared)
            ->assertSet('lines.0.unit_cost', '30')
            ->assertSet('notes', 'Supplier note from phone call')
            ->assertSet('aiChangedFields', ['lines.0.unit_cost'])
            ->assertSet('aiCanUndo', true)
            ->assertSet('totals.total', '60.000000')
            ->call('undoAiInput')
            ->assertSet('lines.0.unit_cost', '24')
            ->assertSet('notes', 'Supplier note from phone call')
            ->assertSet('aiCanUndo', false)
            ->assertSet('totals.total', '48.000000');

        $form->set('aiCorrectionText', 'change cost to 30')->call('correctAiInput');
        $effects2 = $form->__get('effects');
        $prepared2 = $effects2['returns'][0];
        $form->call('applyPreparedAiInput', $prepared2)
            ->assertSet('lines.0.unit_cost', '30');

        $form->set('lines.0.unit_cost', '35');
        $form->call('undoAiInput')
            ->assertSet('lines.0.unit_cost', '35')
            ->assertSet('aiCorrectionError', __('assistant_v2.error_form_changed'));

        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, DB::table('posting_batches')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('ai_purchase_draft_receipts')->count());

        $form->set('vendor_invoice_number', 'INV-PUR-001')
            ->call('save')
            ->assertRedirect();

        $this->assertSame(1, Purchase::count());
        $purchase = Purchase::firstOrFail();
        $this->assertSame('draft', $purchase->status);
        $this->assertSame('70.000000', $purchase->grand_total_currency);
        $this->assertSame('INV-PUR-001', $purchase->vendor_invoice_number);
        $this->assertSame(1, DB::table('ai_purchase_draft_receipts')->count());
        $this->assertSame('accepted', AssistantRequest::where('purpose', 'intent')->firstOrFail()->status);
        $this->assertSame(0, DB::table('posting_batches')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        Http::assertNothingSent();
    }

    public function test_normal_purchase_form_save_without_ai_creates_draft_without_ai_records(): void
    {
        config(['ai_assistant.enabled' => false, 'ai_assistant.provider_approved' => false, 'ai_assistant.speech_enabled' => false]);
        $this->aiProduct->update([
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'default_purchase_cost_base' => '2.000000',
        ]);
        $this->aiCarton->update([
            'is_default_purchase' => true,
            'default_purchase_price_base' => '24.000000',
        ]);
        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد عادي',
            'name_en' => 'Normal Supplier',
            'code' => 'V-NORMAL',
        ]);

        $form = Livewire::test(PurchaseForm::class)
            ->assertSet('aiFormToken', null)
            ->assertSet('aiFormKey', null)
            ->set('vendor_id', $vendor->id)
            ->call('selectProduct', 0, $this->aiProduct->id)
            ->set('lines.0.unit_cost', '20')
            ->set('lines.0.quantity', '3')
            ->set('vendor_invoice_number', 'NORMAL-INV-001')
            ->assertSet('totals.total', '60.000000')
            ->call('save')
            ->assertRedirect();

        $this->assertSame(1, Purchase::count());
        $purchase = Purchase::firstOrFail();
        $this->assertSame('draft', $purchase->status);
        $this->assertSame('60.000000', $purchase->grand_total_currency);
        $this->assertSame('NORMAL-INV-001', $purchase->vendor_invoice_number);
        $this->assertSame(0, DB::table('ai_purchase_draft_receipts')->count());
        $this->assertSame(0, AssistantRequest::count());
        $this->assertSame(0, DB::table('posting_batches')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        Http::assertNothingSent();
    }

    /** @return array{string, array<string, mixed>, Vendor} */
    private function editingPurchase(bool $trackExpiry = false): array
    {
        $this->aiProduct->update([
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => $trackExpiry,
            'default_purchase_cost_base' => '2.000000',
        ]);
        $this->aiCarton->update([
            'is_default_purchase' => true,
            'default_purchase_price_base' => '24.000000',
        ]);
        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد التوريد',
            'name_en' => 'Supplier',
            'code' => 'V-SUPPLIER',
        ]);

        $result = app(TransactionAssistant::class)->prepare($this->company, $this->owner,
            'buy from "Supplier": 2 "carton" "SOAP-3L" at 24 ILS', 'purchase',
            (string) Str::uuid(), (string) Str::uuid(), 'session');

        app(AiFormEditing::class)->adopt($this->company, $this->owner, $result['token'], 'session', 'purchase');

        return [$result['token'], app(AiFormState::class)->read($this->company, $this->owner, $result['token'], 'session'), $this->vendor];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function apply(string $token, array $state, array $payload, string $text): array
    {
        return app(AiFormCorrections::class)->apply($this->company, $this->owner, $token, 'session',
            $state['form_key'], $state['revision'], $payload, $text, (string) Str::uuid());
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function responsePayload(array $proposal): array
    {
        return [
            'choices' => [
                [
                    'message' => ['content' => json_encode($proposal, JSON_THROW_ON_ERROR)],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 80],
        ];
    }
}
