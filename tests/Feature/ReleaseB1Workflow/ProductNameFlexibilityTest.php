<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseB1Workflow;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Exceptions\IdempotencyConflictException;
use App\Livewire\Pages\Products\ProductForm;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Services\Catalogs\CatalogService;
use App\Services\Inventory\BarcodeLabelService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Inventory\QuickProductCreation;
use App\Services\Sales\DocumentDescription;
use App\Services\Search\GlobalSearch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;
use Tests\Support\DisposableMariaDbSchema;

final class ProductNameFlexibilityTest extends Phase5ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:6CUe2hhgD04d5Aic4q6P5q8n3mQ/v3Z9Dk4e2vW+1o8=']);
    }

    public function test_product_form_create_arabic_only(): void
    {
        $this->activate($this->owner);
        Livewire::test(ProductForm::class)
            ->set('name_ar', 'صنف عربي خالص')
            ->set('name_en', '')
            ->set('sku', 'AR-ONLY-01')
            ->set('base_unit_id', $this->product->base_unit_id)
            ->set('product_type', Product::TYPE_STOCK)
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('company_id', $this->company->id)->where('sku', 'AR-ONLY-01')->firstOrFail();
        $this->assertSame('صنف عربي خالص', $product->name_ar);
        $this->assertNull($product->name_en);
    }

    public function test_product_form_create_english_only(): void
    {
        $this->activate($this->owner);
        Livewire::test(ProductForm::class)
            ->set('name_ar', '')
            ->set('name_en', 'English Only Widget')
            ->set('sku', 'EN-ONLY-01')
            ->set('base_unit_id', $this->product->base_unit_id)
            ->set('product_type', Product::TYPE_STOCK)
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('company_id', $this->company->id)->where('sku', 'EN-ONLY-01')->firstOrFail();
        $this->assertNull($product->name_ar);
        $this->assertSame('English Only Widget', $product->name_en);
    }

    public function test_product_form_create_bilingual_preserves_both(): void
    {
        $this->activate($this->owner);
        Livewire::test(ProductForm::class)
            ->set('name_ar', 'صنف ثنائي')
            ->set('name_en', 'Bilingual Widget')
            ->set('sku', 'BI-01')
            ->set('base_unit_id', $this->product->base_unit_id)
            ->set('product_type', Product::TYPE_STOCK)
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('company_id', $this->company->id)->where('sku', 'BI-01')->firstOrFail();
        $this->assertSame('صنف ثنائي', $product->name_ar);
        $this->assertSame('Bilingual Widget', $product->name_en);
    }

    public function test_product_form_create_neither_name_fails_validation(): void
    {
        $this->activate($this->owner);
        $before = Product::where('company_id', $this->company->id)->count();

        Livewire::test(ProductForm::class)
            ->set('name_ar', '')
            ->set('name_en', '')
            ->set('sku', 'FAIL-01')
            ->set('base_unit_id', $this->product->base_unit_id)
            ->set('product_type', Product::TYPE_STOCK)
            ->call('save')
            ->assertHasErrors(['name_ar', 'name_en']);

        $this->assertSame($before, Product::where('company_id', $this->company->id)->count());
    }

    public function test_product_form_create_whitespace_only_fails_validation(): void
    {
        $this->activate($this->owner);
        $before = Product::where('company_id', $this->company->id)->count();

        Livewire::test(ProductForm::class)
            ->set('name_ar', '   ')
            ->set('name_en', "  \t  \n ")
            ->set('sku', 'FAIL-WHITE-01')
            ->set('base_unit_id', $this->product->base_unit_id)
            ->set('product_type', Product::TYPE_STOCK)
            ->call('save')
            ->assertHasErrors(['name_ar', 'name_en']);

        $this->assertSame($before, Product::where('company_id', $this->company->id)->count());
    }

    public function test_product_form_edit_transitions_and_clearing(): void
    {
        $this->activate($this->owner);

        // Edit Arabic-only to English-only
        $form = Livewire::test(ProductForm::class, ['publicId' => $this->product->public_id]);
        $form->set('name_ar', '')
            ->set('name_en', 'Renamed English Only')
            ->call('save')
            ->assertHasNoErrors();

        $this->product->refresh();
        $this->assertNull($this->product->name_ar);
        $this->assertSame('Renamed English Only', $this->product->name_en);

        // Edit English-only to Arabic-only
        $form->set('name_ar', 'اسم عربي مستحدث')
            ->set('name_en', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->product->refresh();
        $this->assertSame('اسم عربي مستحدث', $this->product->name_ar);
        $this->assertNull($this->product->name_en);

        // Both unchanged preserves data
        $form->call('save')->assertHasNoErrors();
        $this->product->refresh();
        $this->assertSame('اسم عربي مستحدث', $this->product->name_ar);

        // Attempting to clear both on edit fails validation
        $form->set('name_ar', '')
            ->set('name_en', '')
            ->call('save')
            ->assertHasErrors(['name_ar', 'name_en']);

        $this->product->refresh();
        $this->assertSame('اسم عربي مستحدث', $this->product->name_ar);
    }

    public function test_quick_product_creation_service_contract(): void
    {
        $service = app(QuickProductCreation::class);

        // Arabic-only quick-create
        $p1 = $service->execute($this->company, $this->owner, (string) Str::uuid(), [
            'name_ar' => 'صنف سريع عربي',
            'name_en' => null,
            'sku' => 'QK-AR-01',
            'base_unit_id' => $this->unit->unit_id,
            'track_expiry' => false,
        ], 'sales.invoice.create');
        $this->assertSame('صنف سريع عربي', $p1->name_ar);
        $this->assertNull($p1->name_en);

        // English-only quick-create
        $p2 = $service->execute($this->company, $this->owner, (string) Str::uuid(), [
            'name_ar' => '',
            'name_en' => 'Quick English Widget',
            'sku' => 'QK-EN-01',
            'base_unit_id' => $this->unit->unit_id,
            'track_expiry' => false,
        ], 'purchasing.purchase.create');
        $this->assertNull($p2->name_ar);
        $this->assertSame('Quick English Widget', $p2->name_en);

        // Neither fails validation
        $this->expectException(ValidationException::class);
        $service->execute($this->company, $this->owner, (string) Str::uuid(), [
            'name_ar' => '',
            'name_en' => '',
            'sku' => 'QK-FAIL-01',
            'base_unit_id' => $this->unit->unit_id,
            'track_expiry' => false,
        ], 'sales.invoice.create');
    }

    public function test_quick_product_idempotency_and_changed_payload_rejection(): void
    {
        $service = app(QuickProductCreation::class);
        $key = (string) Str::uuid();
        $payload = [
            'name_ar' => null,
            'name_en' => 'Idempotent Widget',
            'sku' => 'IDEMP-01',
            'base_unit_id' => $this->unit->unit_id,
            'track_expiry' => false,
        ];

        $first = $service->execute($this->company, $this->owner, $key, $payload, 'sales.invoice.create');

        // Replay with exact same payload
        $second = $service->execute($this->company, $this->owner, $key, $payload, 'sales.invoice.create');
        $this->assertSame($first->id, $second->id);

        // Replay with changed payload must throw IdempotencyConflictException
        $changedPayload = array_merge($payload, ['name_en' => 'Tampered Widget']);
        $this->expectException(IdempotencyConflictException::class);
        $service->execute($this->company, $this->owner, $key, $changedPayload, 'sales.invoice.create');
    }

    public function test_quick_product_create_on_sales_invoice_form(): void
    {
        $this->activate($this->owner);

        $page = Livewire::test(InvoiceForm::class)
            ->call('openQuickProductModal')
            ->set('quick_product_name_ar', '')
            ->set('quick_product_name_en', 'Invoice Quick Widget')
            ->set('quick_product_sku', 'INV-QK-01')
            ->call('saveQuickProduct')
            ->assertHasNoErrors()
            ->assertSet('showQuickProductModal', false)
            ->assertDispatched('quick-product-created');

        $created = Product::where('company_id', $this->company->id)->where('sku', 'INV-QK-01')->firstOrFail();
        $this->assertNull($created->name_ar);
        $this->assertSame('Invoice Quick Widget', $created->name_en);
        $this->assertSame('Invoice Quick Widget', $page->get('lines.0.item_description'));
    }

    public function test_quick_product_create_on_purchase_form(): void
    {
        $this->activate($this->owner);

        $page = Livewire::test(PurchaseForm::class)
            ->call('openQuickProductModal')
            ->set('quick_product_name_ar', '')
            ->set('quick_product_name_en', 'Purchase Quick Widget')
            ->set('quick_product_sku', 'PUR-QK-01')
            ->call('saveQuickProduct')
            ->assertHasNoErrors()
            ->assertSet('showQuickProductModal', false)
            ->assertDispatched('quick-product-created');

        $created = Product::where('company_id', $this->company->id)->where('sku', 'PUR-QK-01')->firstOrFail();
        $this->assertNull($created->name_ar);
        $this->assertSame('Purchase Quick Widget', $created->name_en);
        $this->assertSame('Purchase Quick Widget', $page->get('lines.0.item_description'));
    }

    public function test_name_and_display_fallback_across_locales(): void
    {
        $englishOnly = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => null,
            'name_en' => 'English Solo',
            'sku' => 'EN-SOLO',
            'base_unit_id' => $this->unit->unit_id,
            'created_by' => $this->owner->id,
        ]);

        $arabicOnly = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عربي منفرد',
            'name_en' => null,
            'sku' => 'AR-SOLO',
            'base_unit_id' => $this->unit->unit_id,
            'created_by' => $this->owner->id,
        ]);

        $bilingual = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'ثنائي الاسم',
            'name_en' => 'Dual Name',
            'sku' => 'DUAL-01',
            'base_unit_id' => $this->unit->unit_id,
            'created_by' => $this->owner->id,
        ]);

        // In Arabic locale
        app()->setLocale('ar');
        $this->assertSame('English Solo', $englishOnly->name(), 'English-only product falls back to name_en in Arabic locale');
        $this->assertSame('عربي منفرد', $arabicOnly->name());
        $this->assertSame('ثنائي الاسم', $bilingual->name());

        // In English locale
        app()->setLocale('en');
        $this->assertSame('English Solo', $englishOnly->name());
        $this->assertSame('عربي منفرد', $arabicOnly->name(), 'Arabic-only product falls back to name_ar in English locale');
        $this->assertSame('Dual Name', $bilingual->name());
    }

    public function test_global_search_finds_english_only_and_arabic_only_products(): void
    {
        $search = app(GlobalSearch::class);

        $enProduct = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => null,
            'name_en' => 'Special English Gizmo',
            'sku' => 'GIZMO-EN',
            'base_unit_id' => $this->unit->unit_id,
            'created_by' => $this->owner->id,
        ]);

        $arProduct = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'أداة عربية خاصة',
            'name_en' => null,
            'sku' => 'GIZMO-AR',
            'base_unit_id' => $this->unit->unit_id,
            'created_by' => $this->owner->id,
        ]);

        app()->setLocale('en');
        $resultsEn = $search->search('Gizmo');
        $productTitles = collect($resultsEn['product'] ?? [])->pluck('title')->all();
        $this->assertContains('Special English Gizmo', $productTitles);

        app()->setLocale('ar');
        $resultsAr = $search->search('خاصة');
        $productTitlesAr = collect($resultsAr['product'] ?? [])->pluck('title')->all();
        $this->assertContains('أداة عربية خاصة', $productTitlesAr);
    }

    public function test_document_snapshots_and_posted_immutability_for_english_only_products(): void
    {
        $this->activate($this->owner);

        $product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => null,
            'name_en' => 'Snapshot Test Gadget',
            'sku' => 'SNAP-EN',
            'base_unit_id' => $this->unit->unit_id,
        ], (int) $this->owner->id);

        $productUnit = ProductUnit::where('product_id', $product->id)->firstOrFail();

        // Create and post purchase
        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'purchase_date' => '2026-10-26',
            'vendor_invoice_number' => 'VIN-SNAP-01',
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $productUnit->id,
                    'item_description' => 'Custom line description',
                    'quantity' => '10.000000',
                    'unit_cost' => '5.0000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $this->assertNull($line->product_name_ar);
        $this->assertSame('Snapshot Test Gadget', $line->product_name_en);

        // Post purchase
        app(PostPurchaseAction::class)->execute($purchase, $this->owner);

        // Mutate master product name
        $product->update(['name_en' => 'Renamed Gadget After Posting', 'name_ar' => 'اسم مضاف لاحقاً']);

        // Stored snapshot must remain unchanged
        $line->refresh();
        $this->assertNull($line->product_name_ar, 'Historical snapshot must not be mutated');
        $this->assertSame('Snapshot Test Gadget', $line->product_name_en, 'Historical snapshot must remain immutable');
    }

    public function test_forward_migration_rollback_guard_blocks_when_english_only_products_exist(): void
    {
        Product::create([
            'company_id' => $this->company->id,
            'name_ar' => null,
            'name_en' => 'Rollback Blocker',
            'sku' => 'BLOCK-01',
            'base_unit_id' => $this->unit->unit_id,
            'created_by' => $this->owner->id,
        ]);

        $migration = require database_path('migrations/2026_10_26_100000_allow_nullable_product_name_ar.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot reverse migration: English-only products or transaction snapshots exist');
        $migration->down();
    }

    public function test_catalog_service_rejects_blank_names_without_creating_a_product(): void
    {
        $before = Product::where('company_id', $this->company->id)->count();
        foreach ([[], ['name_ar' => '  ', 'name_en' => "\t\n"], ['name_ar' => null, 'name_en' => null]] as $names) {
            try {
                app(ProductCatalogService::class)->createProduct($this->company, $names + [
                    'base_unit_id' => $this->unit->unit_id,
                ], (int) $this->owner->id);
                $this->fail('A product must have at least one nonblank name.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('name_ar', $exception->errors());
                $this->assertArrayHasKey('name_en', $exception->errors());
            }
        }
        $this->assertSame($before, Product::where('company_id', $this->company->id)->count());
    }

    public function test_partial_catalog_updates_validate_locked_resulting_names(): void
    {
        $service = app(ProductCatalogService::class);
        $product = $service->updateProduct($this->product, ['name_ar' => null, 'name_en' => '  English  '], (int) $this->owner->id);
        $this->assertNull($product->name_ar);
        $this->assertSame('English', $product->name_en);
        $service->updateProduct($product, ['sku' => 'NAME-PARTIAL'], (int) $this->owner->id);
        $this->assertSame('English', $product->fresh()->name_en);
        try {
            $service->updateProduct($product, ['name_en' => ' '], (int) $this->owner->id);
            $this->fail('Clearing the last name must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('name_en', $exception->errors());
        }
        $this->assertSame('English', $product->fresh()->name_en);
    }

    public function test_zero_is_a_valid_name_and_both_localized_errors_are_actionable(): void
    {
        $this->activate($this->owner);
        Livewire::test(ProductForm::class)
            ->set('name_ar', '0')->set('name_en', '')
            ->set('sku', 'ZERO-NAME')->set('base_unit_id', $this->product->base_unit_id)
            ->call('save')->assertHasNoErrors();
        $product = Product::where('company_id', $this->company->id)->where('sku', 'ZERO-NAME')->firstOrFail();
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame('0', $product->name());
            session(['locale' => $locale]);
            Livewire::test(ProductForm::class)
                ->set('name_ar', '')->set('name_en', '')
                ->call('save')->assertHasErrors(['name_ar', 'name_en'])
                ->assertSee(__('inventory.product_name_required'));
            $this->assertSame($locale, app()->getLocale());
            $this->assertNotSame('inventory.product_name_required', __('inventory.product_name_required'));
        }
    }

    public function test_migration_roundtrip_preserves_names_and_guards_each_nullable_snapshot(): void
    {
        // MariaDB DDL implicitly commits: never run the roundtrip inside the
        // parent fixture transaction. This helper owns a separate fresh schema.
        $schema = DisposableMariaDbSchema::create();
        try {
            $schema->switchLaravelConnection();
            $columns = ['products' => 'name_ar', 'purchase_lines' => 'product_name_ar', 'purchase_return_lines' => 'product_name_ar'];
            foreach ($columns as $table => $column) {
                Schema::create($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->id();
                    $blueprint->string($column, 255);
                });
                DB::table($table)->insert(['id' => 1, $column => 'اسم محفوظ']);
            }
            $migration = require database_path('migrations/2026_10_26_100000_allow_nullable_product_name_ar.php');
            $migration->up();
            $migration->down();
            foreach ($columns as $table => $column) {
                $this->assertSame('اسم محفوظ', DB::table($table)->value($column));
                $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);
                $this->assertFalse($definition['nullable']);
            }
            $migration->up();
            foreach ($columns as $table => $column) {
                DB::table($table)->insert(['id' => 2, $column => null]);
                try {
                    $migration->down();
                    $this->fail('Each nullable product/snapshot column must block rollback.');
                } catch (\RuntimeException $exception) {
                    $this->assertStringContainsString('Cannot reverse migration', $exception->getMessage());
                }
                // Preflight must reject before altering ANY of the three columns.
                foreach ($columns as $target => $field) {
                    $definition = collect(Schema::getColumns($target))->firstWhere('name', $field);
                    $this->assertTrue($definition['nullable']);
                    $this->assertSame('اسم محفوظ', DB::table($target)->where('id', 1)->value($field));
                }
                DB::table($table)->where('id', 2)->delete();
            }
        } finally {
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }

    public function test_purchase_return_retains_english_only_source_snapshot_after_master_edit(): void
    {
        $this->activate($this->owner);
        app(ProductCatalogService::class)->updateProduct($this->product, [
            'name_ar' => null, 'name_en' => 'English return snapshot',
        ], (int) $this->owner->id);
        $purchase = $this->createAndPostPurchase();
        $this->product->refresh()->update(['name_ar' => 'اسم جديد', 'name_en' => 'Changed master']);
        $return = $this->createAndPostReturn($purchase);
        $line = $return->lines()->firstOrFail();
        $this->assertNull($line->product_name_ar);
        $this->assertSame('English return snapshot', $line->product_name_en);
        foreach (['ar', 'en'] as $locale) {
            $this->assertSame('English return snapshot', DocumentDescription::choose(null, $line->product_name_ar, $line->product_name_en, $locale));
        }
    }

    public function test_quick_create_rejects_whitespace_and_preserves_trimmed_bilingual_names(): void
    {
        $service = app(QuickProductCreation::class);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            try {
                $service->execute($this->company, $this->owner, (string) Str::uuid(), [
                    'name_ar' => " \t ", 'name_en' => " \n ", 'sku' => 'BLANK-QUICK',
                    'base_unit_id' => $this->unit->unit_id,
                ], 'sales.invoice.create');
                $this->fail('Whitespace is not a product name.');
            } catch (ValidationException $exception) {
                $this->assertSame([__('inventory.product_name_required')], $exception->errors()['name_ar']);
            }
        }
        $product = $service->execute($this->company, $this->owner, (string) Str::uuid(), [
            'name_ar' => '  اسم الصنف  ', 'name_en' => '  Product name  ', 'sku' => 'BILINGUAL-QUICK',
            'base_unit_id' => $this->unit->unit_id,
        ], 'sales.invoice.create');
        $this->assertSame('اسم الصنف', $product->name_ar);
        $this->assertSame('Product name', $product->name_en);
    }

    public function test_english_zero_name_is_not_discarded_in_search_documents_labels_or_catalogs(): void
    {
        $this->activate($this->owner);
        $product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => null, 'name_en' => '0', 'sku' => 'ZERO-ENGLISH',
            'base_unit_id' => $this->unit->unit_id,
        ], (int) $this->owner->id);
        $barcode = ProductBarcode::create([
            'company_id' => $this->company->id, 'product_id' => $product->id,
            'unit_id' => $this->unit->unit_id, 'barcode' => 'B12ZERO', 'type' => 'code128', 'is_primary' => true,
        ]);
        $catalogs = app(CatalogService::class);
        $catalog = $catalogs->save(null, [
            'name_ar' => 'كتالوج', 'locale' => 'ar', 'show_prices' => false,
            'show_sku' => true, 'show_description' => false, 'show_images' => false,
        ], [
            ['product_id' => $product->id, 'unit_id' => $this->unit->unit_id],
        ]);
        $preview = $catalogs->preview($catalog->id);
        $catalogs->publish($catalog->id, $preview['revision'], $preview['hash'], 'publish-zero-name');
        $link = $catalogs->share($catalog->id);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame('0', $product->name());
            $results = app(GlobalSearch::class)->search('ZERO-ENGLISH');
            $this->assertSame('0', $results['product'][0]['title']);
            $this->assertSame('0', DocumentDescription::choose(null, null, '0', $locale));
            $labels = app(BarcodeLabelService::class)->prepare([$barcode->id => 1], $locale);
            $this->assertSame('0', $labels['labels'][0]['name']);
            $data = $catalogs->publicData($link['share'], $locale);
            $this->assertSame('0', $data['items'][0]['name']);
        }
    }
}
