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

        InventoryBalance::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity_base' => '142.000000',
        ]);

        $token = $this->issueToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products");

        $response->assertStatus(409)->assertJsonPath('error.code', 'CATALOG_CONFIG_UNSUPPORTED');

        foreach (['?search=does-not-match', '?cursor=ZZZZZZZZZZZZZZZZZZZZZZZZZZ'] as $suffix) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products{$suffix}")
                ->assertStatus(409)->assertJsonPath('error.code', 'CATALOG_CONFIG_UNSUPPORTED');
        }

        // Strict Cost Redaction Check: no cost fields anywhere in response
        $rawContent = $response->getContent();
        $this->assertStringNotContainsString('18.000000', $rawContent);
        $this->assertStringNotContainsString('default_purchase_cost_base', $rawContent);
        $this->assertStringNotContainsString('unit_cost', $rawContent);
        $this->assertStringNotContainsString('cogs', $rawContent);
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
