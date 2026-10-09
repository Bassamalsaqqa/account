<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Actions\Company\CreateCompanyAction;
use App\Models\Catalog;
use App\Models\CompanyCurrency;
use App\Models\ProductImage;
use App\Models\PublicShare;
use App\Models\User;
use App\Services\Catalogs\CatalogService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Inventory\ProductImageService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Phase5E\Phase5ETestCase;

class CatalogPublicationTest extends Phase5ETestCase
{
    /** @return array<string,mixed> */
    private function fields(bool $priced = false): array
    {
        return ['name_ar' => 'كتالوج مختار', 'name_en' => 'Selected catalog', 'description_ar' => 'منتجات مختارة', 'description_en' => 'Selected Products',
            'locale' => 'ar', 'show_prices' => $priced, 'show_sku' => true, 'show_description' => true, 'show_images' => true,
            'currency_code' => $priced ? 'USD' : null, 'tax_basis' => $priced ? 'Tax included' : null];
    }

    /** @return list<array<string,mixed>> */
    private function items(?ProductImage $image = null, ?string $price = null): array
    {
        return [['product_id' => $this->product->id, 'unit_id' => $this->unit->unit_id, 'image_id' => $image?->id, 'custom_price' => $price]];
    }

    private function catalog(bool $priced = false, ?ProductImage $image = null): Catalog
    {
        return app(CatalogService::class)->save(null, $this->fields($priced), $this->items($image, $priced ? '12.50' : null));
    }

    /** @return array{share:PublicShare,url:string} */
    private function publish(Catalog $catalog, string $key = 'first-publication'): array
    {
        $service = app(CatalogService::class);
        $preview = $service->preview($catalog->id);
        $service->publish($catalog->id, $preview['revision'], $preview['hash'], $key);

        return $service->share($catalog->id);
    }

    public function test_prices_off_whitelist_and_immutable_text_survive_master_and_draft_changes(): void
    {
        $catalog = $this->catalog();
        $link = $this->publish($catalog);
        $service = app(CatalogService::class);
        $before = $service->publicData($link['share'], 'en');
        $this->assertSame('Food Item', $before['items'][0]['name']);
        foreach (['price', 'currency_code', 'tax_basis', 'cost', 'stock', 'product_id', 'unit_id', 'media', 'supplier', 'profit'] as $key) {
            $this->assertArrayNotHasKey($key, $before);
            $this->assertArrayNotHasKey($key, $before['items'][0]);
        }
        $this->product->update(['name_en' => 'UNAPPROVED MASTER', 'default_sale_price_base' => '777', 'default_purchase_cost_base' => '666']);
        $service->save($catalog->id, $this->fields() + [], [['product_id' => $this->product->id, 'unit_id' => $this->unit->unit_id, 'name_en' => 'UNAPPROVED DRAFT']], 1);
        $this->assertSame($before, $service->publicData($link['share'], 'en'));
        $preview = $service->preview($catalog->id);
        $service->publish($catalog->id, $preview['revision'], $preview['hash'], 'approved-update');
        $this->assertSame($link['url'], $service->share($catalog->id)['url']);
        $this->assertSame('UNAPPROVED DRAFT', $service->publicData($link['share'], 'en')['items'][0]['name']);
        $this->product->update(['active' => false]);
        $this->assertSame([], $service->publicData($link['share'], 'en')['items']);
    }

