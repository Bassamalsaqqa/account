<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V1;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\NativeCommandReceipt;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Purchasing\PurchasePayableAsOf;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Mobile\Receipt\NativeCommandReceiptManager;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PartyCatalogIdempotencyAndContractTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private string $rawToken;

    private User $otherUser;

    private string $otherToken;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();
        NativeCommandReceiptManager::$failBeforeReceiptInsertForTesting = false;

        config([
            'mobile.enabled' => true,
            'mobile.merchant_api_enabled' => true,
        ]);

        Currency::updateOrCreate(
            ['code' => 'ILS'],
            ['name' => 'Shekel', 'symbol' => 'ILS', 'minor_units' => 2, 'active' => true]
        );

        $this->user = User::factory()->create([
            'password' => Hash::make('merchant-pass-2026'),
            'locale' => 'ar',
        ]);

        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة التاجر للتجزئة',
            'name_en' => 'Merchant Retail Co',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($this->company);
        setPermissionsTeamId($this->company->id);
        $this->user->assignRole('Owner');

        $deviceId = (string) Str::uuid();
        MobileDevice::create([
            'device_id' => $deviceId,
            'device_name' => 'Primary POS Tablet',
            'user_id' => $this->user->id,
        ]);

        $this->rawToken = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $this->rawToken),
            'device_id' => $deviceId,
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->user->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        // Secondary actor and device in the same company
        $this->otherUser = User::factory()->create([
            'password' => Hash::make('other-pass-2026'),
            'locale' => 'ar',
        ]);
        $this->otherUser->companies()->attach($this->company->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);
        $this->otherUser->assignRole('Administrator');

        $otherDeviceId = (string) Str::uuid();
        MobileDevice::create([
            'device_id' => $otherDeviceId,
            'device_name' => 'Secondary Device',
            'user_id' => $this->otherUser->id,
        ]);

        $this->otherToken = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $this->otherToken),
            'device_id' => $otherDeviceId,
            'user_id' => $this->otherUser->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->otherUser->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
    }

    protected function tearDown(): void
    {
        NativeCommandReceiptManager::$failBeforeReceiptInsertForTesting = false;
        parent::tearDown();
    }

    public function test_customer_create_idempotency_lost_ack_replay_and_conflict(): void
    {
        $idempotencyKey = 'CUST-CREATE-'.(string) Str::uuid();
        $endpoint = "/api/mobile/v1/companies/{$this->company->public_id}/customers";

        $payload = [
            'name_ar' => 'سوبرماركت النور',
            'name_en' => 'Al-Noor Supermarket',
            'phone' => '+970599112233',
            'address' => 'شارع القدس، رام الله',
            'credit_limit' => '1500.00',
        ];

        // 1. First execution: creates customer and receipt
        $response1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson($endpoint, $payload);

        $response1->assertStatus(201)
            ->assertHeader('Idempotency-Key', $idempotencyKey)
            ->assertHeaderMissing('X-Idempotent-Replay');

        $data1 = $response1->json();
        $this->assertNotNull($data1['id']);
        $this->assertSame('سوبرماركت النور', $data1['name_ar']);
        $this->assertSame('Al-Noor Supermarket', $data1['name_en']);
        $this->assertSame('+970599112233', $data1['phone']);
        $this->assertSame('شارع القدس، رام الله', $data1['address']);
        $this->assertSame('1500.00', $data1['credit_limit']);
        $this->assertNull($data1['balance']);
        $this->assertSame('ILS', $data1['currency']);
        $this->assertSame(2, $data1['currency_minor_units']);
        $this->assertSame('active', $data1['status']);

        $customerId = $data1['id'];
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(1, NativeCommandReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->where('command_type', 'customer_create')->count());

        // 2. Lost-ACK retry: identical Idempotency-Key and payload returns 200 replay with frozen snapshot
        $response2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson($endpoint, $payload);

        $response2->assertStatus(200)
            ->assertHeader('Idempotency-Key', $idempotencyKey)
            ->assertHeader('X-Idempotent-Replay', 'true');

        $data2 = $response2->json();
        $this->assertSame($customerId, $data2['id']);
        $this->assertSame($data1, $data2);
        // CRITICAL: Total customers count MUST still be 1 (zero duplicates created)
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

        // 3. Replay with different payload: 409 Conflict
        $conflictingPayload = $payload;
        $conflictingPayload['name_ar'] = 'اسم مختلف تماما';

        $response3 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson($endpoint, $conflictingPayload);

        $response3->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');

        // 4. Cross-actor replay with same key: 403 Forbidden
        $response4 = $this->withHeaders([
            'Authorization' => "Bearer {$this->otherToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson($endpoint, $payload);

        $response4->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // 5. Read receipt status via ReceiptStatusController
        $statusResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
        ])->getJson("/api/mobile/v1/companies/{$this->company->public_id}/receipts/{$idempotencyKey}/status");

        $statusResp->assertStatus(200)
            ->assertJsonPath('command_type', 'customer_create')
            ->assertJsonPath('entity_type', 'customer')
            ->assertJsonPath('entity_public_id', $customerId)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('result.id', $customerId);
    }

    public function test_customer_create_atomic_rollback_on_receipt_failure(): void
    {
        NativeCommandReceiptManager::$failBeforeReceiptInsertForTesting = true;

        $idempotencyKey = 'CUST-FAIL-'.(string) Str::uuid();
        $endpoint = "/api/mobile/v1/companies/{$this->company->public_id}/customers";

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson($endpoint, [
            'name_ar' => 'عميل تجربة الفشل',
            'phone' => '+970599000000',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // Verify total rollback: zero customers and zero receipts in database
        $this->assertSame(0, Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, NativeCommandReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('audit_events')->where('company_id', $this->company->id)->where('event_key', 'like', 'customer%')->count());
    }

    public function test_customer_update_preserves_cold_name_edit_and_explicit_clear_and_zero(): void
    {
        // Setup existing customer with full details
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل قديم',
            'name_en' => 'Old Customer',
            'phone' => '+970599445566',
            'address_ar' => 'شارع الإرسال، البيرة',
            'credit_limit' => '2000.000000',
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $updateKey1 = 'CUST-UPDATE-1-'.(string) Str::uuid();
        $endpoint = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$customer->public_id}";

        // 1. Cold name edit: sending only name_ar MUST NOT erase phone, address, or credit_limit!
        $response1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $updateKey1,
        ])->putJson($endpoint, [
            'name_ar' => 'عميل تم تعديل اسمه فقط',
        ]);

        $response1->assertStatus(200)
            ->assertHeader('Idempotency-Key', $updateKey1);

        $data1 = $response1->json();
        $this->assertSame('عميل تم تعديل اسمه فقط', $data1['name_ar']);
        $this->assertSame('+970599445566', $data1['phone'], 'Phone must be preserved on cold name edit.');
        $this->assertSame('شارع الإرسال، البيرة', $data1['address'], 'Address must be preserved on cold name edit.');
        $this->assertSame('2000.00', $data1['credit_limit'], 'Credit limit must be preserved on cold name edit.');

        $customer->refresh();
        $this->assertSame('عميل تم تعديل اسمه فقط', $customer->name_ar);
        $this->assertSame('+970599445566', $customer->phone);
        $this->assertSame('شارع الإرسال، البيرة', $customer->address_ar);
        $this->assertSame('2000.000000', (string) $customer->credit_limit);

        // 2. Replay with same key: returns 200 with X-Idempotent-Replay
        $response1Replay = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $updateKey1,
        ])->putJson($endpoint, [
            'name_ar' => 'عميل تم تعديل اسمه فقط',
        ]);

        $response1Replay->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('name_ar', 'عميل تم تعديل اسمه فقط');

        // 3. Explicit clearing of phone and address, and credit limit:
        $updateKey2 = 'CUST-UPDATE-2-'.(string) Str::uuid();
        $response2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $updateKey2,
        ])->putJson($endpoint, [
            'name_ar' => 'عميل تم تعديل اسمه فقط',
            'phone' => '', // explicit empty string clears phone
            'address' => null, // explicit null clears address
            'credit_limit' => null, // explicit null clears credit limit
        ]);

        $response2->assertStatus(200);
        $data2 = $response2->json();
        $this->assertNull($data2['phone']);
        $this->assertNull($data2['address']);
        $this->assertNull($data2['credit_limit']);

        $customer->refresh();
        $this->assertNull($customer->phone);
        $this->assertNull($customer->address_ar);
        $this->assertNull($customer->credit_limit);

        // 4. Explicit zero credit limit:
        $updateKey3 = 'CUST-UPDATE-3-'.(string) Str::uuid();
        $response3 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $updateKey3,
        ])->putJson($endpoint, [
            'name_ar' => 'عميل بحد ائتماني صفري',
            'credit_limit' => '0.00',
        ]);

        $response3->assertStatus(200);
        $this->assertSame('0.00', $response3->json('credit_limit'));

        $customer->refresh();
        $this->assertSame('0.000000', (string) $customer->credit_limit);

        // 5. Explicit positive credit limit:
        $updateKey4 = 'CUST-UPDATE-4-'.(string) Str::uuid();
        $response4 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $updateKey4,
        ])->putJson($endpoint, [
            'name_ar' => 'عميل بحد ائتماني جديد',
            'credit_limit' => '3500.50',
        ]);

        $response4->assertStatus(200);
        $this->assertSame('3500.50', $response4->json('credit_limit'));

        $customer->refresh();
        $this->assertSame('3500.500000', (string) $customer->credit_limit);
    }

    public function test_supplier_create_idempotency_lost_ack_replay_and_conflict(): void
    {
        $idempotencyKey = 'SUP-CREATE-'.(string) Str::uuid();
        $endpoint = "/api/mobile/v1/companies/{$this->company->public_id}/suppliers";

        $payload = [
            'name_ar' => 'شركة الأمل للتوريدات',
            'name_en' => 'Al-Amal Supplies Co',
            'phone' => '+970599887766',
            'address' => 'المنطقة الصناعية، نابلس',
            'business_name_ar' => 'الأمل الدولية',
        ];

        // 1. First execution: creates supplier and receipt
        $response1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson($endpoint, $payload);

        $response1->assertStatus(201)
            ->assertHeader('Idempotency-Key', $idempotencyKey)
            ->assertHeaderMissing('X-Idempotent-Replay');

        $data1 = $response1->json();
        $this->assertNotNull($data1['id']);
        $this->assertSame('شركة الأمل للتوريدات', $data1['name_ar']);
        $this->assertSame('Al-Amal Supplies Co', $data1['name_en']);
        $this->assertSame('+970599887766', $data1['phone']);
        $this->assertSame('المنطقة الصناعية، نابلس', $data1['address']);
        $this->assertNull($data1['balance_owed']);
        $this->assertSame('ILS', $data1['currency']);
        $this->assertSame(2, $data1['currency_minor_units']);
        $this->assertSame('active', $data1['status']);

        $supplierId = $data1['id'];
        $this->assertSame(1, Vendor::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(1, NativeCommandReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->where('command_type', 'supplier_create')->count());

        // 2. Lost-ACK retry: returns 200 replay with identical snapshot and NO duplicate in DB
        $response2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson($endpoint, $payload);

        $response2->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true');

        $this->assertSame($supplierId, $response2->json('id'));
        $this->assertSame(1, Vendor::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

        // 3. Replay with modified payload: 409 Conflict
        $conflicting = $payload;
        $conflicting['name_ar'] = 'مورد جديد ببيانات مختلفة';

        $response3 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson($endpoint, $conflicting);

        $response3->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');

        // 4. Cross-actor replay: 403 Forbidden
        $response4 = $this->withHeaders([
            'Authorization' => "Bearer {$this->otherToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson($endpoint, $payload);

        $response4->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // 5. Status lookup: 200 with command_type supplier_create
        $statusResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
        ])->getJson("/api/mobile/v1/companies/{$this->company->public_id}/receipts/{$idempotencyKey}/status");

        $statusResp->assertStatus(200)
            ->assertJsonPath('command_type', 'supplier_create')
            ->assertJsonPath('entity_type', 'supplier')
            ->assertJsonPath('entity_public_id', $supplierId)
            ->assertJsonPath('status', 'completed');
    }

    public function test_supplier_update_preserves_cold_name_edit_and_explicit_clear(): void
    {
        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد التميز',
            'name_en' => 'Excellence Vendor',
            'phone' => '+970599554433',
            'address_ar' => 'شارع السلام، الخليل',
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $updateKey = 'SUP-UPDATE-1-'.(string) Str::uuid();
        $endpoint = "/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$vendor->public_id}";

        // 1. Cold name edit: sending only name_ar preserves phone and address
        $response1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $updateKey,
        ])->putJson($endpoint, [
            'name_ar' => 'مورد التميز المطور',
        ]);

        $response1->assertStatus(200);
        $data1 = $response1->json();
        $this->assertSame('مورد التميز المطور', $data1['name_ar']);
        $this->assertSame('+970599554433', $data1['phone'], 'Phone must be preserved on cold name edit.');
        $this->assertSame('شارع السلام، الخليل', $data1['address'], 'Address must be preserved on cold name edit.');

        $vendor->refresh();
        $this->assertSame('مورد التميز المطور', $vendor->name_ar);
        $this->assertSame('+970599554433', $vendor->phone);
        $this->assertSame('شارع السلام، الخليل', $vendor->address_ar);

        // 2. Replay with same key: returns 200 with X-Idempotent-Replay
        $response1Replay = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $updateKey,
        ])->putJson($endpoint, [
            'name_ar' => 'مورد التميز المطور',
        ]);

        $response1Replay->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true');

        // 3. Explicit clear of phone and address:
        $clearKey = 'SUP-CLEAR-'.(string) Str::uuid();
        $response2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $clearKey,
        ])->putJson($endpoint, [
            'name_ar' => 'مورد التميز المطور',
            'phone' => '',
            'address' => null,
        ]);

        $response2->assertStatus(200);
        $this->assertNull($response2->json('phone'));
        $this->assertNull($response2->json('address'));

        $vendor->refresh();
        $this->assertNull($vendor->phone);
        $this->assertNull($vendor->address_ar);
    }

    public function test_security_gates_missing_key_unauthenticated_and_forbidden(): void
    {
        $endpoint = "/api/mobile/v1/companies/{$this->company->public_id}/customers";

        // Missing Idempotency-Key: 422
        $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
        ])->postJson($endpoint, ['name_ar' => 'اسم عميل'])->assertStatus(422)
            ->assertJsonPath('error.code', 'MISSING_IDEMPOTENCY_KEY');

        // Unauthenticated: 401
        $this->withHeaders([
            'Authorization' => 'Bearer st_m_invalid_token',
            'Idempotency-Key' => 'KEY-NOAUTH',
        ])->postJson($endpoint, ['name_ar' => 'اسم عميل'])->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        // User without customers.manage: 403
        $cashierUser = User::factory()->create(['locale' => 'ar']);
        $cashierUser->companies()->attach($this->company->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);
        $cashierUser->assignRole('Cashier'); // Cashier has customers.view, not customers.manage

        $cashierDeviceId = (string) Str::uuid();
        MobileDevice::create(['device_id' => $cashierDeviceId, 'device_name' => 'Cashier POS', 'user_id' => $cashierUser->id]);
        $cashierToken = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $cashierToken),
            'device_id' => $cashierDeviceId,
            'user_id' => $cashierUser->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $cashierUser->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$cashierToken}",
            'Idempotency-Key' => 'KEY-NOPERM',
        ])->postJson($endpoint, ['name_ar' => 'اسم عميل'])->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_exact_stored_receipt_identity_no_aliasing(): void
    {
        $keyWithSlash = 'pos/1';
        $keyEncoded = 'pos%2F1';

        // 1. Create receipt with literal pos/1
        $res1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $keyWithSlash,
        ])->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers", [
            'name_ar' => 'عميل أول للمفتاح المائل',
        ]);
        $res1->assertStatus(201);

        // Status lookup with pos/1 returns 200 via path and via query parameter
        $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
        ])->getJson("/api/mobile/v1/companies/{$this->company->public_id}/receipts/{$keyWithSlash}/status")
            ->assertStatus(200)
            ->assertJsonPath('idempotency_key', 'pos/1');

        $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
        ])->getJson("/api/mobile/v1/companies/{$this->company->public_id}/receipts/status?idempotency_key=".urlencode($keyWithSlash))
            ->assertStatus(200)
            ->assertJsonPath('idempotency_key', 'pos/1');

        // 2. Status lookup for literal pos%2F1 MUST NOT alias to pos/1 (must return 404 because pos%2F1 does not exist yet)
        // Path: rawurlencode('pos%2F1') => pos%252F1, which framework route matching decodes once to pos%2F1
        $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
        ])->getJson("/api/mobile/v1/companies/{$this->company->public_id}/receipts/".rawurlencode($keyEncoded).'/status')
            ->assertStatus(404);

        // Query: urlencode('pos%2F1') => pos%252F1, which query parsing decodes once to pos%2F1
        $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
        ])->getJson("/api/mobile/v1/companies/{$this->company->public_id}/receipts/status?idempotency_key=".urlencode($keyEncoded))
            ->assertStatus(404);

        // 3. Now create another customer with literal pos%2F1
        $res2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $keyEncoded,
        ])->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers", [
            'name_ar' => 'عميل ثان للمفتاح المشفر',
        ]);
        $res2->assertStatus(201);

        // Now lookup of literal pos%2F1 returns 200 on both path and query
        $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
        ])->getJson("/api/mobile/v1/companies/{$this->company->public_id}/receipts/".rawurlencode($keyEncoded).'/status')
            ->assertStatus(200)
            ->assertJsonPath('idempotency_key', 'pos%2F1');

        $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
        ])->getJson("/api/mobile/v1/companies/{$this->company->public_id}/receipts/status?idempotency_key=".urlencode($keyEncoded))
            ->assertStatus(200)
            ->assertJsonPath('idempotency_key', 'pos%2F1');

        // And pos/1 still returns 200 independently
        $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
        ])->getJson("/api/mobile/v1/companies/{$this->company->public_id}/receipts/{$keyWithSlash}/status")
            ->assertStatus(200)
            ->assertJsonPath('idempotency_key', 'pos/1');

        // Now both exist independently with exact stored key identity!
        $this->assertSame(2, NativeCommandReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertTrue(NativeCommandReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->where('idempotency_key', 'pos/1')->exists());
        $this->assertTrue(NativeCommandReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->where('idempotency_key', 'pos%2F1')->exists());
    }

    public function test_exact_six_credit_limit_storage_and_float_negative_rejection(): void
    {
        $custKey = 'CUST-EXACT6-'.(string) Str::uuid();
        $custResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $custKey,
        ])->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers", [
            'name_ar' => 'عميل ائتمان دقيق',
            'credit_limit' => '123.456789',
        ]);
        $custResp->assertStatus(201);
        $this->assertSame('123.46', $custResp->json('credit_limit'));

        $custId = $custResp->json('id');
        $customer = Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->where('public_id', $custId)->firstOrFail();
        $this->assertSame('123.456789', (string) $customer->credit_limit);

        // Reject decoded JSON floats before string coercion
        $floatKey = 'CUST-FLOAT-'.(string) Str::uuid();
        $floatResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $floatKey,
        ])->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers", [
            'name_ar' => 'عميل رقم عائم',
            'credit_limit' => 99999999999999.01,
        ]);
        $floatResp->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertStringContainsString('Floating point and boolean values are not permitted', (string) $floatResp->json('error.message'));

        // Reject negative credit limit
        $negKey = 'CUST-NEG-'.(string) Str::uuid();
        $negResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $negKey,
        ])->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers", [
            'name_ar' => 'عميل سالب',
            'credit_limit' => '-100.00',
        ]);
        $negResp->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_generic_address_clear_on_english_only_party(): void
    {
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل إنجليزي',
            'name_en' => 'English Customer',
            'phone' => '+970599123456',
            'address_ar' => null,
            'address_en' => '123 Baker Street, London',
            'address_line_1' => '123 Baker Street',
            'credit_limit' => '1000.000000',
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $clearKey = 'CUST-ADDR-CLEAR-'.(string) Str::uuid();
        $resp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $clearKey,
        ])->putJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$customer->public_id}", [
            'address' => null,
        ]);
        $resp->assertStatus(200);
        $this->assertNull($resp->json('address'));

        $customer->refresh();
        $this->assertNull($customer->address_ar);
        $this->assertNull($customer->address_en);
        $this->assertNull($customer->address_line_1_ar);
        $this->assertNull($customer->address_line_1_en);

        // Same for supplier/vendor
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد إنجليزي',
            'name_en' => 'English Vendor',
            'phone' => '+970599654321',
            'address_ar' => null,
            'address_en' => '456 Oxford Street, London',
            'address_line_1_en' => '456 Oxford Street',
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $vClearKey = 'SUP-ADDR-CLEAR-'.(string) Str::uuid();
        $vResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $vClearKey,
        ])->putJson("/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$vendor->public_id}", [
            'address' => null,
        ]);
        $vResp->assertStatus(200);
        $this->assertNull($vResp->json('address'));

        $vendor->refresh();
        $this->assertNull($vendor->address_ar);
        $this->assertNull($vendor->address_en);
    }

    public function test_unknown_field_rejection_and_exact_decimal_normalization_bounds(): void
    {
        // 1. Unknown field rejection on customer create & update
        $custCreateResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => 'CUST-UNK-CREATE-'.Str::uuid(),
        ])->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers", [
            'name_ar' => 'عميل حقل مجهول',
            'unknown_field' => 'hacked',
        ]);
        $custCreateResp->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل اختبار الحقول',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $custUpdateResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => 'CUST-UNK-UPDATE-'.Str::uuid(),
        ])->putJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$customer->public_id}", [
            'unknown_field' => 'hacked',
        ]);
        $custUpdateResp->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 2. Unknown field rejection on supplier create & update
        $suppCreateResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => 'SUP-UNK-CREATE-'.Str::uuid(),
        ])->postJson("/api/mobile/v1/companies/{$this->company->public_id}/suppliers", [
            'name_ar' => 'مورد حقل مجهول',
            'unknown_field' => 'hacked',
        ]);
        $suppCreateResp->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد اختبار الحقول',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $suppUpdateResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => 'SUP-UNK-UPDATE-'.Str::uuid(),
        ])->putJson("/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$vendor->public_id}", [
            'unknown_field' => 'hacked',
        ]);
        $suppUpdateResp->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 3. Exact decimal normalization: 0 vs 0.00
        $normKey = 'CUST-NORM-'.Str::uuid();
        $r1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $normKey,
        ])->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers", [
            'name_ar' => 'عميل تطبيع صفري',
            'credit_limit' => '0',
        ]);
        $r1->assertStatus(201);

        $r2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $normKey,
        ])->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers", [
            'name_ar' => 'عميل تطبيع صفري',
            'credit_limit' => '0.00',
        ]);
        $r2->assertStatus(200)->assertHeader('X-Idempotent-Replay', 'true');
    }

    public function test_update_after_newer_edit_returns_frozen_replay_without_overwriting(): void
    {
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'الاسم الأصلي',
            'phone' => '+970599111222',
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $updateKey1 = 'CUST-UPDATE-STALE-1-'.Str::uuid();
        $r1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $updateKey1,
        ])->putJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$customer->public_id}", [
            'name_ar' => 'تعديل أول قديم',
        ]);
        $r1->assertStatus(200);
        $this->assertSame('تعديل أول قديم', $r1->json('name_ar'));
        $this->assertNull($r1->json('balance'));

        // Direct newer edit
        $customer->refresh();
        $customer->update(['name_ar' => 'تعديل ثان أحدث']);

        // Replay of Update 1 with original key and payload
        $r1Replay = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $updateKey1,
        ])->putJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$customer->public_id}", [
            'name_ar' => 'تعديل أول قديم',
        ]);
        $r1Replay->assertStatus(200)->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame('تعديل أول قديم', $r1Replay->json('name_ar'));

        // Critical: DB customer was NOT overwritten by the replay!
        $customer->refresh();
        $this->assertSame('تعديل ثان أحدث', $customer->name_ar);

        // Replay after soft-delete returns 200 replay snapshot, not 404!
        $customer->delete();
        $r1AfterDelete = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $updateKey1,
        ])->putJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$customer->public_id}", [
            'name_ar' => 'تعديل أول قديم',
        ]);
        $r1AfterDelete->assertStatus(200)->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame('تعديل أول قديم', $r1AfterDelete->json('name_ar'));
    }

    public function test_current_permission_revocation_denies_replay(): void
    {
        $idempotencyKey = 'CUST-REVOKE-'.Str::uuid();
        $endpoint = "/api/mobile/v1/companies/{$this->company->public_id}/customers";

        $r1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson($endpoint, [
            'name_ar' => 'عميل قبل سحب الصلاحية',
        ]);
        $r1->assertStatus(201);

        // Revoke customers.manage permission and Owner role
        setPermissionsTeamId($this->company->id);
        $this->user->removeRole('Owner');
        $this->user->revokePermissionTo('customers.manage');
        $this->user->unsetRelation('roles')->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Replay should now fail with 403 Forbidden!
        $r2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson($endpoint, [
            'name_ar' => 'عميل قبل سحب الصلاحية',
        ]);
        $r2->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

        // Restore permission and role for subsequent tests
        setPermissionsTeamId($this->company->id);
        $this->user->assignRole('Owner');
        $this->user->unsetRelation('roles')->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_immediate_retry_with_exact_key_converges_to_replay(): void
    {
        $key = 'IMMEDIATE-RETRY-'.Str::uuid();
        $endpoint = "/api/mobile/v1/companies/{$this->company->public_id}/customers";
        $payload = ['name_ar' => 'عميل إعادة المحاولة الفورية'];

        $r1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $key,
        ])->postJson($endpoint, $payload);
        $r1->assertStatus(201);

        // Immediate retry with exact key converges to replay
        $r2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $key,
        ])->postJson($endpoint, $payload);
        $r2->assertStatus(200)->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($r1->json('id'), $r2->json('id'));
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name_ar', 'عميل إعادة المحاولة الفورية')->count());
    }

    public function test_new_create_does_not_adopt_existing_unrecorded_party_and_creates_distinct_entity(): void
    {
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        // Existing customer created through web/baseline without a mobile NativeCommandReceipt
        $unrecordedCustomer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل مقطوعية أصلي',
            'phone' => '+970599998877',
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);

        $key = 'NEW-PARTY-CREATE-'.Str::uuid();
        $endpoint = "/api/mobile/v1/companies/{$this->company->public_id}/customers";
        $payload = [
            'name_ar' => 'عميل مقطوعية أصلي',
            'phone' => '+970599998877',
        ];

        // Fresh mobile create MUST NOT adopt the existing unrecorded party!
        // It must create a distinct new entity with its own public_id.
        $resp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $key,
        ])->postJson($endpoint, $payload);

        $resp->assertStatus(201);
        $newCustomerId = $resp->json('id');
        $this->assertNotSame($unrecordedCustomer->public_id, $newCustomerId, 'Legitimate new party must NOT be suppressed or adopted onto existing party.');

        // Total count of customers with this name is now exactly 2
        $this->assertSame(2, Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name_ar', 'عميل مقطوعية أصلي')->count());

        // Subsequent retry of the exact same key replays the newly created customer
        $replay = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $key,
        ])->postJson($endpoint, $payload);
        $replay->assertStatus(200)->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($newCustomerId, $replay->json('id'));
    }

    public function test_english_only_customer_create_without_code_succeeds_without_undefined_name_ar(): void
    {
        $key = 'ENG-CUST-'.Str::uuid();
        $endpoint = "/api/mobile/v1/companies/{$this->company->public_id}/customers";
        $payload = [
            'name_en' => 'Global Retailers Ltd',
            'phone' => '+442071234567',
        ];

        $resp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $key,
        ])->postJson($endpoint, $payload);

        $resp->assertStatus(201);
        $data = $resp->json();
        $this->assertNotNull($data['id']);
        $this->assertSame('Global Retailers Ltd', $data['name_en']);
        $this->assertSame('Global Retailers Ltd', $data['name']);
        $this->assertNull($data['name_ar']);
        $this->assertNull($data['code']);
    }

    public function test_credit_limit_validation_scale_and_rejection_of_booleans_and_floats(): void
    {
        $endpoint = "/api/mobile/v1/companies/{$this->company->public_id}/customers";

        // 1. Scale > 6 (e.g. 0.0000001) must fail 422 with VALIDATION_FAILED, NOT 500!
        $respScale = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => 'LIM-SCALE-'.Str::uuid(),
        ])->postJson($endpoint, [
            'name_ar' => 'عميل دقة زائدة',
            'credit_limit' => '0.0000001',
        ]);
        $respScale->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 2. Boolean true must fail 422, NOT coerce to "1.000000"
        $respTrue = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => 'LIM-TRUE-'.Str::uuid(),
        ])->postJson($endpoint, [
            'name_ar' => 'عميل منطقي موجب',
            'credit_limit' => true,
        ]);
        $respTrue->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 3. Boolean false must fail 422
        $respFalse = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => 'LIM-FALSE-'.Str::uuid(),
        ])->postJson($endpoint, [
            'name_ar' => 'عميل منطقي سالب',
            'credit_limit' => false,
        ]);
        $respFalse->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 4. Float 12.34 must fail 422 before string coercion
        $respFloat = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => 'LIM-FLOAT-'.Str::uuid(),
        ])->postJson($endpoint, [
            'name_ar' => 'عميل رقم عائم',
            'credit_limit' => 12.34,
        ]);
        $respFloat->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 5. Valid string "12.345678" succeeds 201 and preserves 6 decimal storage
        $respValid = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => 'LIM-VALID-'.Str::uuid(),
        ])->postJson($endpoint, [
            'name_ar' => 'عميل ائتمان صحيح',
            'credit_limit' => '12.345678',
        ]);
        $respValid->assertStatus(201);
        $customer = Customer::withoutGlobalScopes()->where('company_id', $this->company->id)->where('public_id', $respValid->json('id'))->firstOrFail();
        $this->assertSame('12.345678', (string) $customer->credit_limit);
    }

    public function test_historical_598c_request_replay_with_name_and_unscaled_credit_limit(): void
    {
        $idempotencyKey = 'HIST-598C-'.Str::uuid();
        $endpoint = "/api/mobile/v1/companies/{$this->company->public_id}/customers";

        // Historical 598c4bf payload: had 'name' (expanded to 'name_ar') and unscaled credit_limit: "12.34"
        $rawPayload = [
            'name' => 'Al-Salam Traders',
            'credit_limit' => '12.34',
        ];
        $h598cNormalized = [
            'credit_limit' => '12.34', // 598c hashed the unscaled limit
            'name' => 'Al-Salam Traders',
            'name_ar' => 'Al-Salam Traders',
        ];
        ksort($h598cNormalized);

        $h598cCanonical = [
            'actor_id' => (int) $this->user->id,
            'device_id' => (string) MobileDevice::where('user_id', $this->user->id)->firstOrFail()->device_id,
            'company_id' => (string) $this->company->public_id,
            'command' => 'customer_create',
            'payload' => $h598cNormalized,
        ];
        $storedHash = hash('sha256', json_encode($h598cCanonical, JSON_THROW_ON_ERROR));

        $snapshot = [
            'id' => (string) Str::ulid(),
            'name' => 'Al-Salam Traders',
            'name_ar' => 'Al-Salam Traders',
            'credit_limit' => '12.34',
        ];

        NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'customer_create',
            'idempotency_key' => $idempotencyKey,
            'actor_id' => $this->user->id,
            'device_id' => $h598cCanonical['device_id'],
            'request_hash' => $storedHash,
            'payload' => $h598cCanonical,
            'response_snapshot' => $snapshot,
            'status' => 'completed',
        ]);

        // Replay of the exact historical request body recovers the receipt via 598c legacy hash
        $resp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson($endpoint, $rawPayload);

        $resp->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($snapshot['id'], $resp->json('id'));
    }

    public function test_historical_supplier_receipt_replay_with_company_name_and_fresh_request_rejection(): void
    {
        $idempotencyKey = 'HIST-SUPP-'.Str::uuid();
        $endpoint = "/api/mobile/v1/companies/{$this->company->public_id}/suppliers";

        // 1. Historical supplier receipt recorded with company_name using ACTUAL pinned 598c handler
        $historicalPayload = [
            'name' => 'Historical Industrial Supplies',
            'company_name' => 'Historical Industrial Supplies',
            'phone' => '+970599114477',
            'preferred_locale' => 'ar',
            'default_currency_code' => 'ILS',
        ];
        // 598c handler: alias-expanded, sorted, includes BOTH company_name AND business_name_ar
        $h598cPayload = [
            'business_name_ar' => 'Historical Industrial Supplies',
            'company_name' => 'Historical Industrial Supplies',
            'default_currency_code' => 'ILS',
            'name' => 'Historical Industrial Supplies',
            'name_ar' => 'Historical Industrial Supplies',
            'phone' => '+970599114477',
            'preferred_locale' => 'ar',
        ];
        ksort($h598cPayload);

        $canonicalHist = [
            'actor_id' => (int) $this->user->id,
            'device_id' => (string) MobileDevice::where('user_id', $this->user->id)->firstOrFail()->device_id,
            'company_id' => (string) $this->company->public_id,
            'command' => 'supplier_create',
            'payload' => $h598cPayload,
        ];
        $storedHash = hash('sha256', json_encode($canonicalHist, JSON_THROW_ON_ERROR));

        $snapshot = [
            'id' => (string) Str::ulid(),
            'name' => 'Historical Industrial Supplies',
            'phone' => '+970599114477',
        ];

        NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'supplier_create',
            'idempotency_key' => $idempotencyKey,
            'actor_id' => $this->user->id,
            'device_id' => $canonicalHist['device_id'],
            'request_hash' => $storedHash,
            'payload' => $canonicalHist,
            'response_snapshot' => $snapshot,
            'status' => 'completed',
        ]);

        // Replaying historical supplier request with company_name must recover receipt before validation rejection!
        $replayResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson($endpoint, $historicalPayload);

        $replayResp->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($snapshot['id'], $replayResp->json('id'));

        // 2. Fresh supplier request with company_name (no existing receipt) MUST reject with 422!
        $freshKey = 'FRESH-SUPP-'.Str::uuid();
        $freshResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $freshKey,
        ])->postJson($endpoint, [
            'company_name' => 'New Unauthorized Supplier',
        ]);
        $freshResp->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertStringContainsString('company_name', (string) $freshResp->json('error.message'));

        // 3. Fresh supplier request with address_line_1_ar MUST reject with 422!
        $freshAddrResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => 'FRESH-ADDR-'.Str::uuid(),
        ])->postJson($endpoint, [
            'name_ar' => 'مورد بعنوان غير مدعوم',
            'address_line_1_ar' => 'شارع غير مدعوم',
        ]);
        $freshAddrResp->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertStringContainsString('address_line_1_ar', (string) $freshAddrResp->json('error.message'));

        // 4. Historical supplier receipt recorded with address_line_1_ar using ACTUAL pinned 598c handler
        $histAddrKey = 'HIST-ADDR-'.Str::uuid();
        $historicalAddrPayload = [
            'name' => 'Historical Address Supplies',
            'address_line_1_ar' => 'شارع يافا القديم',
            'phone' => '+970599335577',
        ];
        $h598cAddrPayload = [
            'address_line_1_ar' => 'شارع يافا القديم',
            'name' => 'Historical Address Supplies',
            'name_ar' => 'Historical Address Supplies',
            'phone' => '+970599335577',
        ];
        ksort($h598cAddrPayload);
        $canonicalHistAddr = [
            'actor_id' => (int) $this->user->id,
            'device_id' => (string) MobileDevice::where('user_id', $this->user->id)->firstOrFail()->device_id,
            'company_id' => (string) $this->company->public_id,
            'command' => 'supplier_create',
            'payload' => $h598cAddrPayload,
        ];
        $storedAddrHash = hash('sha256', json_encode($canonicalHistAddr, JSON_THROW_ON_ERROR));
        $snapshotAddr = [
            'id' => (string) Str::ulid(),
            'name' => 'Historical Address Supplies',
            'phone' => '+970599335577',
        ];
        NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'supplier_create',
            'idempotency_key' => $histAddrKey,
            'actor_id' => $this->user->id,
            'device_id' => $canonicalHistAddr['device_id'],
            'request_hash' => $storedAddrHash,
            'payload' => $canonicalHistAddr,
            'response_snapshot' => $snapshotAddr,
            'status' => 'completed',
        ]);
        $replayAddrResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $histAddrKey,
        ])->postJson($endpoint, $historicalAddrPayload);
        $replayAddrResp->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($snapshotAddr['id'], $replayAddrResp->json('id'));
    }

    public function test_historical_supplier_update_replay_with_company_name_and_fresh_rejection(): void
    {
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد التحديث الأصلي',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $idempotencyKey = 'HIST-SUPP-UPD-'.Str::uuid();
        $endpoint = "/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$vendor->public_id}";

        $updatePayload = [
            'company_name' => 'Updated Historical Supplier',
            'phone' => '+970599223344',
        ];

        // 598c update handler: alias-expanded, sorted, includes both company_name AND business_name_ar
        $h598cPayload = [
            'business_name_ar' => 'Updated Historical Supplier',
            'company_name' => 'Updated Historical Supplier',
            'phone' => '+970599223344',
        ];
        ksort($h598cPayload);

        $canonicalHist = [
            'actor_id' => (int) $this->user->id,
            'device_id' => (string) MobileDevice::where('user_id', $this->user->id)->firstOrFail()->device_id,
            'company_id' => (string) $this->company->public_id,
            'supplier_id' => $vendor->public_id,
            'command' => 'supplier_update',
            'payload' => $h598cPayload,
        ];
        $storedHash = hash('sha256', json_encode($canonicalHist, JSON_THROW_ON_ERROR));

        $snapshot = [
            'id' => (string) $vendor->public_id,
            'name' => 'Updated Historical Supplier',
            'phone' => '+970599223344',
        ];

        NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'supplier_update',
            'idempotency_key' => $idempotencyKey,
            'actor_id' => $this->user->id,
            'device_id' => $canonicalHist['device_id'],
            'request_hash' => $storedHash,
            'payload' => $canonicalHist,
            'response_snapshot' => $snapshot,
            'status' => 'completed',
        ]);

        // Replay of exact old update body recovers the receipt
        $replayResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $idempotencyKey,
        ])->putJson($endpoint, $updatePayload);

        $replayResp->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($snapshot['id'], $replayResp->json('id'));

        // Fresh update with company_name (no existing receipt) MUST reject with 422
        $freshKey = 'FRESH-UPD-'.Str::uuid();
        $freshResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $freshKey,
        ])->putJson($endpoint, [
            'company_name' => 'Fresh Unauthorized Supplier Update',
        ]);
        $freshResp->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertStringContainsString('company_name', (string) $freshResp->json('error.message'));

        // Modern update with business_name_ar must NOT match or alias when replayed with company_name
        $modernKey = 'MODERN-UPD-'.Str::uuid();
        $modernResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $modernKey,
        ])->putJson($endpoint, [
            'business_name_ar' => 'شركة تحديث حديثة',
        ]);
        $modernResp->assertStatus(200);

        $aliasResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $modernKey,
        ])->putJson($endpoint, [
            'company_name' => 'شركة تحديث حديثة',
        ]);
        $this->assertTrue(in_array($aliasResp->status(), [409, 422], true));

        // Historical supplier update receipt recorded with address_line_1_ar using ACTUAL pinned 598c handler
        $histUpdAddrKey = 'HIST-UPD-ADDR-'.Str::uuid();
        $historicalUpdAddrPayload = [
            'name' => 'مورد تحديث بعنوان تاريخي',
            'address_line_1_ar' => 'شارع القدس القديم',
            'phone' => '+970599446688',
        ];
        $h598cUpdAddrPayload = [
            'address_line_1_ar' => 'شارع القدس القديم',
            'name' => 'مورد تحديث بعنوان تاريخي',
            'name_ar' => 'مورد تحديث بعنوان تاريخي',
            'phone' => '+970599446688',
        ];
        ksort($h598cUpdAddrPayload);
        $canonicalHistUpdAddr = [
            'actor_id' => (int) $this->user->id,
            'device_id' => (string) MobileDevice::where('user_id', $this->user->id)->firstOrFail()->device_id,
            'company_id' => (string) $this->company->public_id,
            'supplier_id' => $vendor->public_id,
            'command' => 'supplier_update',
            'payload' => $h598cUpdAddrPayload,
        ];
        $storedUpdAddrHash = hash('sha256', json_encode($canonicalHistUpdAddr, JSON_THROW_ON_ERROR));
        $snapshotUpdAddr = [
            'id' => (string) $vendor->public_id,
            'name' => 'مورد تحديث بعنوان تاريخي',
            'phone' => '+970599446688',
        ];
        NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'supplier_update',
            'idempotency_key' => $histUpdAddrKey,
            'actor_id' => $this->user->id,
            'device_id' => $canonicalHistUpdAddr['device_id'],
            'request_hash' => $storedUpdAddrHash,
            'payload' => $canonicalHistUpdAddr,
            'response_snapshot' => $snapshotUpdAddr,
            'status' => 'completed',
        ]);
        $replayUpdAddrResp = $this->withHeaders([
            'Authorization' => "Bearer {$this->rawToken}",
            'Idempotency-Key' => $histUpdAddrKey,
        ])->putJson($endpoint, $historicalUpdAddrPayload);
        $replayUpdAddrResp->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($snapshotUpdAddr['id'], $replayUpdAddrResp->json('id'));
    }

    public function test_valid_100_percent_discount_purchase_canonical_read_and_tampered_negative_rejection(): void
    {
        $this->actingAs($this->user);
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'WH-DISC-'.Str::random(4),
            'name' => 'Warehouse Discount',
            'name_ar' => 'مستودع الخصم',
            'active' => true,
            'created_by' => $this->user->id,
        ]);
        $unit = Unit::firstOrCreate([
            'company_id' => $this->company->id,
            'code' => 'PCS',
        ], [
            'name_ar' => 'قطعة',
            'name_en' => 'Piece',
            'symbol' => 'pc',
        ]);
        $product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'منتج مجاني بالكامل',
            'base_unit_id' => $unit->id,
            'item_type' => 'inventory',
            'track_stock' => true,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $productUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_purchase' => true,
            'is_default_sale' => true,
            'active' => true,
        ]);
        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد العينات المجانية',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $companyToday = Carbon::now($this->company->timezone ?? 'UTC')->toDateString();

        // 1. Valid 100% discount purchase created and posted canonically
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->user, [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'purchase_date' => $companyToday,
            'currency_code' => $this->company->base_currency_code,
            'exchange_rate' => '1',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $productUnit->id,
                    'quantity' => '1',
                    'unit_cost' => '10',
                    'discount_type' => 'fixed',
                    'discount_value' => '10',
                ],
            ],
        ]);
        $purchase = app(PostPurchaseAction::class)->execute($draft, $this->user);

        $this->assertNull($purchase->posting_batch_id);
        $this->assertSame('0.000000', (string) $purchase->grand_total_currency);

        // PurchasePayableAsOf components evaluates cleanly and yields zero payable
        $asOf = Carbon::now($this->company->timezone ?? 'UTC');
        $positions = app(PurchasePayableAsOf::class)->components([$purchase], $asOf);
        $this->assertArrayHasKey($purchase->id, $positions);
        $this->assertSame('0.000000', (string) $positions[$purchase->id]['outstanding']);

        // HTTP Index returns the 100% discount purchase cleanly
        $indexResp = $this->withHeader('Authorization', "Bearer {$this->rawToken}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases");
        $indexResp->assertStatus(200);
        $found = collect((array) $indexResp->json('purchases'))->firstWhere('id', $purchase->public_id);
        $this->assertNotNull($found);
        $this->assertSame('0.000000', $found['remaining_exact']);

        // HTTP Detail returns the purchase cleanly
        $detailResp = $this->withHeader('Authorization', "Bearer {$this->rawToken}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchase->public_id}");
        $detailResp->assertStatus(200);
        $this->assertSame('0.000000', $detailResp->json('remaining_exact'));

        // 2. Tampered fixtures: test subtotal, discount, and tax independently
        app(CompanyContext::class)->setCompany($this->company, $this->user);

        // a) Tamper discount_total_currency 10 -> 0 (header/line consistency lost)
        DB::table('purchases')->where('id', $purchase->id)->update(['discount_total_currency' => '0.000000']);
        try {
            app(PurchasePayableAsOf::class)->components([$purchase->fresh()], $asOf);
            $this->fail('Tampered discount_total_currency must throw Incoherent historical Purchase posting provenance.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Incoherent historical Purchase posting provenance.', $e->getMessage());
        }
        DB::table('purchases')->where('id', $purchase->id)->update(['discount_total_currency' => '10.000000']);

        // b) Tamper subtotal_currency 10 -> 0
        DB::table('purchases')->where('id', $purchase->id)->update(['subtotal_currency' => '0.000000']);
        try {
            app(PurchasePayableAsOf::class)->components([$purchase->fresh()], $asOf);
            $this->fail('Tampered subtotal_currency must throw Incoherent historical Purchase posting provenance.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Incoherent historical Purchase posting provenance.', $e->getMessage());
        }
        DB::table('purchases')->where('id', $purchase->id)->update(['subtotal_currency' => '10.000000']);

        // c) Tamper tax_total_currency 0 -> 1
        DB::table('purchases')->where('id', $purchase->id)->update(['tax_total_currency' => '1.000000']);
        try {
            app(PurchasePayableAsOf::class)->components([$purchase->fresh()], $asOf);
            $this->fail('Tampered tax_total_currency must throw Incoherent historical Purchase posting provenance.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Incoherent historical Purchase posting provenance.', $e->getMessage());
        }
        DB::table('purchases')->where('id', $purchase->id)->update(['tax_total_currency' => '0.000000']);

        // d) Tamper grand_total_currency to negative
        DB::table('purchases')->where('id', $purchase->id)->update(['grand_total_currency' => '-1.000000']);
        try {
            app(PurchasePayableAsOf::class)->components([$purchase->fresh()], $asOf);
            $this->fail('Tampered grand_total_currency must throw Incoherent historical Purchase posting provenance.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Incoherent historical Purchase posting provenance.', $e->getMessage());
        }
        DB::table('purchases')->where('id', $purchase->id)->update(['grand_total_currency' => '0.000000']);

        // 3. Multiple free purchases bounded query count verification
        $this->actingAs($this->user);
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $draft2 = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->user, [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'purchase_date' => $companyToday,
            'currency_code' => $this->company->base_currency_code,
            'exchange_rate' => '1',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $productUnit->id,
                    'quantity' => '2',
                    'unit_cost' => '5',
                    'discount_type' => 'fixed',
                    'discount_value' => '10',
                ],
            ],
        ]);
        $purchase2 = app(PostPurchaseAction::class)->execute($draft2, $this->user);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $multiPositions = app(PurchasePayableAsOf::class)->components([$purchase->fresh(), $purchase2->fresh()], $asOf);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertArrayHasKey($purchase->id, $multiPositions);
        $this->assertArrayHasKey($purchase2->id, $multiPositions);
        $this->assertSame('0.000000', (string) $multiPositions[$purchase->id]['outstanding']);
        $this->assertSame('0.000000', (string) $multiPositions[$purchase2->id]['outstanding']);

        // Assert query count across multiple free purchases is bounded with no duplicate lazy reloads
        $lineTableQueries = collect($queries)->filter(fn ($q) => str_contains($q['query'], 'purchase_lines'));
        $this->assertLessThanOrEqual(2, $lineTableQueries->count(), 'Query count across multiple free purchases must remain bounded with no duplicate lazy reloads');
    }

    public function test_customer_update_snapshot_preserves_six_decimal_credit_limit_exact6(): void
    {
        $this->actingAs($this->user);
        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'CUST-EXACT-'.Str::random(4),
            'name_ar' => 'عميل دقة السقف',
            'status' => 'active',
            'credit_limit' => '123.456789',
            'created_by' => $this->user->id,
        ]);

        // 1. Detail endpoint returns both minor-unit display and exact6 authoritative limit
        $detailResp = $this->withHeader('Authorization', "Bearer {$this->rawToken}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$customer->public_id}");
        $detailResp->assertStatus(200);
        $this->assertSame('123.46', $detailResp->json('credit_limit'));
        $this->assertSame('123.456789', $detailResp->json('credit_limit_exact'));
        $this->assertSame('123.456789', $detailResp->json('credit_limit_exact6'));

        // 2. Cold name update without credit_limit preserves stored 6-decimal limit in DB and snapshot
        $updateResp = $this->withHeader('Authorization', "Bearer {$this->rawToken}")
            ->withHeader('Idempotency-Key', 'UPD-EXACT-'.Str::uuid())
            ->putJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$customer->public_id}", [
                'name_ar' => 'عميل دقة السقف المعدل',
            ]);
        $updateResp->assertStatus(200);
        $this->assertSame('123.46', $updateResp->json('credit_limit'));
        $this->assertSame('123.456789', $updateResp->json('credit_limit_exact'));
        $this->assertSame('123.456789', $updateResp->json('credit_limit_exact6'));

        $freshCustomer = $customer->fresh();
        $this->assertSame('123.456789', (string) $freshCustomer->credit_limit);
        $this->assertSame('عميل دقة السقف المعدل', $freshCustomer->name_ar);

        // 3. Update using credit_limit_exact6 explicitly sets exact limit
        $updateExactResp = $this->withHeader('Authorization', "Bearer {$this->rawToken}")
            ->withHeader('Idempotency-Key', 'UPD-EXACT2-'.Str::uuid())
            ->putJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$customer->public_id}", [
                'credit_limit_exact6' => '987.654321',
            ]);
        $updateExactResp->assertStatus(200);
        $this->assertSame('987.65', $updateExactResp->json('credit_limit'));
        $this->assertSame('987.654321', $updateExactResp->json('credit_limit_exact6'));
        $this->assertSame('987.654321', (string) $customer->fresh()->credit_limit);
    }

    public function test_supplier_detail_and_directory_expose_authoritative_owed_and_advances_separately(): void
    {
        $this->actingAs($this->user);
        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'code' => 'SUPP-ADV-'.Str::random(4),
            'name_ar' => 'مورد سلفة دائنة',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        // When no transactions, owed and advances are 0.00
        $detailResp = $this->withHeader('Authorization', "Bearer {$this->rawToken}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$vendor->public_id}");
        $detailResp->assertStatus(200);
        $this->assertSame('0.00', $detailResp->json('balance_owed'));
        $this->assertSame('0.00', $detailResp->json('owed'));
        $this->assertSame('0.00', $detailResp->json('advances'));

        $dirResp = $this->withHeader('Authorization', "Bearer {$this->rawToken}")
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/suppliers");
        $dirResp->assertStatus(200);
        $found = collect((array) $dirResp->json('suppliers'))->firstWhere('id', $vendor->public_id);
        $this->assertNotNull($found);
        $this->assertSame('0.00', $found['balance_owed']);
        $this->assertSame('0.00', $found['owed']);
        $this->assertSame('0.00', $found['advances']);
    }
}
