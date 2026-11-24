<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Application\AI\PurchaseDraftPayload;
use App\Application\Commands\ImmediatePurchaseCommands;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Models\Company;
use App\Models\CompanyInventorySettings;
use App\Models\CompanyPurchaseSetting;
use App\Models\Currency;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseConfirmationReceipt;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Purchasing\PurchaseFormConfirmation;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class PurchaseFormSimplificationCandidateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Vendor $vendor;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة تبسيط المشتريات',
            'name_en' => 'Purchase Simplification Co',
        ]);
        $this->activate($this->owner);

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد التبسيط',
            'name_en' => 'Simplification Vendor',
        ]);
        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();

        $piece = Unit::where('code', 'piece')->firstOrFail();

        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج قياسي',
            'name_en' => 'Standard Product',
            'sku' => 'SKU-SIMP-01',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $piece->id,
            'default_purchase_cost_base' => '25.00',
        ], $this->owner->id);

        // Configure default receiving warehouse
        CompanyPurchaseSetting::updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'default_receiving_warehouse_id' => $this->warehouse->id,
                'default_payment_terms_days' => 30,
            ]
        );
    }

    private function activate(User $actor): void
    {
        app(CompanyContext::class)->setCompany($this->company, $actor);
        $this->actingAs($actor);
        setPermissionsTeamId($this->company->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }

    public function test_safe_default_warehouse_is_automatically_bound_when_configured(): void
    {
        $test = Livewire::test(PurchaseForm::class);

        // Must bind the configured receiving warehouse without forcing user interaction
        $test->assertSet('warehouse_id', $this->warehouse->id);
    }

    public function test_safe_default_warehouse_is_null_without_arbitrary_fallback_when_unconfigured(): void
    {
        CompanyPurchaseSetting::where('company_id', $this->company->id)->update([
            'default_receiving_warehouse_id' => null,
        ]);
        CompanyInventorySettings::where('company_id', $this->company->id)->update([
            'default_warehouse_id' => null,
        ]);

        $test = Livewire::test(PurchaseForm::class);

        // Must not select an arbitrary warehouse when no explicit default exists
        $test->assertSet('warehouse_id', null);
    }

    public function test_safe_default_unit_is_resolved_on_product_selection(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->product->id);

        $defaultUnit = ProductUnit::where('product_id', $this->product->id)
            ->where('is_default_purchase', true)
            ->firstOrFail();

        $test->assertSet('lines.0.product_id', $this->product->id);
        $test->assertSet('lines.0.product_unit_id', $defaultUnit->id);
        $test->assertSet('lines.0.unit_cost', '25.00');
    }

    public function test_safe_default_date_and_due_date_resolve_from_company_settings(): void
    {
        $test = Livewire::test(PurchaseForm::class);

        $today = now($this->company->timezone)->format('Y-m-d');
        $expectedDue = now($this->company->timezone)->addDays(30)->format('Y-m-d');

        $test->assertSet('purchase_date', $today);
        $test->assertSet('due_date', $expectedDue);
    }

    public function test_error_state_and_attention_focus_contract_preserved(): void
    {
        app()->setLocale('ar');
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', null)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('save', false);

        $test->assertHasErrors(['vendor_id']);

        $html = $test->html();

        // Must render data-form-attention component for focusing invalid controls
        $this->assertStringContainsString('data-form-attention', $html);
        $this->assertStringContainsString('role="alert"', $html);

        // Combobox must reflect aria-invalid="true" when it has validation error
        $this->assertStringContainsString('id="purchase-vendor"', $html);
        $this->assertStringContainsString('border-danger', $html);
    }

    public function test_hotfix_108_retained_in_template_and_runtime(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('currency_code', 'USD');

        $html = $test->html();

        // No illicit lifecycle hook binding on wire:change
        $this->assertStringNotContainsString('wire:change="updatedExchangeRate"', $html);
        // No corrupted UTF-8 mojibake
        $this->assertStringNotContainsString('â€”', $html);
        // Field-level error container present
        $this->assertStringContainsString('id="purchase-rate-error"', $html);
    }

    public function test_minimal_presentation_only_slice_routine_draft_creation_succeeds(): void
    {
        // Minimal slice: vendor + product + qty + cost.
        // No need for supplier invoice number, notes, language override, or lot customization.
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_cost', '25.00')
            ->call('saveDraft');

        $test->assertHasNoErrors();
        $this->assertNotNull($test->get('savedDraftPublicId'));
        $test->assertSet('previewGrandTotal', '50.00');
    }

    public function test_new_lines_strictly_enforce_no_line_discount_and_reset_to_zero(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_cost', '25.00');

        // New lines must not accept individual discounts (superseded by global discount)
        $test->set('lines.0.discount_type', 'percent')
            ->set('lines.0.discount_value', '10');

        $this->assertSame('none', $test->get('lines.0.discount_type'));
        $this->assertSame('0', $test->get('lines.0.discount_value'));

        $test->call('recalculate');
        $this->assertTrue($test->get('documentTotalsValid'));
        $this->assertSame('50.00', $test->get('previewGrandTotal'));
    }

    public function test_legacy_draft_with_line_discount_calculates_correctly_and_preserves_totals(): void
    {
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_scope' => 'line',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '2',
                    'unit_cost' => '25.00',
                    'discount_type' => 'fixed',
                    'discount_value' => '5.00',
                ],
            ],
        ], ['allow_legacy_scope' => true]);

        $test = Livewire::test(PurchaseForm::class, ['publicId' => $draft->public_id]);

        // Hydrates legacy discount and calculates correctly
        $this->assertTrue($test->get('documentTotalsValid'));
        $this->assertSame('45.00', $test->get('previewGrandTotal'));
    }

    public function test_malformed_discount_confirmation_returns_a_field_error_without_writing(): void
    {
        app()->setLocale('en');
        $form = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->product->id)
            ->call('toggleDiscountControl')
            ->set('discount_type', 'fixed');

        foreach (['abc', '1,2', '1e2', '', '-1', '0.0000001', '100000000000000', []] as $value) {
            $form->update(
                calls: [['method' => 'saveAndConfirm', 'params' => [], 'path' => '']],
                updates: ['discount_value' => $value],
            )->assertHasErrors('discount_value')
                ->assertSet('documentTotalsValid', false);
        }
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('purchase_confirmation_receipts', 0);
    }

    public function test_fixed_discount_requires_reentry_on_manual_and_vendor_currency_changes(): void
    {
        $foreignVendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد أجنبي',
            'name_en' => 'Foreign supplier',
            'default_currency_code' => 'USD',
        ]);

        foreach (['vendor_id' => $foreignVendor->id, 'currency_code' => 'USD'] as $field => $value) {
            $form = Livewire::test(PurchaseForm::class)
                ->set('vendor_id', $this->vendor->id)
                ->call('selectProduct', 0, $this->product->id)
                ->call('toggleDiscountControl')
                ->set('discount_type', 'fixed')
                ->set('discount_value', '17')
                ->set($field, $value)
                ->assertSet('currency_code', 'USD')
                ->assertSet('discount_value', '')
                ->assertSet('lines.0.unit_cost', '')
                ->assertHasErrors('discount_value');

            $form->set('exchange_rate', '3.5')
                ->set('lines.0.unit_cost', '25')
                ->call('saveAndConfirm')
                ->assertHasErrors('discount_value')
                ->set('discount_value', '5')
                ->assertSet('previewGrandTotal', '20.00');
        }
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_bundled_currency_change_clears_pending_fixed_discount_and_cost(): void
    {
        Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->product->id)
            ->call('toggleDiscountControl')
            ->set('discount_type', 'fixed')
            ->update(updates: ['lines.0.unit_cost' => '25', 'discount_value' => '17', 'currency_code' => 'USD'])
            ->assertSet('currency_code', 'USD')
            ->assertSet('lines.0.unit_cost', '')
            ->assertSet('discount_value', '')
            ->assertHasErrors('discount_value');
    }

    public function test_header_discount_binding_and_validation_with_canonical_calculation(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_cost', '25.00');

        // Initial defaults
        $test->assertSet('discount_type', 'none');
        $test->assertSet('discount_value', '0');
        $test->assertSet('showDiscountControl', false);

        // Toggle opens discount control
        $test->call('toggleDiscountControl');
        $test->assertSet('showDiscountControl', true);

        // Canonical 10% global discount on 50.00 subtotal
        $test->set('discount_type', 'percent');
        $test->set('discount_value', '10');
        $test->call('recalculate');

        $this->assertTrue($test->get('documentTotalsValid'));
        $this->assertSame('45.00', $test->get('previewGrandTotal'));
        $this->assertSame('5.000000', $test->get('totals.discount'));

        // Validation: percent > 100 fails
        $test->set('discount_value', '150');
        $test->call('recalculate');
        $this->assertFalse($test->get('documentTotalsValid'));
        $test->assertHasErrors(['discount_value']);

        $test->call('save', false);
        $test->assertHasErrors(['discount_value']);

        // Validation: negative discount fails
        $test->set('discount_value', '-10');
        $test->call('recalculate');
        $this->assertFalse($test->get('documentTotalsValid'));
        $test->assertHasErrors(['discount_value']);

        // Validation: fixed discount exceeding subtotal fails
        $test->set('discount_type', 'fixed');
        $test->set('discount_value', '60.00');
        $test->call('recalculate');
        $this->assertFalse($test->get('documentTotalsValid'));
        $test->assertHasErrors(['discount_value']);

        // Valid fixed discount passes and saves draft
        $test->set('discount_value', '5.00');
        $test->call('recalculate');
        $this->assertTrue($test->get('documentTotalsValid'));
        $this->assertSame('45.00', $test->get('previewGrandTotal'));

        $test->call('saveDraft');
        $test->assertHasNoErrors();
        $this->assertNotNull($test->get('savedDraftPublicId'));

        $savedDraft = Purchase::where('company_id', $this->company->id)->where('public_id', $test->get('savedDraftPublicId'))->firstOrFail();
        $this->assertSame('fixed', $savedDraft->discount_type);
        $this->assertTrue(BigDecimal::of($savedDraft->discount_value)->isEqualTo('5.00'));
        $this->assertSame('document', $savedDraft->discount_scope);

        $line = $savedDraft->lines->first();
        $this->assertTrue(BigDecimal::of($line->allocated_document_discount)->isEqualTo('5.00'));
        $this->assertTrue(BigDecimal::of($line->line_total)->isEqualTo('45.00'));
    }

    public function test_legacy_line_discounts_cannot_be_tampered_by_client_request_or_new_lines(): void
    {
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_scope' => 'line',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '2',
                    'unit_cost' => '25.00',
                    'discount_type' => 'fixed',
                    'discount_value' => '5.00',
                ],
            ],
        ], ['allow_legacy_scope' => true]);

        $test = Livewire::test(PurchaseForm::class, ['publicId' => $draft->public_id]);

        // Attempt to tamper with legacy line discount via client state
        $test->set('lines.0.discount_type', 'percent');
        $test->set('lines.0.discount_value', '50.00');

        // Add a second line with attempted client discount
        $test->call('addLine');
        $test->call('selectProduct', 1, $this->product->id);
        $test->set('lines.1.quantity', '1');
        $test->set('lines.1.unit_cost', '20.00');
        $test->set('lines.1.discount_type', 'percent');
        $test->set('lines.1.discount_value', '10.00');

        // Trigger updatedLines / draft save
        $test->call('saveDraft');
        $test->assertHasNoErrors();

        $reloaded = Purchase::where('id', $draft->id)->firstOrFail();
        $this->assertCount(2, $reloaded->lines);

        $line0 = $reloaded->lines->sortBy('id')->first();
        $line1 = $reloaded->lines->sortBy('id')->last();

        // Line 0 strictly preserves database-persisted legacy values
        $this->assertSame('fixed', $line0->discount_type);
        $this->assertTrue(BigDecimal::of($line0->discount_value)->isEqualTo('5.00'));

        // Line 1 (new line) strictly resets to none / 0
        $this->assertContains($line1->discount_type, [null, 'none']);
        $this->assertTrue(BigDecimal::of($line1->discount_value)->isZero());
    }

    public function test_collapsible_drawer_contains_optional_fields_and_auto_expands_on_error(): void
    {
        $test = Livewire::test(PurchaseForm::class);
        $html = $test->html();

        // Both optional fields are inside extendedDetailsDrawer
        $drawerHtml = strstr($html, 'id="extendedDetailsDrawer"');
        $this->assertNotFalse($drawerHtml);
        $this->assertStringContainsString('id="supplier-number"', $drawerHtml);
        $this->assertStringContainsString('id="purchase-due_date"', $drawerHtml);

        // Neither is rendered in the top primary block before the drawer
        $topBlock = substr($html, 0, strpos($html, 'id="extendedDetailsDrawer"'));
        $this->assertStringNotContainsString('id="supplier-number"', $topBlock);
        $this->assertStringNotContainsString('id="purchase-due_date"', $topBlock);

        // Drawer auto-expands when drawer fields have validation errors
        $test->set('vendor_invoice_number', str_repeat('V', 150));
        $test->call('save', false);
        $test->assertHasErrors(['vendor_invoice_number']);

        $errorHtml = $test->html();
        $this->assertStringContainsString('x-show="true"', $errorHtml);
    }

    public function test_lines_table_action_bar_is_clean_of_shortcuts_and_counters_and_global_discount_controls_toggle(): void
    {
        $test = Livewire::test(PurchaseForm::class);
        $html = $test->html();

        // No Alt+N shortcut badge or item counter clutter
        $this->assertStringNotContainsString('Alt+N', $html);
        $this->assertStringNotContainsString('· 1 Items', $html);

        // Global discount input is initially collapsed behind clean add button
        $this->assertStringContainsString('wire:click="toggleDiscountControl"', $html);
        $this->assertStringNotContainsString('id="purchase-discount-type"', $html);

        // When toggled, interactive inputs appear
        $test->call('toggleDiscountControl');
        $toggledHtml = $test->html();
        $this->assertStringContainsString('id="purchase-discount-type"', $toggledHtml);
        $this->assertStringContainsString('id="purchase-discount-value"', $toggledHtml);
    }

    public function test_fx_currency_requires_exchange_rate_and_recalculates_on_sync(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->set('currency_code', 'USD')
            ->set('exchange_rate', '')
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_cost', '10.00');

        // Blank exchange rate on FX currency fails calculation
        $test->call('recalculate');
        $this->assertFalse($test->get('documentTotalsValid'));
        $test->assertHasErrors(['exchange_rate']);

        // Providing valid exchange rate succeeds and recalculates preview
        $test->set('exchange_rate', '3.5000000000');
        $test->call('recalculate');
        $this->assertTrue($test->get('documentTotalsValid'));
        $this->assertSame('20.00', $test->get('previewGrandTotal'));
    }

    public function test_arabic_and_english_locale_rendering_and_messages(): void
    {
        // Arabic
        app()->setLocale('ar');
        $testAr = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('selectProduct', 0, $this->product->id)
            ->call('toggleDiscountControl')
            ->set('discount_type', 'percent')
            ->set('discount_value', '150');

        $testAr->call('recalculate');
        $testAr->assertHasErrors(['discount_value']);
        $errorAr = $testAr->errors()->first('discount_value');
        $this->assertStringContainsString('الخصم', $errorAr);

        // English
        app()->setLocale('en');
        $testEn = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('selectProduct', 0, $this->product->id)
            ->call('toggleDiscountControl')
            ->set('discount_type', 'percent')
            ->set('discount_value', '150');

        $testEn->call('recalculate');
        $testEn->assertHasErrors(['discount_value']);
        $errorEn = $testEn->errors()->first('discount_value');
        $this->assertStringContainsString('percentage', strtolower($errorEn));
    }

    public function test_legacy_line_discount_renders_read_only_badge_and_persists_without_silent_reset(): void
    {
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_scope' => 'line',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '2',
                    'unit_cost' => '25.00',
                    'discount_type' => 'fixed',
                    'discount_value' => '5.00',
                ],
            ],
        ], ['allow_legacy_scope' => true]);

        $test = Livewire::test(PurchaseForm::class, ['publicId' => $draft->public_id]);

        // Hydrates existing legacy discount
        $test->assertSet('lines.0.discount_type', 'fixed');
        $this->assertTrue(BigDecimal::of($test->get('lines.0.discount_value'))->isEqualTo('5.00'));

        $html = $test->html();

        // Read-only legacy badge rendered
        $this->assertStringContainsString('5.00', $html);
        $this->assertStringContainsString('ILS', $html);

        // No editable line discount inputs rendered in template
        $this->assertStringNotContainsString('id="line-discount-type-0"', $html);
        $this->assertStringNotContainsString('id="line-discount-value-0"', $html);

        // Saving draft does not reset the legacy discount
        $test->call('saveDraft');
        $test->assertHasNoErrors();

        $reloaded = Purchase::where('id', $draft->id)->firstOrFail();
        $reloadedLine = $reloaded->lines->first();
        $this->assertSame('fixed', $reloadedLine->discount_type);
        $this->assertTrue(BigDecimal::of($reloadedLine->discount_value)->isEqualTo('5.00'));
    }

    public function test_purchase_draft_with_document_discount_posts_successfully(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_cost', '25.00')
            ->call('toggleDiscountControl')
            ->set('discount_type', 'percent')
            ->set('discount_value', '10')
            ->call('saveDraft');

        $test->assertHasNoErrors();
        $publicId = $test->get('savedDraftPublicId');
        $this->assertNotNull($publicId);

        $purchase = Purchase::where('company_id', $this->company->id)->where('public_id', $publicId)->firstOrFail();
        $this->assertSame('draft', $purchase->status);
        $this->assertTrue(BigDecimal::of($purchase->subtotal_currency)->isEqualTo('50.00'));
        $this->assertTrue(BigDecimal::of($purchase->discount_total_currency)->isEqualTo('5.00'));
        $this->assertTrue(BigDecimal::of($purchase->grand_total_currency)->isEqualTo('45.00'));

        // Post the purchase draft
        $posted = app(PostPurchaseAction::class)->execute($purchase, $this->owner);
        $this->assertSame('posted', $posted->status);

        // Assert posting batch balances exactly
        $batch = PostingBatch::where('company_id', $this->company->id)->whereKey($posted->posting_batch_id)->firstOrFail();
        $lines = PostingLine::where('posting_batch_id', $batch->id)->get();

        $dr = BigDecimal::zero();
        $cr = BigDecimal::zero();
        foreach ($lines as $line) {
            $dr = $dr->plus(BigDecimal::of($line->debit_base));
            $cr = $cr->plus(BigDecimal::of($line->credit_base));
        }
        $this->assertTrue($dr->isEqualTo($cr));
        $this->assertTrue($dr->isPositive());
    }

    public function test_select_product_and_change_unit_preserves_identity_and_discount_for_same_item_and_blocks_changing_discounted_legacy_item(): void
    {
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'discount_scope' => 'line',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '2',
                    'unit_cost' => '25.00',
                    'discount_type' => 'fixed',
                    'discount_value' => '5.00',
                ],
            ],
        ], ['allow_legacy_scope' => true]);

        $test = Livewire::test(PurchaseForm::class, ['publicId' => $draft->public_id]);
        $origPublicId = $test->get('lines.0.public_id');
        $this->assertNotNull($origPublicId);

        // 1. Legitimate allowed edit: changing quantity retains public_id and exact discount
        $test->set('lines.0.quantity', '3');
        $test->call('recalculate');
        $this->assertSame($origPublicId, $test->get('lines.0.public_id'));
        $this->assertSame('fixed', $test->get('lines.0.discount_type'));
        $this->assertTrue(BigDecimal::of($test->get('lines.0.discount_value'))->isEqualTo('5.00'));

        // 2. Calling selectProduct with SAME product & unit preserves public_id and exact discount
        $test->call('selectProduct', 0, $this->product->id);
        $test->assertHasNoErrors();
        $this->assertSame($origPublicId, $test->get('lines.0.public_id'));
        $this->assertSame('fixed', $test->get('lines.0.discount_type'));
        $this->assertTrue(BigDecimal::of($test->get('lines.0.discount_value'))->isEqualTo('5.00'));

        // 3. Calling changeUnit with SAME unit preserves public_id and exact discount
        $baseUnit = $this->product->productUnits()->first();
        $test->call('changeUnit', 0, $baseUnit->id);
        $test->assertHasNoErrors();
        $this->assertSame($origPublicId, $test->get('lines.0.public_id'));
        $this->assertSame('fixed', $test->get('lines.0.discount_type'));
        $this->assertTrue(BigDecimal::of($test->get('lines.0.discount_value'))->isEqualTo('5.00'));

        // Create second product for change attempt
        $piece = Unit::where('code', 'piece')->firstOrFail();
        $otherProduct = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج آخر',
            'name_en' => 'Other Product',
            'sku' => 'SKU-SIMP-02',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $piece->id,
            'default_purchase_cost_base' => '30.00',
        ], $this->owner->id);

        // 4. Calling selectProduct with DIFFERENT product on discounted legacy line is blocked with error
        $test->call('selectProduct', 0, $otherProduct->id);
        $test->assertHasErrors(['lines.0.product_id']);
        $this->assertSame($origPublicId, $test->get('lines.0.public_id'));
        $this->assertSame('fixed', $test->get('lines.0.discount_type'));
        $this->assertTrue(BigDecimal::of($test->get('lines.0.discount_value'))->isEqualTo('5.00'));

        // 5. Calling changeUnit with DIFFERENT unit on discounted legacy line is blocked with error
        $test->call('changeUnit', 0, 999999);
        $test->assertHasErrors(['lines.0.product_unit_id']);
        $this->assertSame($origPublicId, $test->get('lines.0.public_id'));
        $this->assertSame('fixed', $test->get('lines.0.discount_type'));
        $this->assertTrue(BigDecimal::of($test->get('lines.0.discount_value'))->isEqualTo('5.00'));

        // 6. Test saveDraft with allowed edits on a fresh form instance saves and preserves discount
        $testSave = Livewire::test(PurchaseForm::class, ['publicId' => $draft->public_id]);
        $testSave->set('lines.0.quantity', '5');
        $testSave->call('saveDraft');
        $testSave->assertHasNoErrors();

        $reloaded = Purchase::where('id', $draft->id)->firstOrFail();
        $this->assertSame('fixed', $reloaded->lines->first()->discount_type);
        $this->assertTrue(BigDecimal::of($reloaded->lines->first()->discount_value)->isEqualTo('5.00'));
    }

    public function test_ai_unsaved_form_purchase_allowlist_preserves_discount_type_and_value(): void
    {
        $test = Livewire::test(PurchaseForm::class);
        $test->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_cost', '50.00')
            ->set('discount_type', 'percent')
            ->set('discount_value', '10');

        $test->call('recalculate');
        $this->assertTrue($test->get('documentTotalsValid'));
        $this->assertSame('90.00', $test->get('previewGrandTotal'));

        // Check aiEditablePayload reflection
        $reflection = new \ReflectionClass($test->instance());
        $method = $reflection->getMethod('aiEditablePayload');
        $method->setAccessible(true);
        $payload = $method->invoke($test->instance());

        $this->assertArrayHasKey('discount_type', $payload);
        $this->assertArrayHasKey('discount_value', $payload);
        $this->assertSame('percent', $payload['discount_type']);
        $this->assertSame('10', $payload['discount_value']);

        // Scope and version must not be in the editable client payload
        $this->assertArrayNotHasKey('discount_scope', $payload);
        $this->assertArrayNotHasKey('discount_calculation_version', $payload);
    }

    public function test_stale_payment_amount_synchronizes_on_full_payment_after_recalculation_and_defends_overage(): void
    {
        $cashAccount = MoneyAccount::where('company_id', $this->company->id)->where('currency_code', 'ILS')->first();
        if (! $cashAccount) {
            $ledger = LedgerAccount::where('company_id', $this->company->id)->where('code', '1010')->first()
                ?? LedgerAccount::where('company_id', $this->company->id)->first();
            $cashAccount = MoneyAccount::create([
                'company_id' => $this->company->id,
                'name_ar' => 'صندوق كاش',
                'name_en' => 'Cash Box',
                'currency_code' => 'ILS',
                'account_type' => MoneyAccount::TYPE_CASH,
                'is_active' => true,
                'ledger_account_id' => $ledger->id,
            ]);
        }

        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '4')
            ->set('lines.0.unit_cost', '25.00') // Subtotal 100.00
            ->set('settlement.mode', 'full')
            ->set('settlement.payment_method', 'cash')
            ->set('settlement.money_account_id', (string) $cashAccount->id);

        $test->call('recalculate');
        $this->assertSame('100.00', $test->get('previewGrandTotal'));
        $this->assertSame('100.00', $test->get('settlement.amount'));

        // Step 1: User enters invalid discount 150% -> recalculate fails
        $test->call('toggleDiscountControl')
            ->set('discount_type', 'percent')
            ->set('discount_value', '150')
            ->call('recalculate');

        $this->assertFalse($test->get('documentTotalsValid'));
        $test->assertHasErrors(['discount_value']);

        // Step 2: User fixes discount to valid 10% -> recalculate succeeds
        $test->set('discount_value', '10')
            ->call('recalculate');

        $this->assertTrue($test->get('documentTotalsValid'));
        $this->assertSame('90.00', $test->get('previewGrandTotal'));

        // Crucial fix assertion: Full payment amount MUST synchronize to 90.00, not stale 100.00!
        $this->assertSame('90.00', $test->get('settlement.amount'));

        // Step 3: Partial payment mode preserves intentional partial amount
        $test->set('settlement.mode', 'partial')
            ->set('settlement.amount', '40.00')
            ->call('recalculate');

        $this->assertSame('40.00', $test->get('settlement.amount'));
        $test->assertViewHas('settlementBalance', ['remaining' => '50.00', 'credit' => null]);

        // Step 4: Defend against overage/advance on confirmation (e.g. attempting to pay 95 on total 90 in partial mode)
        $test->set('settlement.amount', '95.00');
        $test->call('saveAndConfirm');
        $test->assertHasErrors(['settlement.amount']);
    }

    public function test_confirmation_digest_binds_header_discount_intent_with_legacy_replay_compatibility(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_cost', '25.00');

        $test->call('recalculate');

        // Document has NO discount -> legacySettlementDigest exists
        $currentDigest = $test->instance()->settlementDigest();
        $legacyDigest = $test->instance()->legacySettlementDigest();
        $this->assertNotNull($legacyDigest);

        // Simulate an existing confirmation receipt created under the legacy digest
        $identity = app(PurchaseFormConfirmation::class)->identity((int) $this->company->id, $this->owner, null);

        // Create a draft for the receipt
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '2',
                    'unit_cost' => '25.00',
                ],
            ],
        ]);

        $preview = app(ImmediatePurchaseCommands::class)->preview($draft, $this->owner, ['mode' => 'unpaid']);
        $confirmationData = [
            'public_id' => $draft->public_id,
            'token' => $preview['confirmation_token'],
            'key' => (string) Str::uuid(),
            'digest' => $legacyDigest,
            'paid' => false,
        ];

        // Store legacy receipt
        $reflection = new \ReflectionClass(app(PurchaseFormConfirmation::class));
        $keyMethod = $reflection->getMethod('key');
        $keyMethod->setAccessible(true);
        $extractedKey = $keyMethod->invoke(app(PurchaseFormConfirmation::class), (int) $this->company->id, $this->owner, null, $identity);

        PurchaseConfirmationReceipt::create([
            'company_id' => $this->company->id,
            'actor_id' => $this->owner->id,
            'purchase_id' => $draft->id,
            'source_public_id' => null,
            'request_key' => $extractedKey,
            'payload_digest' => $legacyDigest,
            'confirmation' => Crypt::encryptString(json_encode($confirmationData, JSON_THROW_ON_ERROR)),
            'created_at' => now(),
        ]);

        // Replaying with currentDigest (which passes legacyDigest as fallback) succeeds without throwing 409!
        $confirmed = app(PurchaseFormConfirmation::class)->execute(
            (int) $this->company->id,
            $this->owner,
            null,
            $identity,
            $currentDigest,
            fn () => null,
            legacyDigest: $legacyDigest
        );

        $this->assertNotNull($confirmed);
        $this->assertSame($draft->public_id, $confirmed['public_id']);

        // Now activate a discount: digest MUST change, and legacyDigest becomes null
        $test->call('toggleDiscountControl')
            ->set('discount_type', 'percent')
            ->set('discount_value', '10')
            ->call('recalculate');

        $discountedDigest = $test->instance()->settlementDigest();
        $this->assertNotSame($currentDigest, $discountedDigest);
        $this->assertNull($test->instance()->legacySettlementDigest());
    }

    public function test_accessible_ui_attributes_and_conditional_discount_value_input(): void
    {
        $test = Livewire::test(PurchaseForm::class);
        $html = $test->html();

        // Extended details drawer toggle has aria-expanded and aria-controls
        $this->assertStringContainsString('aria-controls="extendedDetailsDrawer"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);

        // Before toggle: Add discount button has aria-expanded="false" and aria-controls
        $this->assertStringContainsString('aria-controls="purchase-document-discount-container"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringNotContainsString('id="purchase-discount-value"', $html);

        // Toggle discount control: type defaults to percent, value input is visible
        $test->call('toggleDiscountControl');
        $htmlToggled = $test->html();
        $this->assertStringContainsString('id="purchase-document-discount-container"', $htmlToggled);
        $this->assertStringContainsString('id="purchase-discount-type"', $htmlToggled);
        $this->assertStringContainsString('id="purchase-discount-value"', $htmlToggled);

        // When discount_type is set to 'none', value input is removed from DOM
        $test->set('discount_type', 'none');
        $htmlNone = $test->html();
        $this->assertStringNotContainsString('id="purchase-discount-value"', $htmlNone);

        // When set to 'fixed', value input reappears
        $test->set('discount_type', 'fixed');
        $htmlFixed = $test->html();
        $this->assertStringContainsString('id="purchase-discount-value"', $htmlFixed);
    }

    public function test_authoritative_precision_used_in_livewire_preview_and_settlement_choice_without_lossy_formatting(): void
    {
        // Enable JOD currency for this company
        $this->company->currencies()->updateOrCreate(
            ['currency_code' => 'JOD'],
            [
                'exchange_rate' => '0.2300000000',
                'is_default' => false,
                'enabled' => true,
            ]
        );

        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('currency_code', 'JOD')
            ->set('exchange_rate', '0.2300000000')
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '1')
            ->set('lines.0.unit_cost', '10.5')
            ->call('recalculate');

        // JOD has 3 minor units: previewGrandTotal MUST be 10.500 (not lossy 10.5 or 10.50)
        $this->assertSame('10.500', $test->get('previewGrandTotal'));

        // Money account in JOD
        $ledger = LedgerAccount::where('company_id', $this->company->id)->where('code', '1010')->first()
            ?? LedgerAccount::where('company_id', $this->company->id)->first();
        $moneyAccount = MoneyAccount::create([
            'company_id' => $this->company->id,
            'name_ar' => 'حساب دينار',
            'name_en' => 'JOD Account',
            'currency_code' => 'JOD',
            'account_type' => MoneyAccount::TYPE_CASH,
            'is_active' => true,
            'ledger_account_id' => $ledger->id,
        ]);

        // Switching to full settlement synchronizes exact 3-decimal previewGrandTotal
        $test->set('settlement.mode', 'full')
            ->set('settlement.payment_method', 'cash')
            ->set('settlement.money_account_id', $moneyAccount->id)
            ->call('recalculate');

        $this->assertSame('10.500', $test->get('settlement.amount'));

        // Reject excess precision beyond JOD's 3 decimal minor units (e.g. 4 decimals)
        $test->set('settlement.mode', 'partial')
            ->set('settlement.amount', '5.1234');

        $reflection = new \ReflectionClass($test->instance());
        $method = $reflection->getMethod('settlementChoice');
        $method->setAccessible(true);

        $this->expectException(ValidationException::class);
        $method->invoke($test->instance());
    }

    public function test_normal_create_and_ai_paths_cannot_request_legacy_scope_or_line_discounts(): void
    {
        // 1. Web UI PurchaseForm normal create defaults to 'document' and rejects line discounts
        $test = Livewire::test(PurchaseForm::class);
        $this->assertSame('document', $test->get('discount_scope'));

        // Set lines and save draft
        $test->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_cost', '15.00')
            ->call('saveDraft');

        $savedDraft = Purchase::latest('id')->firstOrFail();
        $this->assertSame('document', $savedDraft->discount_scope);
        $this->assertSame('none', $savedDraft->lines->sole()->discount_type ?? 'none');
        $this->assertSame('0.000000', (string) $savedDraft->lines->sole()->discount_value);

        // 2. AI payload cannot request 'line' discount_scope
        $intent = app(PurchaseDraftPayload::class);
        $payloadWithLineScope = [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'en',
            'discount_scope' => 'line',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '2.000000',
                    'unit_cost' => '15.000000',
                ],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Legacy line discount scope is not selectable via AI assistant.');
        $intent->canonicalize($payloadWithLineScope);
    }

    public function test_editing_draft_currency_resnapshots_precision_then_posts_at_the_frozen_scale(): void
    {
        $this->company->currencies()->where('currency_code', 'JOD')->update(['enabled' => true]);
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'discount_type' => 'percent',
            'discount_value' => '50',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '1.004']],
        ]);
        $this->assertSame(2, $draft->currency_minor_units);

        Livewire::test(PurchaseForm::class, ['publicId' => $draft->public_id])
            ->set('currency_code', 'JOD')
            ->set('exchange_rate', '2')
            ->set('lines.0.unit_cost', '1.004')
            ->assertSet('currency_minor_units', 3)
            ->assertSet('previewGrandTotal', '0.502')
            ->call('saveDraft')
            ->assertHasNoErrors();
        $this->assertSame('0.502000', $draft->fresh()->discount_total_currency);

        Currency::where('code', 'JOD')->update(['minor_units' => 2]);
        app(CurrencyMinorUnitsResolver::class)->flush();
        Livewire::test(PurchaseForm::class, ['publicId' => $draft->public_id])
            ->assertSet('currency_minor_units', 3)
            ->assertSet('previewGrandTotal', '0.502')
            ->call('saveAndConfirm')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.show', $draft->public_id));

        $posted = $draft->fresh(['lines']);
        $this->assertTrue($posted->isPosted());
        $this->assertSame(3, $posted->currency_minor_units);
        $this->assertSame('0.502000', $posted->lines->sole()->allocated_document_discount);
    }

    public function test_failed_preview_keeps_replaced_line_id_and_legacy_discount_for_retry(): void
    {
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'discount_scope' => 'line',
            'lines' => [[
                'product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '20',
                'discount_type' => 'fixed', 'discount_value' => '2.125',
            ]],
        ], ['allow_legacy_scope' => true]);

        $originalId = $draft->lines->sole()->public_id;
        $form = Livewire::test(PurchaseForm::class, ['publicId' => $draft->public_id])
            ->set('lines.0.unit_cost', '25.13')
            ->set('settlement.mode', 'full')
            ->set('settlement.money_account_id', 999999999)
            ->call('saveAndConfirm')
            ->assertHasErrors(['settlement.money_account_id'])
            ->assertSet('showPreview', false);
        $this->assertNotNull($form->get('previewError'));
        $this->assertTrue($draft->fresh()->isDraft());
        $savedLine = $draft->lines()->sole();
        $this->assertNotSame($originalId, $savedLine->public_id);
        $form->assertSet('lines.0.public_id', $savedLine->public_id)
            ->assertSet('lines.0.unit_cost', '25.13')
            ->assertSet('lines.0.discount_value', '2.125000')
            ->set('settlement.mode', 'unpaid')
            ->call('saveAndConfirm')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.show', $draft->public_id));

        $this->assertTrue($draft->fresh()->isPosted());
        $this->assertSame('2.125000', $draft->lines()->sole()->discount_value);
    }

    public function test_blank_product_row_invalidates_totals_but_selected_zero_cost_remains_valid(): void
    {
        Livewire::test(PurchaseForm::class)
            ->assertSet('documentTotalsValid', false)
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.unit_cost', '0')
            ->assertSet('documentTotalsValid', true)
            ->assertSet('previewGrandTotal', '0.00')
            ->call('addLine')
            ->assertSet('documentTotalsValid', false)
            ->assertSet('totals', []);
    }

    public function test_discounted_purchase_preview_batches_tax_queries_for_many_lines(): void
    {
        $tax = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT16',
            'name_ar' => 'ضريبة',
            'rate' => '16',
            'calculation' => TaxRate::CALC_EXCLUSIVE,
            'active' => true,
        ]);

        $form = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('discount_type', 'percent')
            ->set('discount_value', '10');

        $line = [
            ...$form->get('lines')[0],
            'product_id' => $this->product->id,
            'quantity' => '2',
            'unit_cost' => '25.00',
            'tax_rate_id' => $tax->id,
        ];

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $form->set('lines', array_fill(0, 20, $line))
                ->assertSet('previewGrandTotal', '1044.00');
            $taxQueries = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from `tax_rates`'));
            self::assertLessThanOrEqual(2, count($taxQueries));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_component_preview_uses_frozen_draft_precision_when_currency_scale_mutates(): void
    {
        $bhd = Currency::firstOrCreate(
            ['code' => 'BHD'],
            ['name' => 'Bahraini Dinar', 'symbol' => 'BD', 'minor_units' => 3, 'exchange_rate' => '1.0000000000']
        );
        $this->company->currencies()->updateOrCreate(
            ['currency_code' => 'BHD'],
            ['exchange_rate' => '1.0000000000', 'is_base' => false]
        );
        app(CurrencyMinorUnitsResolver::class)->flush();
        $this->assertSame(3, app(CurrencyMinorUnitsResolver::class)->resolve('BHD'));

        // Create a draft with BHD: 3 units at 10.000 = 30.000, fixed discount 0.500 => grand total 29.500
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'BHD',
            'exchange_rate' => '1.0000000000',
            'discount_type' => 'fixed',
            'discount_value' => '0.500',
            'discount_scope' => 'document',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '3.000000',
                    'unit_cost' => '10.000',
                ],
            ],
        ]);

        $this->assertSame(3, $draft->currency_minor_units);
        $this->assertSame('29.500000', (string) $draft->grand_total_currency);

        // Mutate current BHD scale in currencies table to 2 decimal places
        $bhd->update(['minor_units' => 2]);
        app(CurrencyMinorUnitsResolver::class)->flush();
        $this->assertSame(2, app(CurrencyMinorUnitsResolver::class)->resolve('BHD'));

        // Mount the draft in PurchaseForm component
        $test = Livewire::test(PurchaseForm::class, ['publicId' => $draft->public_id]);

        // Component must preserve frozen precision of draft (3) instead of mutated table scale (2)
        $test->assertSet('currency_minor_units', 3)
            ->assertSeeText('29.500')
            ->assertSeeText('0.500');
        $this->assertSame('29.500', $test->get('previewGrandTotal'));

        // Re-calculate must also respect frozen precision
        $test->call('recalculate');
        $this->assertSame('29.500', $test->get('previewGrandTotal'));
    }
}
