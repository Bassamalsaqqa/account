<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Actions\Company\CreateCompanyAction;
use App\Application\AI\AiFormEditing;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\ProductCatalogService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class AdaptiveComboboxAndLineIdentityTest extends Phase5ETestCase
{
    public function test_adaptive_combobox_blade_renders_single_listbox_teleport_bridge_and_option_ids(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-searchable-combobox
                id="invoice-desktop-line-0-product-id"
                type="product"
                context="sales"
                :selected-id="15"
                selected-label="Olive Oil (OIL-1)"
                on-select="selectProduct"
                :extra-args="['uuid-line-0']"
                compact
                placeholder="Product"
                aria-label="Product 1"
            />
        BLADE);

        $this->assertStringContainsString('<template x-teleport="body">', $html);
        $this->assertStringContainsString('x-ref="overlayPanel"', $html);
        $this->assertStringContainsString('x-ref="syncBridge"', $html);
        $this->assertStringContainsString('x-ref="sheetInput"', $html);
        $this->assertStringContainsString('data-combobox-id="invoice-desktop-line-0-product-id"', $html);
        $this->assertStringContainsString(':id="optionId(item)"', $html);
        $this->assertStringNotContainsString('-opt-', $html);
        $this->assertSame(1, substr_count($html, ':id="listboxId"'));
        $this->assertSame(1, substr_count($html, 'role="listbox"'));
        $this->assertStringContainsString('aria-live="polite"', $html);
    }

    public function test_forms_render_immutable_row_keys_and_preserve_accessibility_contract(): void
    {
        foreach ([
            [InvoiceForm::class, 'inv-line-', 'inv-card-', 'sales'],
            [PurchaseForm::class, 'purchase-line-', 'purchase-line-', 'purchasing'],
            [QuotationForm::class, 'quote-line-', 'quote-card-', 'quotation'],
        ] as [$componentClass, $desktopPrefix, $mobilePrefix, $expectedContext]) {
            $component = Livewire::test($componentClass);
            $rowKey = $component->get('lines.0.row_key');
            $this->assertIsString($rowKey);
            $this->assertNotSame('', $rowKey);

            $html = $component->html();
            $this->assertStringContainsString('wire:key="'.$desktopPrefix.$rowKey.'"', $html);
            $this->assertSame(1, substr_count($html, 'wire:key="'.$desktopPrefix.$rowKey.'"'));
            $this->assertStringNotContainsString('-card-'.$rowKey, $html);
            $this->assertStringContainsString('selectProduct', $html);
            $this->assertStringContainsString($rowKey, $html);

            if ($componentClass === QuotationForm::class) {
                $this->assertStringContainsString("context: 'quotation'", $html);
                $this->assertStringNotContainsString("context: 'sales'", $html);
                $this->assertSame(1, substr_count($html, 'id="quote-line-'.$rowKey.'-quantity"'));
                $this->assertStringNotContainsString('quote-mobile-line-', $html);
            }
            if ($componentClass === InvoiceForm::class) {
                $this->assertSame(1, substr_count($html, 'id="invoice-line-'.$rowKey.'-quantity"'));
                $this->assertStringNotContainsString('invoice-mobile-line-', $html);
            }
        }
    }

    public function test_search_selector_is_renderless_and_enforces_fresh_membership_and_cost_permissions(): void
    {
        $this->product->update(['default_purchase_cost_base' => '123.45']);

        $purchaseForm = Livewire::test(PurchaseForm::class);
        $purchaseForm->call('searchSelector', 'product', 'Food', null, ['generation' => 1])
            ->assertHasNoErrors();
        $returnedData = $purchaseForm->instance()->searchSelector('product', 'Food', null, ['generation' => 1]);
        $this->assertNotEmpty($returnedData['items']);
        $this->assertTrue(
            BigDecimal::of((string) ($returnedData['items'][0]['meta']['default_purchase_cost_base'] ?? '0'))->isEqualTo('123.45')
        );
        $this->assertSame([], $purchaseForm->effects['html'] ?? []);

        // Revoking purchasing.cost.view immediately redacts cost via fresh permission reload
        foreach ($this->owner->roles as $role) {
            $role->revokePermissionTo('purchasing.cost.view');
        }
        $this->owner->revokePermissionTo('purchasing.cost.view');

        $redacted = $purchaseForm->instance()->searchSelector('product', 'Food', null, ['generation' => 2]);
        $this->assertArrayNotHasKey('default_purchase_cost_base', $redacted['items'][0]['meta'] ?? []);

        // Revoking active membership immediately blocks searchSelector with 403
        $this->company->users()->updateExistingPivot($this->owner->id, ['status' => 'inactive']);
        $purchaseForm->call('searchSelector', 'product', 'Food', null, ['generation' => 3])
            ->assertForbidden();
    }

    public function test_row_key_and_lot_key_stay_immutable_across_mutations_and_reject_stale_keys(): void
    {
        $otherProduct = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'سلعة مع تاريخ',
            'name_en' => 'Expiry Item',
            'sku' => 'EXP-02',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => true,
            'base_unit_id' => Unit::where('code', 'piece')->firstOrFail()->id,
        ], $this->owner->id);

        foreach ([InvoiceForm::class, PurchaseForm::class, QuotationForm::class] as $componentClass) {
            $form = Livewire::test($componentClass)
                ->call('addLine');

            $key0 = $form->get('lines.0.row_key');
            $key1 = $form->get('lines.1.row_key');
            $this->assertIsString($key0);
            $this->assertIsString($key1);
            $this->assertNotSame($key0, $key1);

            // Selecting product by row_key keeps row_key immutable
            $form->call('selectProduct', $key0, $this->product->id, $this->unit->id);
            $this->assertSame($key0, $form->get('lines.0.row_key'));
            $this->assertSame($this->product->id, $form->get('lines.0.product_id'));

            // Replacing product and clearing product keep row_key immutable
            $form->call('selectProduct', $key0, $otherProduct->id, null);
            $this->assertSame($key0, $form->get('lines.0.row_key'));
            $this->assertSame($otherProduct->id, $form->get('lines.0.product_id'));

            $form->call('selectProduct', $key0, null, null);
            $this->assertSame($key0, $form->get('lines.0.row_key'));
            $this->assertNull($form->get('lines.0.product_id'));

            // Re-selecting after clear still uses same key
            $form->call('selectProduct', $key0, $this->product->id, $this->unit->id);
            $this->assertSame($key0, $form->get('lines.0.row_key'));

            // Selecting duplicate identical product on row 1 preserves both distinct row_keys
            $form->call('selectProduct', $key1, $this->product->id, $this->unit->id)
                ->set('lines.1.quantity', '4');
            $this->assertSame($key0, $form->get('lines.0.row_key'));
            $this->assertSame($key1, $form->get('lines.1.row_key'));

            // Delete row 0 by key0 -> row 1 survives at index 0 with exact key1
            if ($componentClass === PurchaseForm::class) {
                $form->call('toggleRowOptions', $key1);
                $this->assertTrue((bool) $form->get('expandedRowOptions.1'));
            }
            $form->call('removeLine', $key0);
            $this->assertCount(1, $form->get('lines'));
            $this->assertSame($key1, $form->get('lines.0.row_key'));
            $this->assertSame('4', $form->get('lines.0.quantity'));
            if ($componentClass === PurchaseForm::class) {
                $this->assertTrue((bool) $form->get('expandedRowOptions.0'));
            }

            // Stale deleted row_key, non-existent UUID, or string numeric index NEVER mutate survivor
            $form->call('selectProduct', $key0, $otherProduct->id, null);
            $form->call('selectProduct', '0', $otherProduct->id, null);
            $form->call('selectProduct', (string) Str::uuid(), $otherProduct->id, null);
            $form->call('removeLine', $key0);
            $this->assertCount(1, $form->get('lines'));
            $this->assertSame($this->product->id, $form->get('lines.0.product_id'));
            $this->assertSame('4', $form->get('lines.0.quantity'));
        }

        // PurchaseForm lot_key immutability & stale lot_key rejection
        $pf = Livewire::test(PurchaseForm::class);
        $rowKey = $pf->get('lines.0.row_key');
        $pf->call('selectProduct', $rowKey, $otherProduct->id, null)
            ->call('customizeLots', $rowKey)
            ->call('addLot', $rowKey);

        $lots = $pf->get('lines.0.lots');
        $this->assertCount(2, $lots);
        $lotKey0 = $lots[0]['lot_key'];
        $lotKey1 = $lots[1]['lot_key'];
        $this->assertNotSame($lotKey0, $lotKey1);

        $pf->set('lines.0.lots.1.lot_number', 'LOT-KEEP-1')
            ->call('removeLot', $rowKey, $lotKey0)
            ->call('removeLot', $rowKey, $lotKey0); // stale repeat must no-op

        $this->assertCount(1, $pf->get('lines.0.lots'));
        $this->assertSame($lotKey1, $pf->get('lines.0.lots.0.lot_key'));
        $this->assertSame('LOT-KEEP-1', $pf->get('lines.0.lots.0.lot_number'));
    }

    public function test_ui_row_and_lot_keys_preserve_canonical_digest_ai_hash_and_persistence(): void
    {
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل تجربة',
            'name_en' => 'Test Customer',
            'preferred_locale' => 'ar',
            'default_currency_code' => 'ILS',
            'created_by' => $this->owner->id,
            'status' => 'active',
        ]);

        // InvoiceForm: changing UI row_key leaves canonical digest identical and persists cleanly
        $invoiceForm = Livewire::test(InvoiceForm::class)
            ->set('customer_id', $customer->id)
            ->call('selectProduct', 0, $this->product->id, $this->unit->id)
            ->set('lines.0.quantity', '3')
            ->set('lines.0.unit_price', '25.50');

        $digestBefore = $invoiceForm->instance()->computePayloadDigest();
        $aiHashBefore = app(AiFormEditing::class)->hash(
            (function () use ($invoiceForm) {
                $ref = new \ReflectionMethod($invoiceForm->instance(), 'aiEditablePayload');
                $ref->setAccessible(true);

                return $ref->invoke($invoiceForm->instance());
            })()
        );

        $invoiceForm->set('lines.0.row_key', (string) Str::uuid());
        $digestAfter = $invoiceForm->instance()->computePayloadDigest();
        $aiPayloadAfter = (function () use ($invoiceForm) {
            $ref = new \ReflectionMethod($invoiceForm->instance(), 'aiEditablePayload');
            $ref->setAccessible(true);

            return $ref->invoke($invoiceForm->instance());
        })();

        $this->assertSame($digestBefore, $digestAfter);
        $this->assertSame($aiHashBefore, app(AiFormEditing::class)->hash($aiPayloadAfter));
        $this->assertArrayNotHasKey('row_key', $aiPayloadAfter['lines'][0]);

        $invoiceForm->call('saveDraft')->assertHasNoErrors();
        $invoice = SalesInvoice::latest('id')->firstOrFail();
        $reopenedInvoice = Livewire::test(InvoiceForm::class, ['publicId' => $invoice->public_id]);
        $this->assertNotEmpty($reopenedInvoice->get('lines.0.row_key'));
        $this->assertTrue(BigDecimal::of($reopenedInvoice->get('lines.0.unit_price'))->isEqualTo('25.50'));

        // PurchaseForm with expiry lot_key leaves digest identical and persists cleanly
        $expiryProduct = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صنف صلاحية',
            'name_en' => 'Expiry Product',
            'sku' => 'EXP-DIGEST-1',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => true,
            'base_unit_id' => Unit::where('code', 'piece')->firstOrFail()->id,
        ], $this->owner->id);

        $purchaseForm = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $expiryProduct->id, null)
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_cost', '12.50')
            ->call('customizeLots', 0)
            ->set('lines.0.lots.0.lot_number', 'LOT-ABC')
            ->set('lines.0.lots.0.expiry_date', now()->addMonths(6)->toDateString())
            ->set('lines.0.lots.0.quantity', '2');

        $pfDigestBefore = $purchaseForm->instance()->computePayloadDigest();
        $pfLegacyBefore = $purchaseForm->instance()->computeLegacyPayloadDigest();
        $purchaseForm->set('lines.0.row_key', (string) Str::uuid());
        $purchaseForm->set('lines.0.lots.0.lot_key', (string) Str::uuid());
        $this->assertSame($pfDigestBefore, $purchaseForm->instance()->computePayloadDigest());
        $this->assertSame($pfLegacyBefore, $purchaseForm->instance()->computeLegacyPayloadDigest());

        $purchaseForm->call('save', false)->assertHasNoErrors();
        $purchase = Purchase::latest('id')->firstOrFail();
        $this->assertSame('LOT-ABC', DB::table('purchase_line_lots')->where('company_id', $this->company->id)->value('lot_number'));

        $reopenedPurchase = Livewire::test(PurchaseForm::class, ['publicId' => $purchase->public_id]);
        $this->assertNotEmpty($reopenedPurchase->get('lines.0.row_key'));
        $this->assertNotEmpty($reopenedPurchase->get('lines.0.lots.0.lot_key'));

        // AI form replacement preserves existing row_key while assigning new UUIDs for newly added lines
        $originalKey = $reopenedInvoice->get('lines.0.row_key');
        $refApply = new \ReflectionMethod($reopenedInvoice->instance(), 'applyAiEditResult');
        $refApply->setAccessible(true);
        $refPayload = new \ReflectionMethod($reopenedInvoice->instance(), 'aiEditablePayload');
        $refPayload->setAccessible(true);
        $basePayload = $refPayload->invoke($reopenedInvoice->instance());
        $basePayload['lines'][] = [
            'product_id' => $this->product->id,
            'product_unit_id' => $this->unit->id,
            'item_description' => 'AI Added Line',
            'quantity' => '1',
            'unit_price' => '10.00',
            'discount_type' => 'none',
            'discount_value' => '0',
            'tax_rate_id' => null,
        ];
        $refApply->invoke($reopenedInvoice->instance(), [
            'revision' => 1,
            'changed_fields' => ['lines'],
            'can_undo' => true,
            'payload' => $basePayload,
        ]);

        $this->assertSame($originalKey, $reopenedInvoice->get('lines.0.row_key'));
        $this->assertNotEmpty($reopenedInvoice->get('lines.1.row_key'));
        $this->assertNotSame($originalKey, $reopenedInvoice->get('lines.1.row_key'));

        // Cross-tenant product selection with valid row_key fails closed
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create(['locale' => 'en']);
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة أخرى',
            'name_en' => 'Other Co',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($otherCompany, $otherOwner);
        $this->actingAs($otherOwner);
        $foreignProduct = app(ProductCatalogService::class)->createProduct($otherCompany, [
            'name_ar' => 'صنف أجنبي',
            'name_en' => 'Foreign Item',
            'sku' => 'FOR-01',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => Unit::where('company_id', $otherCompany->id)->where('code', 'piece')->firstOrFail()->id,
        ], $otherOwner->id);

        app(CompanyContext::class)->clear();
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        $qf = Livewire::test(QuotationForm::class);
        $qRowKey = $qf->get('lines.0.row_key');
        $qf->call('selectProduct', $qRowKey, $foreignProduct->id, null);
        $this->assertNull($qf->get('lines.0.product_id'));
        $this->assertSame($qRowKey, $qf->get('lines.0.row_key'));
    }
}
