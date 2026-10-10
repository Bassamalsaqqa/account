<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Catalogs\CatalogComposer;
use App\Models\Catalog;
use App\Models\Product;
use App\Models\PublicShare;
use App\Models\User;
use App\Services\Catalogs\CatalogService;
use App\Services\Inventory\ProductCatalogService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public function test_open_and_save_link_access_renews_expired_link_without_changing_url_or_revision(): void
    {
        $catalog = $this->catalog();
        Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id])
            ->call('previewPublication')->assertHasNoErrors()
            ->call('publish')->assertHasNoErrors();
        $this->assertSame('active', $catalog->fresh()->status);

        $link = app(CatalogService::class)->share($catalog->id, 'initial-secret-123', now()->addDay()->format('Y-m-d'));
        $share = PublicShare::where('company_id', $this->company->id)->where('subject_type', 'product_catalog')->where('subject_id', $catalog->id)->firstOrFail();
        $share->update(['access_profile' => 'catalog_v1', 'expires_at' => now()->subDay()]);
        $this->assertFalse($share->fresh()->isValid());

        $component = Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id]);
        $this->assertNull($component->get('url'));

        $component->call('openLinkAccess')->assertHasNoErrors();
        $this->assertTrue($component->get('hasPassword'));
        $this->assertSame(now()->subDay()->setTimezone($this->company->timezone)->format('Y-m-d'), $component->get('currentExpires'));
        $this->assertNotNull($component->get('expectedState'));

        $newExpiry = now()->addDays(7)->format('Y-m-d');
        $component->set('linkAccessExpires', $newExpiry)
            ->set('linkAccessPassword', '')
            ->call('updateLinkAccess')->assertHasNoErrors();

        $this->assertSame($link['url'], $component->get('url'));
        $this->assertSame($newExpiry, app(CatalogService::class)->linkSettings($catalog->id)['expires']);
        $this->assertSame(Carbon::parse($newExpiry, $this->company->timezone)->addDay()->startOfDay()->utc()->timestamp, $share->fresh()->expires_at?->timestamp);
        $this->assertTrue(Hash::check('initial-secret-123', $share->fresh()->password_hash));
        $this->assertTrue($share->fresh()->isValid());
        $this->assertSame(1, $catalog->fresh()->published_revision);
        $this->assertNull($component->get('qr'));
    }

    public function test_link_access_password_preserved_when_blank_and_updated_when_nonempty(): void
    {
        $catalog = $this->catalog();
        Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id])
            ->call('previewPublication')->assertHasNoErrors()
            ->call('publish')->assertHasNoErrors();

        app(CatalogService::class)->share($catalog->id, 'original-password-123', now()->addDays(2)->format('Y-m-d'));
        $share = PublicShare::where('company_id', $this->company->id)->where('subject_type', 'product_catalog')->where('subject_id', $catalog->id)->firstOrFail();

        $component = Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id])
            ->call('openLinkAccess')->assertHasNoErrors();

        $component->set('linkAccessPassword', 'replaced-password-456')
            ->call('updateLinkAccess')->assertHasNoErrors();

        $this->assertTrue(Hash::check('replaced-password-456', $share->fresh()->password_hash));

        $component->call('openLinkAccess')->assertHasNoErrors()
            ->set('linkAccessExpires', now()->addDays(10)->format('Y-m-d'))
            ->set('linkAccessPassword', '')
            ->call('updateLinkAccess')->assertHasNoErrors();

        $this->assertTrue(Hash::check('replaced-password-456', $share->fresh()->password_hash));
        $this->assertSame(now()->addDays(10)->format('Y-m-d'), app(CatalogService::class)->linkSettings($catalog->id)['expires']);

        // Deliberately clearing expiry must not recover an old value from another form field.
        $component->call('openLinkAccess')->set('linkAccessExpires', '')
            ->set('linkAccessPassword', '')->call('updateLinkAccess')->assertHasNoErrors();
        $this->assertNull($share->fresh()->expires_at);
        $this->assertTrue(Hash::check('replaced-password-456', $share->fresh()->password_hash));
    }

    public function test_stale_expected_state_rejected_during_concurrent_link_access_edit(): void
    {
        $catalog = $this->catalog();
        Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id])
            ->call('previewPublication')->assertHasNoErrors()
            ->call('publish')->assertHasNoErrors();

        app(CatalogService::class)->share($catalog->id, 'password-123', now()->addDays(2)->format('Y-m-d'));

        $component = Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id])
            ->call('openLinkAccess')->assertHasNoErrors();

        $service = app(CatalogService::class);
        $settings = $service->linkSettings($catalog->id);
        $service->updateLinkAccess($catalog->id, $settings['state'], now()->addDays(3)->format('Y-m-d'), 'concurrent-pwd-999');

        $component->set('linkAccessExpires', now()->addDays(4)->format('Y-m-d'))
            ->call('updateLinkAccess')->assertHasErrors('linkAccess');
    }

    public function test_open_link_access_denied_when_share_permission_revoked(): void
    {
        $catalog = $this->catalog();
        $actor = $this->customActor(['catalogs.view', 'catalogs.manage', 'inventory.stock.view']);
        $this->activate($actor);

        Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id])
            ->call('openLinkAccess')->assertForbidden();
    }

    public function test_foreign_catalog_denied_for_link_access(): void
    {
        $foreignOwner = User::factory()->create();
        app(CompanyContext::class)->clear();
        $foreignCompany = app(CreateCompanyAction::class)->execute($foreignOwner, ['name_ar' => 'شركة أخرى 2', 'base_currency_code' => 'ILS']);
        $this->actingAs($foreignOwner);
        app(CompanyContext::class)->setCompany($foreignCompany, $foreignOwner);
        $foreignProduct = app(ProductCatalogService::class)->createProduct($foreignCompany, ['name_ar' => 'منتج خارجي',
            'sku' => 'FOREIGN-P2', 'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => false,
            'base_unit_id' => DB::table('units')->where('company_id', $foreignCompany->id)->where('code', 'piece')->value('id')], $foreignOwner->id);
        $foreignCatalog = app(CatalogService::class)->save(null, $this->fields(), [['product_id' => $foreignProduct->id, 'unit_id' => $foreignProduct->base_unit_id]]);

        $this->activate($this->owner);
        Livewire::test(CatalogComposer::class, ['publicId' => $foreignCatalog->public_id])->assertNotFound();
    }

    public function test_locked_link_access_state_cannot_be_tampered_by_client(): void
    {
        $catalog = $this->catalog();
        $component = Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('expectedState', 'tampered-state-hash');
    }

    public function test_terminal_revoke_clears_delivery_controls_and_only_explicit_new_link_replaces_grant(): void
    {
        config(['app.url' => 'https://accounting.test']);
        $catalog = $this->catalog();
        $component = Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id])
            ->call('previewPublication')->call('publish')->call('createOrRecoverLink')->assertHasNoErrors();
        $oldUrl = $component->get('url');
        $oldGrant = PublicShare::where('subject_type', 'product_catalog')->where('subject_id', $catalog->id)->firstOrFail();
        $component->call('showQr')->assertHasNoErrors()->assertSet('showQrModal', true)
            ->call('manageState', 'paused')->assertHasNoErrors()->assertSet('url', null)
            ->assertSet('qr', null)->assertSet('showQrModal', false)
            ->call('manageState', 'active')->call('createOrRecoverLink')->assertHasNoErrors()->assertSet('url', $oldUrl)
            ->call('manageState', 'revoked')->assertHasNoErrors()->assertSet('url', null)
            ->assertSet('hasExistingShare', false)->call('manageState', 'active')->assertHasNoErrors()
            ->call('createOrRecoverLink')->assertHasErrors('share')->assertSet('url', null)
            ->assertSee(__('catalogs.link_retired_notice'));
        $this->assertFalse($oldGrant->fresh()->isValid());
        $component->call('openNewLink')->assertSet('showNewLinkModal', true)
            ->set('newLinkExpires', now($this->company->timezone)->format('Y-m-d'))
            ->set('newLinkPassword', 'replacement-secret')->call('issueNewLink')->assertHasNoErrors()
            ->assertSet('showNewLinkModal', false)->assertSet('status', 'active');
        $this->assertNotSame($oldUrl, $component->get('url'));
        $this->assertFalse($oldGrant->fresh()->isValid());
        $this->assertSame(2, PublicShare::where('subject_type', 'product_catalog')->where('subject_id', $catalog->id)->count());
    }

    public function test_mounted_new_link_action_rechecks_publication_authority(): void
    {
        $catalog = $this->catalog();
        $component = Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id])
            ->call('previewPublication')->call('publish')->call('openNewLink')->assertHasNoErrors();
        $actor = $this->customActor(['catalogs.view', 'catalogs.share', 'inventory.stock.view']);
        $this->activate($actor);
        $component->call('issueNewLink')->assertForbidden();
        $this->assertSame(0, PublicShare::where('subject_type', 'product_catalog')->where('subject_id', $catalog->id)->count());
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
