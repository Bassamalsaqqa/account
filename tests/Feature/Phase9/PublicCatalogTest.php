<?php

declare(strict_types=1);

namespace Tests\Feature\Phase9;

use App\Actions\Company\CreateCompanyAction;
use App\Models\Catalog;
use App\Models\ProductImage;
use App\Models\PublicShare;
use App\Models\User;
use App\Services\Catalogs\CatalogService;
use App\Services\Inventory\ProductImageService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Phase5E\Phase5ETestCase;

class PublicCatalogTest extends Phase5ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('catalog-ip:'.hash('sha256', '127.0.0.1'));
    }

    /** @return array{catalog:Catalog,share:PublicShare,url:string} */
    private function publish(?string $password = null, ?string $expires = null, ?ProductImage $image = null): array
    {
        $service = app(CatalogService::class);
        $catalog = $service->save(null, [
            'name_ar' => 'كتالوج المنتجات المعتمد', 'name_en' => 'Approved product catalog',
            'description_ar' => 'منتجات مختارة', 'description_en' => 'Selected marketing Products',
            'locale' => 'ar', 'show_prices' => false, 'show_sku' => true, 'show_description' => true, 'show_images' => true,
        ], [['product_id' => $this->product->id, 'unit_id' => $this->unit->unit_id, 'image_id' => $image?->id]]);
        $preview = $service->preview($catalog->id);
        $service->publish($catalog->id, $preview['revision'], $preview['hash'], 'publish-'.$catalog->public_id);

        return ['catalog' => $catalog, ...$service->share($catalog->id, $password, $expires)];
    }

    public function test_prices_off_are_absent_from_html_json_and_actual_pdf_input_without_economic_writes(): void
    {
        $this->product->update(['default_sale_price_base' => '98765.43', 'default_purchase_cost_base' => '87654.32']);
        $link = $this->publish();
        auth()->logout();
        $before = $this->economicFingerprint();
        $json = $this->get($link['url'].'?locale=en&format=json')->assertOk()->json();
        $this->assertPublicWhitelist($json);
        foreach (['', '&format=print'] as $format) {
            $response = $this->get($link['url'].'?locale=en'.$format)->assertOk()->assertSee('Food Item');
            foreach (['98765.43', '87654.32', 'price-badge">', 'Tax included'] as $private) {
                $response->assertDontSee($private, false);
            }
            $this->assertPrivateHeaders($response);
        }
        $captured = [];
        View::composer('pdf.catalog', function ($view) use (&$captured): void {
            $captured[] = $view->getData()['data'];
        });
        foreach (['ar', 'en'] as $locale) {
            $response = $this->get($link['url'].'?locale='.$locale.'&format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertPrivateHeaders($response);
            $directory = base_path('.ai/delegations/phase9-implementation/pdf-evidence');
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            file_put_contents($directory.'/public-catalog-'.$locale.'.pdf', $response->getContent());
        }
        $this->assertCount(2, $captured);
        foreach ($captured as $dto) {
            $this->assertPublicWhitelist($dto);
        }
        $this->assertSame($before, $this->economicFingerprint());
    }

    public function test_password_gate_cannot_be_bypassed_by_get_head_formats_or_query_password(): void
    {
        $link = $this->publish('catalog-secret');
        auth()->logout();
        foreach (['', '?format=json', '?format=pdf', '?format=print', '?password=catalog-secret&format=json'] as $suffix) {
            $response = $this->get($link['url'].$suffix)->assertOk()->assertDontSee('Food Item')->assertDontSee('Approved product catalog');
            $this->assertPrivateHeaders($response);
            $this->head($link['url'].$suffix)->assertOk()->assertContent('');
        }
        $this->assertSame(0, $link['share']->fresh()->view_count);
        $this->post($link['url'], ['password' => 'incorrect-password'])->assertNotFound();
        $this->get($link['url'].'?format=json')->assertDontSee('Food Item');
        $this->post($link['url'], ['password' => 'catalog-secret'])->assertRedirect();
        $this->get($link['url'].'?locale=en&format=json')->assertOk()->assertJsonPath('items.0.name', 'Food Item');
        $this->get($link['url'].'?locale=en&format=print')->assertOk()->assertSee('Food Item');
        $this->get($link['url'].'?locale=en&format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_password_unlock_requires_real_csrf_and_is_share_password_and_time_bound(): void
    {
        $first = $this->publish('catalog-secret');
        $second = $this->publish('catalog-secret');
        auth()->logout();
        $this->app->instance('env', 'phase9-catalog-csrf-test');
        try {
            $this->post($first['url'], ['password' => 'catalog-secret'])->assertStatus(419)->assertDontSee('Food Item');
            $this->withSession(['_token' => 'catalog-csrf-token'])->post($first['url'], ['_token' => 'catalog-csrf-token', 'password' => 'catalog-secret'])->assertRedirect();
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->get($first['url'].'?locale=en&format=json')->assertJsonPath('items.0.name', 'Food Item');
        $this->get($second['url'].'?locale=en&format=json')->assertDontSee('Food Item');
        $first['share']->update(['password_hash' => Hash::make('new-catalog-secret')]);
        $this->get($first['url'].'?locale=en&format=json')->assertDontSee('Food Item');
        $this->post($first['url'], ['password' => 'new-catalog-secret'])->assertRedirect();
        $this->travel(16)->minutes();
        $this->get($first['url'].'?locale=en&format=json')->assertDontSee('Food Item');
        $this->travelBack();
    }

    public function test_pause_revoke_expiry_and_company_disable_deny_managed_routes_but_keep_public_marketing_asset(): void
    {
        Storage::fake('public');
        $image = app(ProductImageService::class)->storeImage($this->product, UploadedFile::fake()->image('approved.png', 64, 64), $this->owner, true);
        $link = $this->publish(expires: now()->addDays(2)->format('Y-m-d'), image: $image);
        $service = app(CatalogService::class);
        $originalBytes = Storage::disk('public')->get($image->thumbnail_path);
        $originalUrl = $service->publicData($link['share'], 'en')['items'][0]['image'];
        $this->assertSame(rtrim((string) config('app.url'), '/').'/storage/'.$image->thumbnail_path, $originalUrl);
        foreach (['paused', 'revoked'] as $state) {
            $service->state($link['catalog']->id, $state);
            $this->assertAllManagedEndpointsDenied($link['url']);
            $this->assertSame($originalBytes, Storage::disk('public')->get($image->thumbnail_path));
            $this->assertFileIsReadable(Storage::disk('public')->path($image->thumbnail_path));
            $service->state($link['catalog']->id, 'active');
        }
        $this->get($link['url'].'?locale=en&format=json')->assertOk()->assertJsonPath('items.0.image', $originalUrl);
        $link['share']->update(['expires_at' => now()]);
        $this->assertAllManagedEndpointsDenied($link['url']);
        $link['share']->update(['expires_at' => now()->addDays(2)]);
        $this->company->update(['status' => 'disabled']);
        $this->assertAllManagedEndpointsDenied($link['url']);
        // Static public-storage delivery is outside managed catalog access: revocation does not delete or privatize approved photographs.
        $this->assertSame($originalBytes, Storage::disk('public')->get($image->thumbnail_path));
        $this->assertFileIsReadable(Storage::disk('public')->path($image->thumbnail_path));
    }

    public function test_primary_image_changes_do_not_change_public_revision_and_deleted_approved_media_has_no_fallback(): void
    {
        Storage::fake('public');
        $images = app(ProductImageService::class);
        $imageA = $images->storeImage($this->product, UploadedFile::fake()->image('a.png', 64, 64), $this->owner, true);
        $link = $this->publish(image: $imageA);
        $before = $this->get($link['url'].'?locale=en&format=json')->assertOk()->json();
        $imageB = $images->storeImage($this->product, UploadedFile::fake()->image('b.png', 72, 72), $this->owner, true);
        $this->assertSame($before, $this->get($link['url'].'?locale=en&format=json')->assertOk()->json());
        $images->deleteImage($imageA, $this->owner);
        $response = $this->get($link['url'].'?locale=en&format=json')->assertOk()->assertJsonPath('items.0.image', null);
        $response->assertDontSee($imageB->thumbnail_path, false);
        $this->get($link['url'].'?locale=en')->assertOk()->assertDontSee($imageB->thumbnail_path, false);
    }

    public function test_guest_tenant_resolution_is_explicit_and_invalid_token_or_subject_cannot_fall_back(): void
    {
        $link = $this->publish();
        app(CompanyContext::class)->clear();
        $foreignOwner = User::factory()->create();
        $foreignCompany = app(CreateCompanyAction::class)->execute($foreignOwner, ['name_ar' => 'شركة أخرى', 'name_en' => 'Foreign private company', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($foreignCompany, $foreignOwner);
        $this->actingAs($foreignOwner);
        $this->get($link['url'].'?locale=en&format=json')->assertOk()->assertJsonPath('items.0.name', 'Food Item')->assertDontSee('Foreign private company');
        $this->get('/catalog/'.str_repeat('x', 40))->assertNotFound();
        $this->get('/catalog/short')->assertNotFound();
        DB::table('public_shares')->where('id', $link['share']->id)->update(['company_id' => $foreignCompany->id]);
        $this->assertAllManagedEndpointsDenied($link['url']);
    }

    public function test_revocation_during_pdf_render_discards_generated_bytes(): void
    {
        $link = $this->publish();
        auth()->logout();
        View::composer('pdf.catalog', function () use ($link): void {
            DB::table('catalogs')->where('id', $link['catalog']->id)->update(['status' => 'revoked']);
        });
        $response = $this->get($link['url'].'?locale=en&format=pdf')->assertNotFound()->assertDontSee('Food Item');
        $this->assertStringNotContainsString('%PDF-', $response->getContent());
        $this->assertPrivateHeaders($response);
    }

    public function test_publication_revision_change_during_pdf_render_discards_stale_bytes(): void
    {
        $link = $this->publish();
        View::composer('pdf.catalog', function () use ($link): void {
            DB::table('catalogs')->where('id', $link['catalog']->id)->increment('published_revision');
        });
        $this->get($link['url'].'?locale=en&format=pdf')->assertNotFound()->assertDontSee('%PDF-', false);
    }

    private function assertAllManagedEndpointsDenied(string $url): void
    {
        foreach (['', '?format=json', '?format=pdf', '?format=print'] as $suffix) {
            $response = $this->get($url.$suffix)->assertNotFound()->assertDontSee('Food Item')->assertDontSee('Approved product catalog');
            $this->assertPrivateHeaders($response);
            $this->head($url.$suffix)->assertNotFound()->assertContent('');
        }
    }

    /** @param array<string,mixed> $data */
    private function assertPublicWhitelist(array $data): void
    {
        foreach (['price', 'currency_code', 'tax_basis', 'cost', 'profit', 'supplier', 'stock', 'warehouse', 'private_notes', 'product_id', 'unit_id', 'media'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
            foreach ($data['items'] as $item) {
                $this->assertArrayNotHasKey($field, $item);
            }
        }
    }

    private function assertPrivateHeaders(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    /** @return array<string,string> */
    private function economicFingerprint(): array
    {
        $result = [];
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'sales_invoices', 'sales_invoice_lines', 'customer_payments', 'customer_payment_allocations', 'document_sequences', 'purchases', 'purchase_lines', 'vendor_payments', 'vendor_payment_allocations'] as $table) {
            $result[$table] = hash('sha256', DB::table($table)->where('company_id', $this->company->id)->orderBy('id')->get()->toJson());
        }

        return $result;
    }
}
