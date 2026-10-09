<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Catalogs\CatalogComposer;
use App\Models\Catalog;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalogs\CatalogService;
use App\Services\Inventory\ProductCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class CatalogComposerTest extends Phase5ETestCase
{
    /** @return array<string,mixed> */
    private function fields(bool $priced = false): array
    {
        return ['name_ar' => 'كتالوج تجاري', 'name_en' => 'Business catalog', 'description_ar' => null, 'description_en' => null,
            'locale' => 'en', 'show_prices' => $priced, 'show_sku' => true, 'show_description' => true, 'show_images' => false,
            'currency_code' => $priced ? 'ILS' : null, 'tax_basis' => $priced ? 'Tax inclusive' : null];
    }

    private function catalog(bool $priced = false): Catalog
    {
        return app(CatalogService::class)->save(null, $this->fields($priced), [['product_id' => $this->product->id,
            'unit_id' => $this->unit->unit_id, 'image_id' => null, 'custom_price' => $priced ? '0' : null]]);
    }

    public function test_livewire_save_and_publish_use_real_catalog_and_preserve_economics(): void
    {
        $before = $this->economicFingerprint();
        $component = Livewire::test(CatalogComposer::class)
            ->assertSet('headers.show_prices', false)
            ->set('headers.name_ar', 'كتالوج جديد')
            ->call('addProduct', $this->product->id)
            ->call('saveDraft')->assertHasNoErrors();
        $catalog = Catalog::findOrFail($component->get('catalogId'));
        $this->assertFalse($catalog->show_prices);
        $this->assertCount(1, $catalog->items);
        $component->call('previewPublication')->assertHasNoErrors()->call('publish')->assertHasNoErrors();
        $this->assertSame(1, $catalog->fresh()->published_revision);
        $this->assertArrayNotHasKey('currency_code', $catalog->fresh()->published_payload);
        $this->assertArrayNotHasKey('price', $catalog->fresh()->published_payload['items'][0]);
        $this->assertSame($before, $this->economicFingerprint());
    }

    public function test_product_search_selects_beyond_first_100_without_losing_order_or_selection(): void
    {
        $service = app(ProductCatalogService::class);
        $target = null;
        for ($index = 1; $index <= 105; $index++) {
            $created = $service->createProduct($this->company, ['name_ar' => 'منتج '.$index, 'name_en' => 'Selectable '.$index,
                'sku' => 'CAT-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'product_type' => Product::TYPE_STOCK,
                'track_stock' => true, 'track_expiry' => false, 'base_unit_id' => $this->unit->unit_id], $this->owner->id);
            if ($index === 1) {
                $target = $created;
            }
        }
        $this->assertNotNull($target);
        $component = Livewire::test(CatalogComposer::class)
            ->call('addProduct', $this->product->id)
            // Results sort newest first: CAT-001 lies outside the first 100 records.
            ->set('productSearch', 'CAT-001')->assertSee('CAT-001')
            ->call('addProduct', $target->id)
            ->set('productSearch', 'CAT-105')
            ->call('moveItemUp', 1)
            ->set('headers.name_ar', 'منتجات محددة')
            ->call('saveDraft')->assertHasNoErrors();
        $this->assertSame([$target->id, $this->product->id], array_column($component->get('items'), 'product_id'));
        $this->assertSame([$target->id, $this->product->id], Catalog::findOrFail($component->get('catalogId'))->items()->orderBy('position')->pluck('product_id')->all());
    }

    public function test_stale_saved_preview_is_rejected_after_another_editor_saves(): void
    {
        $catalog = $this->catalog();
        $component = Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id])->call('previewPublication')->assertHasNoErrors();
        app(CatalogService::class)->save($catalog->id, $this->fields() + [], [['product_id' => $this->product->id, 'unit_id' => $this->unit->unit_id]], $catalog->draft_revision);
        $component->call('publish')->assertHasErrors('general');
        $this->assertSame(0, $catalog->fresh()->published_revision);
    }

    public function test_priced_publish_requires_actual_confirmed_projection_ack_and_keeps_zero_price(): void
    {
        $catalog = $this->catalog(true);
        $this->assertSame('0.000000', $catalog->items()->firstOrFail()->custom_price);
        $component = Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id])
            ->set('items.0.custom_price', '0')->call('saveDraft')->assertHasNoErrors()
            ->call('previewPublication')->assertHasNoErrors()
            ->call('publish')->assertHasErrors('general');
        $this->assertSame(0, $catalog->fresh()->published_revision);
        // Editing the client flag cannot disguise the persisted priced projection as price-less.
        $component->set('headers.show_prices', false)->call('publish')->assertHasErrors('general');
        $this->assertSame(0, $catalog->fresh()->published_revision);
        $component->call('loadCatalog', $catalog->public_id)->call('previewPublication')
            ->set('priceAcknowledged', true)->call('publish')->assertHasNoErrors();
        $this->assertSame('0.000000', $catalog->fresh()->published_payload['items'][0]['price']);
        $oldKey = $component->get('requestKey');
        $component->call('previewPublication')->assertSet('priceAcknowledged', false);
        $this->assertNotSame($oldKey, $component->get('requestKey'));
    }

    public function test_priced_catalog_never_loads_for_an_actor_without_price_authority(): void
    {
        $catalog = $this->catalog(true);
        $actor = $this->customActor(['catalogs.view', 'catalogs.manage', 'catalogs.publish', 'inventory.stock.view']);
        $this->activate($actor);
        Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id])->assertForbidden();
    }

    public function test_permission_and_membership_revocation_after_mount_block_sensitive_hydration(): void
    {
        $catalog = $this->catalog(true);
        $actor = $this->customActor(['catalogs.view', 'catalogs.manage', 'catalogs.publish', 'catalogs.show_prices', 'inventory.stock.view']);
        $this->activate($actor);
        $component = Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id])->call('previewPublication')->assertHasNoErrors();
        $role = $actor->roles()->firstOrFail();
        $role->revokePermissionTo('catalogs.show_prices');
        $component->call('$refresh')->assertForbidden();
        $this->assertSame(0, $catalog->fresh()->published_revision);
        $this->activate($this->owner);
        $ordinary = $this->catalog();
        $this->activate($actor);
        $component = Livewire::test(CatalogComposer::class, ['publicId' => $ordinary->public_id]);
        $actor->load('roles', 'permissions');
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $actor->id)->update(['status' => 'inactive']);
        $component->call('saveDraft')->assertForbidden();
        $this->assertSame(1, $ordinary->fresh()->draft_revision);
    }

    public function test_product_selection_authority_is_required_before_hydrating_search_results(): void
    {
        $actor = $this->customActor(['catalogs.view', 'catalogs.manage']);
        $this->activate($actor);
        Livewire::test(CatalogComposer::class)->assertForbidden();
    }

    public function test_untrusted_items_search_and_headers_are_bounded_before_hydration(): void
    {
        Livewire::test(CatalogComposer::class)->set('productSearch', str_repeat('a', 121))->assertStatus(422);
        $item = ['product_id' => $this->product->id, 'unit_id' => $this->unit->unit_id, 'image_id' => null,
            'name_ar' => null, 'name_en' => null, 'description_ar' => null, 'description_en' => null, 'custom_price' => null];
        Livewire::test(CatalogComposer::class)->set('items', array_fill(0, 251, $item))->assertStatus(422);
        Livewire::test(CatalogComposer::class)->set('headers', [])->assertStatus(422);
        $this->assertSame(0, Catalog::count());
    }

    public function test_selection_metadata_never_serializes_default_purchase_or_selling_prices(): void
    {
        $this->unit->update(['default_purchase_price_base' => '98765.123456', 'default_sale_price_base' => '87654.654321']);
        $actor = $this->customActor(['catalogs.view', 'catalogs.manage', 'inventory.stock.view']);
        $this->activate($actor);
        Livewire::test(CatalogComposer::class)->call('addProduct', $this->product->id)
            ->assertDontSee('98765.123456')->assertDontSee('87654.654321')
            ->assertDontSee('default_purchase_price_base')->assertDontSee('default_sale_price_base');
    }

    public function test_foreign_catalog_and_product_are_not_selectable(): void
    {
        $foreignOwner = User::factory()->create();
        app(CompanyContext::class)->clear();
        $foreignCompany = app(CreateCompanyAction::class)->execute($foreignOwner, ['name_ar' => 'شركة أخرى', 'base_currency_code' => 'ILS']);
        $this->actingAs($foreignOwner);
        app(CompanyContext::class)->setCompany($foreignCompany, $foreignOwner);
        $foreignProduct = app(ProductCatalogService::class)->createProduct($foreignCompany, ['name_ar' => 'منتج خاص',
            'sku' => 'FOREIGN-CAT', 'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => false,
            'base_unit_id' => DB::table('units')->where('company_id', $foreignCompany->id)->where('code', 'piece')->value('id')], $foreignOwner->id);
        $foreignCatalog = app(CatalogService::class)->save(null, $this->fields(), [['product_id' => $foreignProduct->id, 'unit_id' => $foreignProduct->base_unit_id]]);
        $this->activate($this->owner);
        Livewire::test(CatalogComposer::class, ['publicId' => $foreignCatalog->public_id])->assertNotFound();
        Livewire::test(CatalogComposer::class)->call('addProduct', $foreignProduct->id)->assertNotFound();
        $this->assertSame(1, Catalog::withoutGlobalScopes()->findOrFail($foreignCatalog->id)->draft_revision);
    }

    public function test_locked_catalog_identity_cannot_be_replaced_by_client(): void
    {
        $catalog = $this->catalog();
        $component = Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('catalogId', 999999);
    }

    public function test_locked_preview_payload_cannot_be_replaced_by_client(): void
    {
        $catalog = $this->catalog();
        $component = Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id])->call('previewPublication');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('previewPayload', ['currency_code' => 'ILS', 'items' => []]);
    }

    /** @return array<string,string> */
    private function economicFingerprint(): array
    {
        $result = [];
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'customer_payments', 'vendor_payments', 'document_sequences'] as $table) {
            $result[$table] = hash('sha256', DB::table($table)->where('company_id', $this->company->id)->orderBy('id')->get()->toJson());
        }

        return $result;
    }
}
