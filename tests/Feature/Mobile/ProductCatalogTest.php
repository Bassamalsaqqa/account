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
use Tests\TestCase;

final class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Unit $unitPiece;

    private TaxRate $taxRate;

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

        $this->taxRate = TaxRate::create([
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
        MobileDevice::create(['device_id' => $deviceId, 'device_name' => 'Tablet']);

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

    public function test_product_catalog_returns_valid_structure_matching_schema(): void
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'SKU-OLIVE-OIL-1L',
            'barcode' => '6291041001234',
            'name_ar' => 'زيت زيتون بكر ممتاز 1 لتر',
            'name_en' => 'Extra Virgin Olive Oil 1L',
            'product_type' => Product::TYPE_STOCK,
            'base_unit_id' => $this->unitPiece->id,
            'track_stock' => true,
            'default_sale_price_base' => '25.000000',
            'default_purchase_cost_base' => '18.000000', // Sensitive cost that MUST be redacted
            'tax_rate_id' => $this->taxRate->id,
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

        $data = $response->json();
        $this->assertSame($this->company->public_id, $data['company_id']);
        $this->assertFalse($data['has_more']);
        $this->assertNull($data['next_cursor']);
        $this->assertCount(1, $data['products']);

        $item = $data['products'][0];
        $this->assertSame($product->public_id, $item['id']);
        $this->assertSame('SKU-OLIVE-OIL-1L', $item['sku']);
        $this->assertSame('6291041001234', $item['barcode']);
        $this->assertSame('25.000000', $item['unit_price']);
        $this->assertSame('16.000000', $item['tax_rate']);
        $this->assertSame($this->taxRate->public_id, $item['tax_rate_id']);
        $this->assertTrue($item['is_tracked']);
        $this->assertSame('142.000000', $item['available_quantity']);

        // Default Unit: product_unit_id maps to unit's public_id
        $this->assertSame($this->unitPiece->public_id, $item['default_unit']['unit_id']);
        $this->assertSame($this->unitPiece->public_id, $item['default_unit']['product_unit_id']);
        $this->assertSame('1.000000', $item['default_unit']['conversion_to_base']);
        $this->assertSame('25.000000', $item['default_unit']['sale_price']);

        // Units array
        $this->assertCount(1, $item['units']);
        $this->assertSame($this->unitPiece->public_id, $item['units'][0]['product_unit_id']);
        $this->assertSame('PCS', $item['units'][0]['code']);
        $this->assertTrue($item['units'][0]['is_base']);
        $this->assertTrue($item['units'][0]['is_default_sale']);

        // Strict Cost Redaction Check: no cost fields anywhere in response
        $rawContent = $response->getContent();
        $this->assertStringNotContainsString('18.000000', $rawContent);
        $this->assertStringNotContainsString('default_purchase_cost_base', $rawContent);
        $this->assertStringNotContainsString('unit_cost', $rawContent);
        $this->assertStringNotContainsString('cogs', $rawContent);
    }

    public function test_product_catalog_bounded_pagination_and_search(): void
    {
        // Seed 35 products
        for ($i = 1; $i <= 35; $i++) {
            Product::create([
                'company_id' => $this->company->id,
                'sku' => sprintf('SKU-%04d', $i),
                'name_ar' => "منتج رقم {$i}",
                'product_type' => Product::TYPE_STOCK,
                'base_unit_id' => $this->unitPiece->id,
                'default_sale_price_base' => '10.000000',
                'active' => true,
                'created_by' => $this->user->id,
            ]);
        }

        $token = $this->issueToken();

        // 1. Clamped limit: requesting 50 returns max 30
        $response1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products?limit=50");

        $response1->assertStatus(200);
        $data1 = $response1->json();
        $this->assertCount(30, $data1['products']);
        $this->assertTrue($data1['has_more']);
        $this->assertNotNull($data1['next_cursor']);

        // 2. Second page using next_cursor
        $response2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products?cursor={$data1['next_cursor']}");

        $response2->assertStatus(200);
        $data2 = $response2->json();
        $this->assertCount(5, $data2['products']);
        $this->assertFalse($data2['has_more']);
        $this->assertNull($data2['next_cursor']);

        // 3. Search filter
        $responseSearch = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products?search=SKU-0012");
        $responseSearch->assertStatus(200);
        $dataSearch = $responseSearch->json();
        $this->assertCount(1, $dataSearch['products']);
        $this->assertSame('SKU-0012', $dataSearch['products'][0]['sku']);
    }
}
