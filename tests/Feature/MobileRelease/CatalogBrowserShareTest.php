<?php

declare(strict_types=1);

namespace Tests\Feature\MobileRelease;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Catalogs\CatalogComposer;
use App\Models\PublicShare;
use App\Models\User;
use App\Services\Catalogs\CatalogBrowserShare;
use App\Services\Catalogs\CatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class CatalogBrowserShareTest extends Phase5ETestCase
{
    public function test_only_existing_active_publication_is_shareable_and_draft_copy_is_never_in_message(): void
    {
        $service = app(CatalogService::class);
        $catalog = $service->save(null, ['name_ar' => 'كتالوج', 'locale' => 'ar', 'show_prices' => false,
            'show_sku' => true, 'show_description' => true, 'show_images' => false],
            [['product_id' => $this->product->id, 'unit_id' => $this->unit->unit_id]]);
        $browser = app(CatalogBrowserShare::class);
        $this->assertNull($browser->existingUrl($catalog->id));
        $this->assertSame(0, PublicShare::count());
        $preview = $service->preview($catalog->id);
        $service->publish($catalog->id, $preview['revision'], $preview['hash'], 'mobile-publication');
        $this->assertNull($browser->existingUrl($catalog->id));
        $issued = $service->share($catalog->id);
        $this->assertSame($issued['url'], $browser->existingUrl($catalog->id));
        $before = PublicShare::firstOrFail()->getRawOriginal();
        $component = Livewire::test(CatalogComposer::class, ['publicId' => $catalog->public_id])
            ->call('createOrRecoverLink')
            ->set('headers.name_ar', 'PRIVATE-DRAFT-SECRET')
            ->assertViewHas('browserShareUrl', $issued['url']);
        $html = $component->html();
        preg_match('/href="(https:\/\/api\.whatsapp\.com[^\"]+)"/', $html, $match);
        $this->assertNotEmpty($match);
        $this->assertStringNotContainsString('PRIVATE-DRAFT-SECRET', rawurldecode($match[1]));
        $this->assertSame($before, PublicShare::firstOrFail()->getRawOriginal());
        $issued['share']->update(['expires_at' => now()->subMinute()]);
        $this->assertNull($browser->existingUrl($catalog->id));
        $issued['share']->update(['expires_at' => null, 'is_active' => false, 'revoked_at' => now()]);
        $this->assertNull($browser->existingUrl($catalog->id));
        $this->assertSame(1, PublicShare::count());
    }

    public function test_paused_catalog_and_foreign_company_are_refused_without_issuing_links(): void
    {
        $service = app(CatalogService::class);
        $catalog = $service->save(null, ['name_ar' => 'كتالوج', 'locale' => 'ar', 'show_prices' => false,
            'show_sku' => true, 'show_description' => true, 'show_images' => false],
            [['product_id' => $this->product->id, 'unit_id' => $this->unit->unit_id]]);
        $catalog->update(['status' => 'paused']);
        $this->assertNull(app(CatalogBrowserShare::class)->existingUrl($catalog->id));
        $otherOwner = User::factory()->create();
        app(CompanyContext::class)->clear();
        $other = app(CreateCompanyAction::class)->execute($otherOwner, ['name_ar' => 'شركة أخرى']);
        app(CompanyContext::class)->setCompany($other, $otherOwner);
        $this->actingAs($otherOwner);
        $this->expectException(ModelNotFoundException::class);
        app(CatalogBrowserShare::class)->existingUrl($catalog->id);
    }
}
