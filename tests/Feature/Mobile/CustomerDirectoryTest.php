<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\Customer;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Customer $walkInCustomer;

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

        $this->walkInCustomer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'CUST-WALK-IN',
            'name_ar' => 'عميل نقدي',
            'name_en' => 'Walk-in Customer',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        CompanyMobileSetting::create([
            'company_id' => $this->company->id,
            'walk_in_customer_id' => $this->walkInCustomer->id,
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

    public function test_customer_directory_returns_valid_structure_matching_schema(): void
    {
        $regularCustomer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'CUST-00102',
            'name_ar' => 'سوبرماركت طارق',
            'name_en' => 'Tariq Supermarket',
            'phone' => '+970599123456',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $token = $this->issueToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/customers");

        $response->assertStatus(200)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        $data = $response->json();
        $this->assertSame($this->company->public_id, $data['company_id']);
        $this->assertFalse($data['has_more']);
        $this->assertNull($data['next_cursor']);
        $this->assertCount(2, $data['customers']);

        $walkInItem = collect((array) $data['customers'])->firstWhere('id', $this->walkInCustomer->public_id);
        $this->assertNotNull($walkInItem);
        $this->assertSame('CUST-WALK-IN', $walkInItem['code']);
        $this->assertTrue($walkInItem['is_walk_in']);

        $regItem = collect((array) $data['customers'])->firstWhere('id', $regularCustomer->public_id);
        $this->assertNotNull($regItem);
        $this->assertSame('CUST-00102', $regItem['code']);
        $this->assertSame('+970599123456', $regItem['phone']);
        $this->assertFalse($regItem['is_walk_in']);
    }

    public function test_customer_directory_bounded_pagination_and_search(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            Customer::create([
                'company_id' => $this->company->id,
                'code' => sprintf('CUST-%04d', $i),
                'name_ar' => "عميل رقم {$i}",
                'phone' => sprintf('+970599%06d', $i),
                'status' => 'active',
                'created_by' => $this->user->id,
            ]);
        }

        $token = $this->issueToken();

        // 1. Clamped limit: requesting 50 returns max 30
        $response1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/customers?limit=50");

        $response1->assertStatus(200);
        $data1 = $response1->json();
        $this->assertCount(30, $data1['customers']);
        $this->assertTrue($data1['has_more']);
        $this->assertNotNull($data1['next_cursor']);

        // 2. Second page using cursor
        $response2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/customers?cursor={$data1['next_cursor']}");

        $response2->assertStatus(200);
        $data2 = $response2->json();
        // 36 total (1 walk-in + 35 seeded) - 30 = 6
        $this->assertCount(6, $data2['customers']);
        $this->assertFalse($data2['has_more']);
        $this->assertNull($data2['next_cursor']);

        // 3. Search filter by code
        $responseSearch = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/customers?search=CUST-0015");
        $responseSearch->assertStatus(200);
        $dataSearch = $responseSearch->json();
        $this->assertCount(1, $dataSearch['customers']);
        $this->assertSame('CUST-0015', $dataSearch['customers'][0]['code']);
    }

    public function test_customer_directory_query_validation_strictly_rejects_invalid_parameters(): void
    {
        $token = $this->issueToken();

        // Invalid limit formats
        foreach (['limit[]' => '10', 'limit' => '-5', 'limit' => '10.5', 'limit' => 'abc', 'limit' => '0', 'limit' => '101'] as $param => $val) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/customers?{$param}={$val}")
                ->assertStatus(422);
        }

        // Invalid cursor formats
        foreach (['01JCV8X9MDPTRSVWXYZ012345678', 'invalid_lower_case_cursor_xx', 'not-a-crockford-ulid-string'] as $badCursor) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/customers?cursor={$badCursor}")
                ->assertStatus(422);
        }

        // Search string exceeding 100 characters
        $longSearch = str_repeat('c', 101);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/customers?search={$longSearch}")
            ->assertStatus(422);
    }

    public function test_customer_directory_fails_with_409_when_stored_customer_exceeds_wire_contract(): void
    {
        // Stored customer with empty code violates schema minLength: 1
        $badCustomer = CompanyScope::executeWithoutScope(fn () => Customer::create([
            'company_id' => $this->company->id,
            'code' => 'TEMP-CODE',
            'name_ar' => 'عميل بدون كود صالح',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]));
        DB::table('customers')
            ->where('id', $badCustomer->id)
            ->update(['code' => '']);

        $token = $this->issueToken();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/customers")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONTRACT_INCOMPATIBLE');
    }

    public function test_customer_directory_absolute_cap_30_even_when_config_is_1000(): void
    {
        config(['mobile.rate_limits.customer_page_limit' => 1000]);
        CompanyScope::executeWithoutScope(function (): void {
            for ($i = 1; $i <= 35; $i++) {
                Customer::create([
                    'company_id' => $this->company->id,
                    'code' => sprintf('CUST-LIM-%04d', $i),
                    'name_ar' => "عميل سقف {$i}",
                    'status' => 'active',
                    'created_by' => $this->user->id,
                ]);
            }
        });

        $token = $this->issueToken();
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/customers?limit=100")
            ->assertOk();

        $this->assertCount(30, $response->json('customers'));
        $this->assertTrue($response->json('has_more'));
    }
}
