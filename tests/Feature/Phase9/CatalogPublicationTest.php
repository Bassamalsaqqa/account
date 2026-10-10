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
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
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

        // Manually mark expired with access_profile catalog_v1 and original password
        $link['share']->update([
            'access_profile' => 'catalog_v1',
            'password_hash' => Hash::make('original-password'),
            'expires_at' => now()->subDay(),
        ]);

        // linkSettings must be truthful legacy
        $oldSettings = $service->linkSettings($catalog->id);
        $this->assertTrue($oldSettings['has_password']);
        $this->assertArrayHasKey('expires_at', $oldSettings);
        $this->assertArrayHasKey('timezone', $oldSettings);
        $this->assertArrayHasKey('legacy_expiry', $oldSettings);
        $this->assertSame($this->company->timezone ?? 'Asia/Hebron', $oldSettings['timezone']);
        $this->assertTrue($oldSettings['legacy_expiry']);
        $this->assertNotNull($oldSettings['expires']);
        $this->assertNotNull($oldSettings['expires_at']);

        // Renew deliberately with date now(Company timezone) + 2 days using OLDstate and replacement password
        $newDate = now($this->company->timezone)->addDays(2)->format('Y-m-d');
        $revision = $catalog->fresh()->published_hash;
        $url = $service->updateLinkAccess($catalog->id, $oldSettings['state'], $newDate, 'replacement-password');

        // Assert stable URL, unchanged published revision, profile converted to catalog_v2, new date label
        $this->assertSame($link['url'], $url);
        $this->assertSame($revision, $catalog->fresh()->published_hash);
        $renewedGrant = $link['share']->fresh();
        $this->assertSame('catalog_v2', $renewedGrant->access_profile);
        $this->assertTrue(Hash::check('replacement-password', $renewedGrant->password_hash));

        $renewedSettings = $service->linkSettings($catalog->id);
        $this->assertSame($newDate, $renewedSettings['expires']);
        $this->assertFalse($renewedSettings['legacy_expiry']); // Converted v2 must not be labeled as legacy

        // Exact identical retry converges
        $this->assertSame($url, $service->updateLinkAccess($catalog->id, $oldSettings['state'], $newDate, 'replacement-password'));

        // Stale OLDstate with changed date or password must reject
        try {
            $service->updateLinkAccess($catalog->id, $oldSettings['state'], now($this->company->timezone)->addDays(4)->format('Y-m-d'), 'different-password');
            $this->fail('Stale OLDstate with changed date/password must reject.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('changed', $e->getMessage());
        }

        // Freshstate clear expiry preserves replacement password
        $freshSettings = $service->linkSettings($catalog->id);
        $service->updateLinkAccess($catalog->id, $freshSettings['state'], null);
        $this->assertTrue(Hash::check('replacement-password', $link['share']->fresh()->password_hash));
        $this->assertNull($link['share']->fresh()->expires_at);

        // Original password denied; replacement password unlocks JSON EN Food Item
        $this->get($url.'?format=json')->assertOk()->assertDontSee('Food Item');
        $this->post($url, ['password' => 'original-password'])->assertNotFound();
        $this->post($url, ['password' => 'replacement-password'])->assertRedirect();
        $this->get($url.'?locale=en&format=json')->assertOk()->assertJsonPath('items.0.name', 'Food Item');

        // Paused catalog rejects access update
        $freshAfterUnlock = $service->linkSettings($catalog->id);
        $service->state($catalog->id, 'paused');
        $this->expectException(InvalidArgumentException::class);
        $service->updateLinkAccess($catalog->id, $freshAfterUnlock['state'], null);
    }

    public function test_genuine_catalog_v1_timestamp_where_local_differs_from_utc_preserves_db_instant_and_profile_on_password_edit(): void
    {
        $catalog = $this->catalog();
        $link = $this->publish($catalog);
        $service = app(CatalogService::class);

        // In Asia/Hebron (UTC+03:00): 2026-10-15 22:30:00 UTC is 2026-10-16 01:30:00 local time.
        // UTC date is 2026-10-15, but local date in Asia/Hebron is 2026-10-16.
        $storedUtc = Carbon::parse('2026-10-15 22:30:00', 'UTC');
        $link['share']->update([
            'access_profile' => 'catalog_v1',
            'expires_at' => $storedUtc,
            'password_hash' => Hash::make('old-v1-secret'),
        ]);

        $settings = $service->linkSettings($catalog->id);
        $this->assertTrue($settings['legacy_expiry']);
        $this->assertSame('2026-10-16', $settings['expires']);
        $this->assertStringContainsString('2026-10-16T01:30:00+03:00', (string) $settings['expires_at']);

        // Password-only edit using linkSettings['expires'] preserves exact DB timestamp and access_profile
        $service->updateLinkAccess($catalog->id, $settings['state'], $settings['expires'], 'new-v1-secret');

        $fresh = $link['share']->fresh();
        $this->assertSame('catalog_v1', $fresh->access_profile);
        $this->assertTrue(Hash::check('new-v1-secret', $fresh->password_hash));
        $this->assertSame('2026-10-15 22:30:00', $fresh->expires_at?->format('Y-m-d H:i:s'));

        // No expiry read or recovery rewrites the timestamp
        $recovered = $service->share($catalog->id, 'new-v1-secret', $settings['expires']);
        $this->assertSame($fresh->id, $recovered['share']->id);
        $this->assertSame('2026-10-15 22:30:00', $link['share']->fresh()->expires_at?->format('Y-m-d H:i:s'));
    }

    public function test_actual_share_today_near_midnight_and_recovery_compares_company_date_in_hebron_and_new_york(): void
    {
        $service = app(CatalogService::class);

        // 1. Asia/Hebron (+03:00) near 23:30 local time
        $catalogHebron = $this->catalog();
        $previewHebron = $service->preview($catalogHebron->id);
        $service->publish($catalogHebron->id, $previewHebron['revision'], $previewHebron['hash'], 'pub-today-hebron');

        Carbon::setTestNow(Carbon::parse('2026-10-15 23:30:00', 'Asia/Hebron'));
        try {
            // Actual share TODAY date near 23:30 local -> next local day in UTC
            // 2026-10-16 00:00:00 Asia/Hebron = 2026-10-15 21:00:00 UTC
            $linkHebron = $service->share($catalogHebron->id, 'pass-hebron-1', '2026-10-15');
            $grantHebron = $linkHebron['share']->fresh();
            $rawTokenHebron = Crypt::decryptString($grantHebron->encrypted_token);

            $expectedHebronUtc = Carbon::parse('2026-10-16 00:00:00', 'Asia/Hebron')->setTimezone('UTC');
            $this->assertSame($expectedHebronUtc->toIso8601String(), $grantHebron->expires_at?->toIso8601String());

            // Valid at last second (23:59:59 local = 20:59:59 UTC)
            Carbon::setTestNow(Carbon::parse('2026-10-15 23:59:59', 'Asia/Hebron'));
            $this->assertFalse($grantHebron->fresh()->isExpired());
            $this->assertTrue($grantHebron->fresh()->isValid());
            $this->assertSame($grantHebron->id, $service->resolve($rawTokenHebron)->id);

            // Denied at exact boundary (00:00:00 local = 21:00:00 UTC)
            Carbon::setTestNow(Carbon::parse('2026-10-16 00:00:00', 'Asia/Hebron'));
            $this->assertTrue($grantHebron->fresh()->isExpired());
            $this->assertFalse($grantHebron->fresh()->isValid());
            try {
                $service->resolve($rawTokenHebron);
                $this->fail('Expired grant at exact boundary was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Unavailable', $e->getMessage());
            }

            // updateLinkAccess similarly sets next local day UTC
            Carbon::setTestNow(Carbon::parse('2026-10-16 23:30:00', 'Asia/Hebron'));
            $settingsHebron = $service->linkSettings($catalogHebron->id);
            $service->updateLinkAccess($catalogHebron->id, $settingsHebron['state'], '2026-10-16', 'pass-hebron-1');
            $expectedHebronNext = Carbon::parse('2026-10-17 00:00:00', 'Asia/Hebron')->setTimezone('UTC');
            $this->assertSame($expectedHebronNext->toIso8601String(), $grantHebron->fresh()->expires_at?->toIso8601String());

            // Recovery compares correct Company date
            Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00', 'Asia/Hebron'));
            $recoveredHebron = $service->share($catalogHebron->id, 'pass-hebron-1', '2026-10-16');
            $this->assertSame($grantHebron->id, $recoveredHebron['share']->id);
            try {
                $service->share($catalogHebron->id, 'pass-hebron-1', '2026-10-17');
                $this->fail('Recovery accepted wrong company date.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Recovery', $e->getMessage());
            }
        } finally {
            Carbon::setTestNow();
        }

        // 2. America/New_York (DST day, EDT UTC-04:00)
        $this->company->update(['timezone' => 'America/New_York']);
        $catalogNy = $this->catalog();
        $previewNy = $service->preview($catalogNy->id);
        $service->publish($catalogNy->id, $previewNy['revision'], $previewNy['hash'], 'pub-today-ny');

        Carbon::setTestNow(Carbon::parse('2026-10-15 23:30:00', 'America/New_York'));
        try {
            // Actual share TODAY date near 23:30 local in America/New_York (unpassworded)
            // 2026-10-16 00:00:00 EDT = 2026-10-16 04:00:00 UTC
            $linkNy = $service->share($catalogNy->id, null, '2026-10-15');
            $grantNy = $linkNy['share']->fresh();
            $rawTokenNy = Crypt::decryptString($grantNy->encrypted_token);

            $expectedNyUtc = Carbon::parse('2026-10-16 00:00:00', 'America/New_York')->setTimezone('UTC');
            $this->assertSame($expectedNyUtc->toIso8601String(), $grantNy->expires_at?->toIso8601String());

            // Valid at last second (23:59:59 EDT = 03:59:59 UTC)
            Carbon::setTestNow(Carbon::parse('2026-10-15 23:59:59', 'America/New_York'));
            $this->assertFalse($grantNy->fresh()->isExpired());
            $this->assertTrue($grantNy->fresh()->isValid());
            $this->assertSame($grantNy->id, $service->resolve($rawTokenNy)->id);

            // Denied at exact boundary (00:00:00 EDT = 04:00:00 UTC)
            Carbon::setTestNow(Carbon::parse('2026-10-16 00:00:00', 'America/New_York'));
            $this->assertTrue($grantNy->fresh()->isExpired());
            $this->assertFalse($grantNy->fresh()->isValid());
            try {
                $service->resolve($rawTokenNy);
                $this->fail('Expired grant at exact boundary was accepted in NY.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Unavailable', $e->getMessage());
            }

            // Recovery unpassworded compares correct Company date
            Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'America/New_York'));
            $recoveredNy = $service->share($catalogNy->id, null, '2026-10-15');
            $this->assertSame($grantNy->id, $recoveredNy['share']->id);
            try {
                $service->share($catalogNy->id, null, '2026-10-16');
                $this->fail('Recovery accepted wrong company date in NY.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Recovery', $e->getMessage());
            }
        } finally {
            $this->company->update(['timezone' => 'Asia/Hebron']);
            Carbon::setTestNow();
        }
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

    public function test_company_timezone_change_labels_existing_instant_truthfully_without_renewing_it(): void
    {
        $this->company->update(['timezone' => 'Asia/Hebron']);
        $catalog = $this->catalog();
        $service = app(CatalogService::class);
        $this->publish($catalog);
        $state = $service->linkSettings($catalog->id)['state'];
        $date = now('Asia/Hebron')->addDays(4)->format('Y-m-d');
        $service->updateLinkAccess($catalog->id, $state, $date);
        $grant = PublicShare::where('subject_type', 'product_catalog')->where('subject_id', $catalog->id)->firstOrFail();
        $instant = $grant->expires_at->timestamp;
        $this->assertFalse($service->linkSettings($catalog->id)['legacy_expiry']);
        $this->company->update(['timezone' => 'Pacific/Honolulu']);
        $settings = $service->linkSettings($catalog->id);
        $this->assertTrue($settings['legacy_expiry']);
        $this->assertSame($grant->expires_at->copy()->setTimezone('Pacific/Honolulu')->toIso8601String(), $settings['expires_at']);
        $url = $service->updateLinkAccess($catalog->id, $settings['state'], $settings['expires'], 'timezone-secret');
        $this->assertSame($instant, $grant->fresh()->expires_at->timestamp);
        $this->assertSame($url, $service->share($catalog->id, 'timezone-secret', $settings['expires'])['url']);
        $this->assertSame('catalog_v2', $grant->fresh()->access_profile);
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

    public function test_share_recovery_refuses_to_create_after_all_grants_are_retired(): void
    {
        $catalog = $this->catalog();
        $service = app(CatalogService::class);
        $link1 = $this->publish($catalog);

        // Active recovery succeeds and returns identical share
        $linkRecovered = $service->share($catalog->id);
        $this->assertSame($link1['share']->id, $linkRecovered['share']->id);
        $this->assertSame($link1['url'], $linkRecovered['url']);

        // Expired active grant cannot be silently renewed via share(); requires updateLinkAccess
        $link1['share']->update(['expires_at' => now()->subDay()]);
        try {
            $service->share($catalog->id);
            $this->fail('Share recovery bypassed expired active grant.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('unavailable', $e->getMessage());
        }

        // Terminal revocation retires the active grant
        $service->state($catalog->id, 'revoked');
        $this->assertFalse($link1['share']->fresh()->is_active);
        $this->assertNotNull($link1['share']->fresh()->revoked_at);

        // Resume to active does not revive retired grant
        $service->state($catalog->id, 'active');

        // share() strictly refuses to create a new grant after retired history
        $this->expectException(InvalidArgumentException::class);
        $service->share($catalog->id);
    }

    public function test_publication_receipts_are_immutable_and_preserved_across_republication_and_revocation(): void
    {
        $catalog = $this->catalog();
        $service = app(CatalogService::class);
        $preview1 = $service->preview($catalog->id);
        $service->publish($catalog->id, $preview1['revision'], $preview1['hash'], 'receipt-pub-01');

        $this->assertSame(1, DB::table('catalog_publications')->where('catalog_id', $catalog->id)->count());
        $receipt1 = DB::table('catalog_publications')->where('catalog_id', $catalog->id)->first();
        $this->assertSame('receipt-pub-01', $receipt1->request_key);
        $this->assertSame($preview1['hash'], $receipt1->content_hash);

        // Revoke catalog
        $service->state($catalog->id, 'revoked');

        // Modify draft and publish second approved revision
        $service->state($catalog->id, 'active');
        $service->save($catalog->id, $this->fields(), $this->items(price: null), 1);
        $preview2 = $service->preview($catalog->id);
        $service->publish($catalog->id, $preview2['revision'], $preview2['hash'], 'receipt-pub-02');

        $this->assertSame(2, DB::table('catalog_publications')->where('catalog_id', $catalog->id)->count());
        $receipts = DB::table('catalog_publications')->where('catalog_id', $catalog->id)->orderBy('id')->get();
        $this->assertSame('receipt-pub-01', $receipts[0]->request_key);
        $this->assertSame('receipt-pub-02', $receipts[1]->request_key);
        $this->assertSame($receipt1->payload, $receipts[0]->payload);
        $this->assertSame($preview2['hash'], $receipts[1]->content_hash);
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