    public function test_expired_managed_link_can_be_deliberately_renewed_without_changing_approved_revision_or_url(): void
    {
        $catalog = $this->catalog();
        $link = $this->publish($catalog);
        $service = app(CatalogService::class);
        $link['share']->update(['password_hash' => Hash::make('original-password'), 'expires_at' => now()->subDay()]);
        $settings = $service->linkSettings($catalog->id);
        $this->assertTrue($settings['has_password']);
        $revision = $catalog->fresh()->published_hash;
        $url = $service->updateLinkAccess($catalog->id, $settings['state'], now()->addDays(2)->format('Y-m-d'), 'replacement-password');
        $this->assertSame($link['url'], $url);
        $this->assertSame($revision, $catalog->fresh()->published_hash);
        $this->assertTrue(Hash::check('replacement-password', $link['share']->fresh()->password_hash));
        $this->assertSame($url, $service->updateLinkAccess($catalog->id, $settings['state'], now()->addDays(2)->format('Y-m-d'), 'replacement-password'));
        try {
            $service->updateLinkAccess($catalog->id, $settings['state'], now()->addDays(3)->format('Y-m-d'), 'another-password');
            $this->fail('A stale edit changed catalog access settings.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('changed', $e->getMessage());
        }
        $fresh = $service->linkSettings($catalog->id);
        $service->updateLinkAccess($catalog->id, $fresh['state'], null);
        $this->assertTrue(Hash::check('replacement-password', $link['share']->fresh()->password_hash));
        $this->assertNull($link['share']->fresh()->expires_at);
        $this->get($url.'?format=json')->assertOk()->assertDontSee('Food Item');
        $this->post($url, ['password' => 'original-password'])->assertNotFound();
        $this->post($url, ['password' => 'replacement-password'])->assertRedirect();
        $this->get($url.'?format=json')->assertOk();
        $fresh = $service->linkSettings($catalog->id);
        $service->updateLinkAccess($catalog->id, $fresh['state'], now()->addDays(3)->format('Y-m-d'), 'newer-password');
        $this->get($url.'?format=json')->assertOk()->assertDontSee('Food Item');
        $service->state($catalog->id, 'paused');
        $this->expectException(InvalidArgumentException::class);
        $service->updateLinkAccess($catalog->id, $fresh['state'], null);
    }

    public function test_image_identity_primary_changes_deletion_and_replacement_fail_safe(): void
    {
        Storage::fake('public');
        $images = app(ProductImageService::class);
        $imageA = $images->storeImage($this->product, UploadedFile::fake()->image('a.png', 60, 60), $this->owner, true);
        $catalog = $this->catalog(image: $imageA);
        $link = $this->publish($catalog);
        $service = app(CatalogService::class);
        $before = $service->publicData($link['share'], 'en');
        $this->assertStringContainsString($imageA->thumbnail_path, $before['items'][0]['image']);
        $imageB = $images->storeImage($this->product, UploadedFile::fake()->image('b.png', 70, 70), $this->owner, true);
        $this->assertSame($before, $service->publicData($link['share'], 'en'));
        Storage::disk('public')->put($imageA->thumbnail_path, Storage::disk('public')->get($imageB->thumbnail_path));
        $this->assertNull($service->publicData($link['share'], 'en')['items'][0]['image']);
        $images->deleteImage($imageA, $this->owner);
        $this->assertNull($service->publicData($link['share'], 'en')['items'][0]['image']);
        $service->save($catalog->id, $this->fields(), $this->items($imageB), 1);
        $preview = $service->preview($catalog->id);
        $this->product->update(['name_en' => 'Changed after preview']);
        try {
            $service->publish($catalog->id, $preview['revision'], $preview['hash'], 'stale-media');
            $this->fail('Stale preview accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('changed since preview', $exception->getMessage());
        }
        $preview = $service->preview($catalog->id);
        $service->publish($catalog->id, $preview['revision'], $preview['hash'], 'image-b-approved');
        $this->assertStringContainsString($imageB->thumbnail_path, $service->publicData($link['share'], 'en')['items'][0]['image']);
        $this->assertSame($link['url'], $service->share($catalog->id)['url']);
    }

    public function test_delayed_exact_publication_retry_does_not_create_a_third_revision(): void
    {
        $catalog = $this->catalog();
        $service = app(CatalogService::class);
        $preview = $service->preview($catalog->id);
        foreach (['k1', 'k2', 'k1'] as $key) {
            $service->publish($catalog->id, $preview['revision'], $preview['hash'], $key);
        }
        $this->assertSame(2, $catalog->fresh()->published_revision);
        $this->assertSame(2, DB::table('catalog_publications')->where('catalog_id', $catalog->id)->count());
        try {
            $service->publish($catalog->id, 999, $preview['hash'], 'k1');
            $this->fail('Changed intent accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('conflicts', $exception->getMessage());
        }
        $other = $this->catalog();
        $otherPreview = $service->preview($other->id);
        $this->expectException(InvalidArgumentException::class);
        $service->publish($other->id, $otherPreview['revision'], $otherPreview['hash'], 'k1');
    }

    public function test_priced_catalog_requires_price_authority_and_current_unit_currency_eligibility(): void
    {
        $catalog = $this->catalog(true);
        $link = $this->publish($catalog);
        $service = app(CatalogService::class);
        $data = $service->publicData($link['share'], 'en');
        $this->assertSame('12.500000', $data['items'][0]['price']);
        $this->assertSame('USD', $data['currency_code']);
        $this->assertSame('Tax included', $data['tax_basis']);
        $preview = $service->preview($catalog->id);
        $actor = $this->customActor(['catalogs.view', 'catalogs.publish', 'catalogs.manage', 'inventory.stock.view']);
        $this->activate($actor);
        try {
            $service->publish($catalog->id, $preview['revision'], $preview['hash'], 'first-publication');
            $this->fail('Priced retry bypassed current permission.');
        } catch (AuthorizationException) {
            $this->assertSame(1, $catalog->fresh()->published_revision);
        }
        $this->activate($this->owner);
        $this->unit->update(['conversion_to_base' => '2']);
        try {
            $service->publicData($link['share'], 'en');
            $this->fail('Changed Unit meaning retained a priced output.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Unit configuration', $exception->getMessage());
        }
        $this->unit->update(['conversion_to_base' => '1']);
        CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['enabled' => false]);
        $this->expectException(InvalidArgumentException::class);
        $service->publicData($link['share'], 'en');
    }

    public function test_more_than_100_products_remain_selectable_ordered_and_paginated(): void
    {
        $service = app(CatalogService::class);
        $items = [];
        for ($i = 1; $i <= 125; $i++) {
            $product = app(ProductCatalogService::class)->createProduct($this->company, ['name_ar' => 'منتج '.$i, 'name_en' => 'Product '.$i, 'sku' => 'CAT-'.$i,
                'product_type' => 'service', 'base_unit_id' => $this->unit->unit_id], $this->owner->id);
            $items[] = ['product_id' => $product->id, 'unit_id' => $this->unit->unit_id];
        }
        $catalog = $service->save(null, $this->fields(), array_reverse($items));
        $link = $this->publish($catalog);
        $first = $service->publicData($link['share'], 'en');
        $last = $service->publicData($link['share'], 'en', 6);
        $this->assertCount(24, $first['items']);
        $this->assertSame('Product 125', $first['items'][0]['name']);
        $this->assertSame(6, $first['pages']);
        $this->assertCount(5, $last['items']);
        $this->assertSame('Product 1', $last['items'][4]['name']);
        $this->assertCount(125, $service->publicData($link['share'], 'en', 1, true)['items']);
    }

    public function test_tenant_foreign_keys_and_selection_permissions_are_enforced(): void
    {
        $catalog = $this->catalog();
        app(CompanyContext::class)->clear();
        $foreignOwner = User::factory()->create();
        $foreign = app(CreateCompanyAction::class)->execute($foreignOwner, ['name_ar' => 'شركة أخرى', 'base_currency_code' => 'ILS']);
        $this->activate($this->owner);
        try {
            DB::table('catalog_items')->insert(['company_id' => $foreign->id, 'catalog_id' => $catalog->id, 'product_id' => $this->product->id, 'unit_id' => $this->unit->unit_id, 'position' => 2]);
            $this->fail('Cross-company FK accepted.');
        } catch (QueryException) {
            $this->assertSame(1, $catalog->items()->count());
        }
        $actor = $this->customActor(['catalogs.view', 'catalogs.manage']);
        $this->activate($actor);
        $this->expectException(HttpException::class);
        app(CatalogService::class)->save(null, $this->fields(), $this->items());
    }

    public function test_forward_down_forward_preserves_catalogs_publications_grants_and_original_product_rows(): void
    {
        $catalog = $this->catalog();
        $this->publish($catalog);
        $before = [];
        foreach (['products', 'units', 'catalogs', 'catalog_items', 'catalog_publications', 'public_shares'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $migration = require database_path('migrations/2026_10_09_000002_create_managed_product_catalogs.php');
        $migration->down();
        $migration->up();
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
        }
    }
}
