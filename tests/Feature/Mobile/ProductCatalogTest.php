<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Unit $unitPiece;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mobile.enabled' => true]);

        $this->user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->company = Company::factory()->create([
            'base_currency_code' => 'ILS',
            'status' => 'active',
        ]);

        $this->user->companies()->attach($this->company->id, ['status' => 'active', 'is_owner' => false]);
        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($this->company);
        setPermissionsTeamId($this->company->id);
        $this->user->assignRole('Cashier');

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'WH-MAIN',
            'name_ar' => 'المستودع الرئيسي',
            'active' => true,
            'is_default' => true,
            'created_by' => $this->user->id,
        ]);

        $this->unitPiece = Unit::create([
            'company_id' => $this->company->id,
            'code' => 'PCS',
            'name_ar' => 'حبة',
            'name_en' => 'Piece',
            'allows_fraction' => false,
            'decimal_places' => 0,
            'active' => true,
        ]);

        TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT16',
            'name_ar' => 'ضريبة القيمة المضافة',
            'rate' => '16.000000',
            'calculation' => 'exclusive',
            'active' => true,
            'created_by' => $this->user->id,
        ]);
    }

    private function issueToken(): string
    {
        $raw = 'st_m_'.bin2hex(random_bytes(32));
        $deviceId = (string) Str::uuid();
        MobileDevice::create(['device_id' => $deviceId, 'device_name' => 'Tablet', 'user_id' => $this->user->id]);

        MobileAccessToken::create([
            'token_hash' => hash('sha256', $raw),
            'device_id' => $deviceId,
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->user->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        return $raw;
    }

    public function test_nonempty_catalog_rejects_unapproved_tax_mapping_and_redacts_costs(): void
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'SKU-OLIVE-OIL-1L',
            'name_ar' => 'زيت زيتون بكر ممتاز 1 لتر',
            'name_en' => 'Extra Virgin Olive Oil 1L',
            'product_type' => Product::TYPE_STOCK,
            'base_unit_id' => $this->unitPiece->id,
            'track_stock' => true,
            'default_sale_price_base' => '25.000000',
            'default_purchase_cost_base' => '18.000000', // Sensitive cost that MUST be redacted
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'default_sale_price_base' => '25.000000',
            'active' => true,
        ]);

        $unitBox = Unit::create([
            'company_id' => $this->company->id,
            'code' => 'BOX',
            'name_ar' => 'صندوق',
            'name_en' => 'Box',
            'allows_fraction' => false,
            'decimal_places' => 0,
            'active' => true,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $unitBox->id,
            'conversion_to_base' => '12.000000',
            'is_base' => false,
            'is_default_sale' => false,
            'default_sale_price_base' => null, // No override: computes base 25 * 12 = 300
            'active' => true,
        ]);

        InventoryBalance::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity_base' => '142.000000',
        ]);

        $token = $this->issueToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products");

        $response->assertStatus(200)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        $validator = new Process(['python', base_path('tests/Support/Mobile/validate-response.py'), 'product-catalog-response']);
        $validator->setInput($response->getContent())->run();
        $this->assertTrue($validator->isSuccessful(), $validator->getErrorOutput());

        $data = $response->json();
        $this->assertCount(1, $data['products']);
        $item = $data['products'][0];

        // Revision 2: nullable tax_rate + tax_rate_id PAIR null/null
        $this->assertNull($item['tax_rate']);
        $this->assertNull($item['tax_rate_id']);
        $this->assertSame('25.000000', $item['unit_price']);
        $this->assertSame('142.000000', $item['available_quantity']);
        $this->assertSame('25.000000', $item['default_unit']['sale_price']);

        // Packaging conversion without override: 25 * 12 = 300.000000
        $boxUnit = collect((array) $item['units'])->firstWhere('code', 'BOX');
        $this->assertNotNull($boxUnit);
        $this->assertSame('300.000000', $boxUnit['sale_price']);

        // Prefix search: non-matching query returns empty list with 200 OK
        foreach (['?search=does-not-match', '?cursor=ZZZZZZZZZZZZZZZZZZZZZZZZZZ'] as $suffix) {
            $resp = $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products{$suffix}")
                ->assertStatus(200);
            $this->assertSame([], $resp->json('products'));
        }

        // Strict Cost Redaction Check: no cost fields anywhere in response
        $rawContent = $response->getContent();
        $this->assertStringNotContainsString('18.000000', $rawContent);
        $this->assertStringNotContainsString('default_purchase_cost_base', $rawContent);
        $this->assertStringNotContainsString('unit_cost', $rawContent);
        $this->assertStringNotContainsString('cogs', $rawContent);
    }

    public function test_pricing_packaging_rules_legitimate_zero_and_max_decimals(): void
    {
        // CanonicalSalesDocumentRules::price packaging base 10 conversion 12 no override 120
        $product10 = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'SKU-BASE10',
            'name_ar' => 'منتج سعر أساسي 10',
            'product_type' => Product::TYPE_STOCK,
            'base_unit_id' => $this->unitPiece->id,
            'track_stock' => true,
            'default_sale_price_base' => '10.000000',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $unitPack = Unit::create([
            'company_id' => $this->company->id,
            'code' => 'PACK12',
            'name_ar' => 'حزمة 12',
            'allows_fraction' => false,
            'decimal_places' => 0,
            'active' => true,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product10->id,
            'unit_id' => $unitPack->id,
            'conversion_to_base' => '12.000000',
            'is_base' => false,
            'is_default_sale' => false,
            'default_sale_price_base' => null, // packaging base 10 * conversion 12 = 120
            'active' => true,
        ]);

        // Legitimate zero price product
        $productZero = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'SKU-FREE-SAMPLE',
            'name_ar' => 'عينة مجانية',
            'product_type' => Product::TYPE_STOCK,
            'base_unit_id' => $this->unitPiece->id,
            'track_stock' => false,
            'default_sale_price_base' => '0.000000',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        // Max DECIMAL(20,6) exact value
        $productMax = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'SKU-MAX-DECIMAL',
            'name_ar' => 'منتج حد أقصى',
            'product_type' => Product::TYPE_STOCK,
            'base_unit_id' => $this->unitPiece->id,
            'track_stock' => false,
            'default_sale_price_base' => '99999999999999.999999',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $token = $this->issueToken();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products")
            ->assertOk();

        $items = collect((array) $response->json('products'));

        $item10 = $items->firstWhere('sku', 'SKU-BASE10');
        $this->assertNotNull($item10);
        $packUnit = collect((array) $item10['units'])->firstWhere('code', 'PACK12');
        $this->assertSame('120.000000', $packUnit['sale_price']);

        $itemZero = $items->firstWhere('sku', 'SKU-FREE-SAMPLE');
        $this->assertNotNull($itemZero);
        $this->assertSame('0.000000', $itemZero['unit_price']);

        $itemMax = $items->firstWhere('sku', 'SKU-MAX-DECIMAL');
        $this->assertNotNull($itemMax);
        $this->assertSame('99999999999999.999999', $itemMax['unit_price']);
    }

    public function test_catalog_snapshot_version_changes_truthfully_after_catalog_edit(): void
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'SKU-SNAP-TEST',
            'name_ar' => 'اسم أصلي',
            'product_type' => Product::TYPE_STOCK,
            'base_unit_id' => $this->unitPiece->id,
            'track_stock' => false,
            'default_sale_price_base' => '5.000000',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $token = $this->issueToken();
        $first = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products")->assertOk();
        $v1 = $first->json('catalog_snapshot_version');
        $this->assertSame(64, strlen($v1));

        // Sleep 1 second to ensure updated_at changes timestamp
        sleep(1);
        $product->update(['name_ar' => 'اسم معدل']);

        $second = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products")->assertOk();
        $v2 = $second->json('catalog_snapshot_version');
        $this->assertSame(64, strlen($v2));
        $this->assertNotSame($v1, $v2);
    }

    public function test_empty_catalog_is_schema_compatible_and_snapshot_is_deterministic(): void
    {
        $token = $this->issueToken();
        $first = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products")->assertOk();
        $second = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products")->assertOk();
        $this->assertSame($first->json('catalog_snapshot_version'), $second->json('catalog_snapshot_version'));
        $first->assertJsonPath('products', []);
    }
}
