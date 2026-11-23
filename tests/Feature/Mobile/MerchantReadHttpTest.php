<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MerchantReadHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Product $product;

    private Customer $customer;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mobile.enabled' => true, 'mobile.merchant_api_enabled' => true]);
        Currency::updateOrCreate(['code' => 'ILS'], ['name' => 'Shekel', 'symbol' => 'ILS', 'minor_units' => 2, 'active' => true]);
        $user = User::factory()->create();
        $this->company = Company::factory()->create(['base_currency_code' => 'ILS', 'status' => 'active']);
        $user->companies()->attach($this->company->id, ['status' => 'active', 'is_owner' => false]);
        app(CompanyRoleService::class)->seedCompanyRoles($this->company);
        setPermissionsTeamId($this->company->id);
        $user->assignRole('Owner');
        app(CompanyContext::class)->setCompany($this->company, $user);
        CompanyCurrency::create(['company_id' => $this->company->id, 'currency_code' => 'ILS', 'enabled' => true, 'is_base' => true]);
        $unit = Unit::create(['company_id' => $this->company->id, 'code' => 'KG', 'name_ar' => 'كغم', 'allows_fraction' => true, 'decimal_places' => 6, 'active' => true]);
        $this->product = Product::create(['company_id' => $this->company->id, 'sku' => 'EXACT-PRICE', 'name_ar' => 'صنف', 'base_unit_id' => $unit->id, 'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'default_sale_price_base' => '1.234567', 'default_purchase_cost_base' => '0.123456', 'active' => true, 'created_by' => $user->id]);
        ProductUnit::create(['company_id' => $this->company->id, 'product_id' => $this->product->id, 'unit_id' => $unit->id, 'conversion_to_base' => '1.000000', 'is_base' => true, 'is_default_sale' => true, 'is_default_purchase' => true, 'default_sale_price_base' => '1.234567', 'active' => true]);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'زبون', 'status' => 'active', 'created_by' => $user->id]);
        $this->vendor = Vendor::create(['company_id' => $this->company->id, 'name_ar' => 'مورد', 'status' => 'active', 'created_by' => $user->id]);
        $device = (string) Str::uuid();
        MobileDevice::create(['device_id' => $device, 'device_name' => 'HTTP qualification', 'user_id' => $user->id]);
        $token = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create(['token_hash' => hash('sha256', $token), 'device_id' => $device, 'user_id' => $user->id, 'company_id' => $this->company->id, 'password_epoch' => 'epoch-'.substr(hash('sha256', $user->password), 0, 16), 'expires_at' => now()->addHour()]);
        $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /** @return list<array{string}> */
    public static function reads(): array
    {
        return array_map(fn (string $path) => [$path], ['home', 'products', 'customers', 'suppliers', 'purchases', 'sales/invoices', 'expenses', 'expenses/categories', 'reports/shift-summary', 'reports/treasury', 'settings/business']);
    }

    #[DataProvider('reads')]
    public function test_native_owner_can_load_merchant_screen_data(string $path): void
    {
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/{$path}")->assertOk();
    }

    public function test_read_ready_settings_preserve_precision_and_hide_merchant_mapping(): void
    {
        config(['mobile.merchant_api_enabled' => false]);
        $path = "/api/mobile/v1/companies/{$this->company->public_id}/settings/business";
        $this->getJson($path)->assertOk()->assertJsonPath('default_currency_minor_units', 2)
            ->assertJsonMissingPath('approved_cash_account')->assertJsonMissingPath('default_warehouse')
            ->assertJsonMissingPath('walk_in_customer')->assertJsonMissingPath('taxes')->assertJsonMissingPath('currencies');
        config(['mobile.merchant_api_enabled' => true]);
        $this->getJson($path)->assertOk()->assertJsonStructure(['approved_cash_account', 'default_warehouse', 'walk_in_customer', 'taxes', 'currencies']);
        config(['mobile.merchant_api_enabled' => false]);
        $other = Company::factory()->create(['status' => 'active']);
        $this->getJson("/api/mobile/v1/companies/{$other->public_id}/settings/business")->assertNotFound();
        $token = MobileAccessToken::query()->firstOrFail();
        $actor = User::findOrFail($token->user_id);
        setPermissionsTeamId($this->company->id);
        app(CompanyContext::class)->setCompany($this->company, $actor);
        $actor->removeRole('Owner');
        $this->getJson($path)->assertForbidden();
        config(['mobile.merchant_api_enabled' => true]);
        $this->getJson($path)->assertOk()->assertJsonStructure(['approved_cash_account']);
        config(['mobile.merchant_api_enabled' => false]);
        app(CompanyContext::class)->setCompany($this->company, $actor);
        setPermissionsTeamId($this->company->id);
        $actor->assignRole('Owner');
        $token->update(['revoked_at' => now()]);
        $this->getJson($path)->assertUnauthorized();
        $token->update(['revoked_at' => null]);
        MobileAccessToken::query()->update(['expires_at' => now()->subMinute()]);
        $this->getJson($path)->assertUnauthorized();
        $this->withHeader('Authorization', '')->getJson($path)->assertUnauthorized();
    }

    public function test_product_detail_preserves_exact_price_and_real_public_unit_identity(): void
    {
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products/{$this->product->public_id}")
            ->assertOk()->assertJsonPath('sale_price', '1.234567')->assertJsonPath('units.0.sale_price', '1.234567');
    }

    public function test_party_detail_routes_read_persisted_entities(): void
    {
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}")
            ->assertOk()->assertJsonPath('id', $this->customer->public_id);
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$this->vendor->public_id}")
            ->assertOk()->assertJsonPath('id', $this->vendor->public_id);
    }
}
