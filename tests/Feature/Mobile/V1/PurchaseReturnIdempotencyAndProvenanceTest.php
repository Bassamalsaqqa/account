<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V1;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\VoidPurchaseReturnAction;
use App\Models\Company;
use App\Models\CompanyPurchaseSetting;
use App\Models\Currency;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\NativeCommandReceipt;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Mobile\Receipt\NativeCommandReceiptManager;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PurchaseReturnIdempotencyAndProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Vendor $vendor;

    private Warehouse $warehouse;

    private Warehouse $warehouse2;

    private Unit $unit;

    private Product $product;

    private Product $product2;

    private string $deviceId;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();

        config([
            'mobile.enabled' => true,
            'mobile.merchant_api_enabled' => true,
        ]);

        Currency::updateOrCreate(['code' => 'ILS'], ['name' => 'Shekel', 'symbol' => 'ILS', 'minor_units' => 2, 'active' => true]);

        $this->user = User::factory()->create([
            'password' => Hash::make('secret-password-123'),
            'locale' => 'ar',
        ]);

        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة فحص المرتجعات المشتريات',
            'name_en' => 'Purchase Returns Audit Co',
            'base_currency_code' => 'ILS',
        ]);

        $this->actingAs($this->user);
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        setPermissionsTeamId($this->company->id);
        $this->user->unsetRelation('roles')->unsetRelation('permissions');

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();

        $this->warehouse2 = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'WH-BRANCH-2',
            'name_ar' => 'مستودع الفرع الثاني',
            'name_en' => 'Branch 2 Warehouse',
            'active' => true,
            'is_transit' => false,
        ]);

        $pieceUnit = Unit::where('code', 'piece')->first();
        if ($pieceUnit === null) {
            $pieceUnit = Unit::create([
                'company_id' => $this->company->id,
                'code' => 'PCS',
                'name_ar' => 'قطعة',
                'allows_fraction' => false,
                'decimal_places' => 0,
                'active' => true,
            ]);
        }
        $this->unit = $pieceUnit;

        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج مشتريات رئيسي',
            'name_en' => 'Main Purchase Product',
            'sku' => 'SKU-RET-MAIN',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'default_sale_price_base' => '25.000000',
            'default_purchase_cost_base' => '12.000000',
            'base_unit_id' => $this->unit->id,
        ], $this->user->id);

        $this->product2 = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج مشتريات ثانوي',
            'name_en' => 'Secondary Purchase Product',
            'sku' => 'SKU-RET-SEC',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'default_sale_price_base' => '30.000000',
            'default_purchase_cost_base' => '15.000000',
            'base_unit_id' => $this->unit->id,
        ], $this->user->id);

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->user, [
            'name_ar' => 'مورد فحص المرتجعات',
            'name_en' => 'Returns Test Vendor',
            'default_currency_code' => 'ILS',
        ]);

        CompanyPurchaseSetting::updateOrCreate(
            ['company_id' => $this->company->id],
            ['default_receiving_warehouse_id' => $this->warehouse->id]
        );

        $this->deviceId = (string) Str::uuid();
        $device = MobileDevice::create([
            'device_id' => $this->deviceId,
            'device_name' => 'Returns QA Device',
            'user_id' => $this->user->id,
        ]);

        $this->token = 'st_ret_'.bin2hex(random_bytes(32));
        $accessToken = MobileAccessToken::create([
            'token_hash' => hash('sha256', $this->token),
            'device_id' => $this->deviceId,
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->user->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        auth()->forgetGuards();
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $idempotencyKey): array
    {
        return [
            'Authorization' => 'Bearer '.$this->token,
            'Idempotency-Key' => $idempotencyKey,
            'Accept' => 'application/json',
        ];
    }

    private function bindContext(): void
    {
        $this->actingAs($this->user);
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        setPermissionsTeamId($this->company->id);
    }

    public function test_exact_replay_returns_200_with_idempotent_header_and_does_not_duplicate_purchase_return(): void
    {
        // 1. Create staged purchase bill for 100 units
        $postResponse = $this->withHeaders($this->authHeaders('bill-ret-replay-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-RET-REPLAY',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '100', 'unit_cost_ils' => '12.00'],
                ],
            ]);
        $postResponse->assertStatus(201);
        $purchasePublicId = $postResponse->json('id');

        $this->bindContext();
        $purchase = Purchase::where('public_id', $purchasePublicId)->firstOrFail();
        $line = $purchase->lines()->firstOrFail();

        // 2. Receive 50 units via GRN into main warehouse
        $grnResponse = $this->withHeaders($this->authHeaders('grn-ret-replay-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '50'],
                ],
            ]);
        $grnResponse->assertStatus(201);
        $grnPublicId = $grnResponse->json('id');

        // 3. Post physical purchase return for 10 units with idempotency key
        $returnPayload = [
            'purchase_id' => $purchasePublicId,
            'warehouse_id' => $this->warehouse->public_id,
            'goods_receipt_note_id' => $grnPublicId,
            'reason' => 'Defective batch returned to vendor',
            'lines' => [
                ['purchase_line_id' => $line->public_id, 'quantity' => '10'],
            ],
        ];

        $firstReturn = $this->withHeaders($this->authHeaders('ret-idemp-key-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", $returnPayload);

        $firstReturn->assertStatus(201)
            ->assertHeader('Idempotency-Key', 'ret-idemp-key-1')
            ->assertJsonPath('status', 'posted');

        $returnId = $firstReturn->json('id');
        $this->assertNotEmpty($returnId);

        $this->bindContext();
        $returnCountAfterFirst = PurchaseReturn::where('company_id', $this->company->id)->count();
        $this->assertSame(1, $returnCountAfterFirst, 'Exactly one purchase return record must exist after first post');

        // Verify durable NativeCommandReceipt exists
        $receipt = NativeCommandReceipt::where('company_id', $this->company->id)
            ->where('idempotency_key', 'ret-idemp-key-1')
            ->first();
        $this->assertNotNull($receipt, 'Durable native command receipt must be persisted');
        $this->assertSame('purchase_return', $receipt->command_type);
        $this->assertSame($returnId, $receipt->entity_public_id);
        $this->assertSame('completed', $receipt->status);

        $stockMovementCountAfterFirst = StockMovement::where('company_id', $this->company->id)->count();

        // 4. LostACK replay: identical request with identical idempotency key
        $replayReturn = $this->withHeaders($this->authHeaders('ret-idemp-key-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", $returnPayload);

        $replayReturn->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertHeader('Idempotency-Key', 'ret-idemp-key-1')
            ->assertJsonPath('id', $returnId)
            ->assertJsonPath('status', 'posted');

        // Invariant: zero duplicate records in DB
        $this->bindContext();
        $returnCountAfterReplay = PurchaseReturn::where('company_id', $this->company->id)->count();
        $this->assertSame(1, $returnCountAfterReplay, 'Replay must NOT create duplicate PurchaseReturn records');

        $stockMovementCountAfterReplay = StockMovement::where('company_id', $this->company->id)->count();
        $this->assertSame($stockMovementCountAfterFirst, $stockMovementCountAfterReplay, 'Replay must NOT create duplicate stock movements');
    }

    public function test_same_key_different_payload_returns_409_conflict(): void
    {
        $postResponse = $this->withHeaders($this->authHeaders('bill-ret-conflict-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-RET-CONFLICT',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '50', 'unit_cost_ils' => '12.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');

        $this->bindContext();
        $purchase = Purchase::where('public_id', $purchasePublicId)->firstOrFail();
        $line = $purchase->lines()->firstOrFail();

        $grnResponse = $this->withHeaders($this->authHeaders('grn-ret-conflict-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '30'],
                ],
            ]);
        $grnPublicId = $grnResponse->json('id');

        // First submission with 5 units
        $firstReturn = $this->withHeaders($this->authHeaders('ret-conflict-key'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", [
                'purchase_id' => $purchasePublicId,
                'warehouse_id' => $this->warehouse->public_id,
                'goods_receipt_note_id' => $grnPublicId,
                'reason' => 'First attempt',
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '5'],
                ],
            ]);
        $firstReturn->assertStatus(201);

        // Same idempotency key, different quantity (8 units) => HTTP 409 IDEMPOTENCY_KEY_CONFLICT
        $conflictReturn = $this->withHeaders($this->authHeaders('ret-conflict-key'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", [
                'purchase_id' => $purchasePublicId,
                'warehouse_id' => $this->warehouse->public_id,
                'goods_receipt_note_id' => $grnPublicId,
                'reason' => 'Modified quantity attempt',
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '8'],
                ],
            ]);

        $conflictReturn->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
    }

    public function test_same_key_cross_actor_or_device_returns_403_forbidden(): void
    {
        $postResponse = $this->withHeaders($this->authHeaders('bill-ret-actor-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-RET-ACTOR',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '20', 'unit_cost_ils' => '10.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');

        $this->bindContext();
        $purchase = Purchase::where('public_id', $purchasePublicId)->firstOrFail();
        $line = $purchase->lines()->firstOrFail();

        $returnPayload = [
            'purchase_id' => $purchasePublicId,
            'warehouse_id' => Warehouse::where('company_id', $this->company->id)->where('is_transit', true)->firstOrFail()->public_id,
            'reason' => 'Transit pre-return',
            'lines' => [
                ['purchase_line_id' => $line->public_id, 'quantity' => '5'],
            ],
        ];

        // User A / Device A executes
        $res = $this->withHeaders($this->authHeaders('ret-cross-key'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", $returnPayload);
        $res->assertStatus(201);

        // User B / Device B attempts to replay the same key
        $device2Id = (string) Str::uuid();
        MobileDevice::create([
            'device_id' => $device2Id,
            'device_name' => 'Attacker Device',
            'user_id' => $this->user->id,
        ]);
        $token2 = 'st_ret_2_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $token2),
            'device_id' => $device2Id,
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->user->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        $crossReplay = $this->withHeaders([
            'Authorization' => 'Bearer '.$token2,
            'Idempotency-Key' => 'ret-cross-key',
            'Accept' => 'application/json',
        ])->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", $returnPayload);

        $crossReplay->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_direct_receipt_purchase_return_replay_does_not_duplicate_records(): void
    {
        $this->bindContext();
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->user, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => today()->toDateString(),
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '40', 'unit_cost' => '10']],
        ]);
        $purchase = app(PostPurchaseAction::class)->execute($draft, $this->user);
        $this->assertFalse((bool) $purchase->is_staged);
        $purchasePublicId = $purchase->public_id;
        $line = $purchase->lines()->firstOrFail();

        $returnPayload = [
            'purchase_id' => $purchasePublicId,
            'warehouse_id' => $this->warehouse->public_id,
            'reason' => 'Direct receipt return',
            'lines' => [
                ['purchase_line_id' => $line->public_id, 'quantity' => '15'],
            ],
        ];

        $firstReturn = $this->withHeaders($this->authHeaders('direct-ret-ack-key'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", $returnPayload);

        $firstReturn->assertStatus(201);
        $retId = $firstReturn->json('id');

        $this->bindContext();
        $this->assertSame(1, PurchaseReturn::where('purchase_id', $purchase->id)->count());

        app(VoidPurchaseReturnAction::class)->execute(
            PurchaseReturn::where('public_id', $retId)->firstOrFail(), $this->user, 'Supplier declined', today()->toDateString()
        );
        // LostACK replay
        $replay = $this->withHeaders($this->authHeaders('direct-ret-ack-key'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", $returnPayload);

        $replay->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('id', $retId);

        $this->assertSame($firstReturn->json(), $replay->json());

        $this->bindContext();
        $this->assertSame(1, PurchaseReturn::where('purchase_id', $purchase->id)->count(), 'Direct return replay must NOT create duplicate return');
    }

    public function test_malformed_lines_and_unknown_purchase_line_rejected(): void
    {
        $postResponse = $this->withHeaders($this->authHeaders('bill-malformed-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-MALFORMED',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '10', 'unit_cost_ils' => '10.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');

        // 1. Unknown purchase line ULID
        $fakeUlid = (string) Str::ulid();
        $resUnknown = $this->withHeaders($this->authHeaders('ret-bad-line-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", [
                'purchase_id' => $purchasePublicId,
                'lines' => [
                    ['purchase_line_id' => $fakeUlid, 'quantity' => '5'],
                ],
            ]);
        $resUnknown->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $uri = "/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns";
        $valid = ['purchase_id' => $purchasePublicId, 'lines' => [['product_id' => $this->product->public_id, 'quantity' => '1']]];
        foreach ([
            ['warehouse_id' => $fakeUlid],
            ['warehouse_id' => ['invalid']],
            ['goods_receipt_note_id' => 8],
            ['lines' => [['purchase_line_id' => $fakeUlid, 'product_id' => $this->product->public_id, 'quantity' => '1']]],
            ['lines' => [['product_id' => $this->product->public_id, 'quantity' => '1.0000001']]],
        ] as $invalid) {
            $this->withHeaders($this->authHeaders('invalid-return'))->postJson($uri, $invalid + $valid)->assertStatus(422);
        }
        $this->assertDatabaseCount('purchase_returns', 0);

        // 2. Negative quantity
        $this->bindContext();
        $purchase = Purchase::where('public_id', $purchasePublicId)->firstOrFail();
        $line = $purchase->lines()->firstOrFail();

        $resNegative = $this->withHeaders($this->authHeaders('ret-bad-line-2'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", [
                'purchase_id' => $purchasePublicId,
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '-5'],
                ],
            ]);
        $resNegative->assertStatus(422);

        // 3. Zero quantity
        $resZero = $this->withHeaders($this->authHeaders('ret-bad-line-3'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", [
                'purchase_id' => $purchasePublicId,
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '0'],
                ],
            ]);
        $resZero->assertStatus(422);
    }

    public function test_return_quantity_exceeding_remaining_returnable_is_rejected(): void
    {
        $postResponse = $this->withHeaders($this->authHeaders('bill-excess-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-EXCESS',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '10', 'unit_cost_ils' => '10.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $line = Purchase::where('public_id', $purchasePublicId)->firstOrFail()->lines()->firstOrFail();

        // Attempt to return 15 units when bill has only 10
        $resExcess = $this->withHeaders($this->authHeaders('ret-excess-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", [
                'purchase_id' => $purchasePublicId,
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '15'],
                ],
            ]);
        $resExcess->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_mixed_grn_or_mixed_warehouse_origins_rejected_before_effects(): void
    {
        // 1. Bill with 2 products
        $postResponse = $this->withHeaders($this->authHeaders('bill-mixed-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-MIXED-ORGS',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '20', 'unit_cost_ils' => '10.00'],
                    ['product_id' => $this->product2->public_id, 'quantity' => '20', 'unit_cost_ils' => '15.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $purchase = Purchase::where('public_id', $purchasePublicId)->firstOrFail();
        $line1 = $purchase->lines()->where('product_id', $this->product->id)->firstOrFail();
        $line2 = $purchase->lines()->where('product_id', $this->product2->id)->firstOrFail();

        // GRN 1: receives Line 1 into Warehouse 1
        $grn1 = $this->withHeaders($this->authHeaders('grn-mixed-wh1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [
                    ['purchase_line_id' => $line1->public_id, 'quantity' => '20'],
                ],
            ]);
        $grn1->assertStatus(201);
        $grn1Id = $grn1->json('id');

        // GRN 2: receives Line 2 into Warehouse 2
        $grn2 = $this->withHeaders($this->authHeaders('grn-mixed-wh2'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse2->public_id,
                'lines' => [
                    ['purchase_line_id' => $line2->public_id, 'quantity' => '20'],
                ],
            ]);
        $grn2->assertStatus(201);
        $grn2Id = $grn2->json('id');

        // Attempt return that mixes Line 1 (in Warehouse 1/GRN 1) and Line 2 (in Warehouse 2/GRN 2)
        $mixedReturn = $this->withHeaders($this->authHeaders('ret-mixed-origins'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", [
                'purchase_id' => $purchasePublicId,
                'reason' => 'Attempting mixed warehouse return',
                'lines' => [
                    ['purchase_line_id' => $line1->public_id, 'quantity' => '5'],
                    ['purchase_line_id' => $line2->public_id, 'quantity' => '5'],
                ],
            ]);

        $mixedReturn->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_requested_warehouse_mismatching_actual_grn_warehouse_is_rejected(): void
    {
        $postResponse = $this->withHeaders($this->authHeaders('bill-wh-mismatch-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-WH-MISMATCH',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '30', 'unit_cost_ils' => '10.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $line = Purchase::where('public_id', $purchasePublicId)->firstOrFail()->lines()->firstOrFail();

        // Received in Warehouse 1
        $grn = $this->withHeaders($this->authHeaders('grn-wh-mismatch-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '30'],
                ],
            ]);
        $grnId = $grn->json('id');

        // Return requests Warehouse 2 while specifying GRN 1 (which was received in Warehouse 1)
        $mismatched = $this->withHeaders($this->authHeaders('ret-wh-mismatch-key'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", [
                'purchase_id' => $purchasePublicId,
                'warehouse_id' => $this->warehouse2->public_id,
                'goods_receipt_note_id' => $grnId,
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '5'],
                ],
            ]);

        $mismatched->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_injected_failure_rolls_back_entire_return_and_receipt(): void
    {
        $postResponse = $this->withHeaders($this->authHeaders('bill-rollback-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-ROLLBACK',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '20', 'unit_cost_ils' => '10.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $line = Purchase::where('public_id', $purchasePublicId)->firstOrFail()->lines()->firstOrFail();

        $this->withHeaders($this->authHeaders('rollback-receipt'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [['purchase_line_id' => $line->public_id, 'quantity' => '10']],
            ])->assertCreated();
        $tables = ['inventory_operations', 'stock_movements', 'inventory_balances', 'posting_batches', 'posting_lines', 'document_sequences',
            'purchase_returns', 'purchase_return_lines', 'purchase_return_allocations', 'native_command_receipts'];
        $fingerprint = fn () => array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
        $before = $fingerprint();

        NativeCommandReceiptManager::$failBeforeReceiptInsertForTesting = true;

        try {
            $failedReturn = $this->withHeaders($this->authHeaders('ret-rollback-key'))
                ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", [
                    'purchase_id' => $purchasePublicId,
                    'warehouse_id' => $this->warehouse->public_id,
                    'reason' => 'Rollback test',
                    'lines' => [
                        ['purchase_line_id' => $line->public_id, 'quantity' => '5'],
                    ],
                ]);

            $failedReturn->assertStatus(422);
            $this->assertStringContainsString('Injected failure', $failedReturn->json('error.message'));
            $this->assertSame($before, $fingerprint());

            $this->bindContext();
            $this->assertSame(0, PurchaseReturn::where('company_id', $this->company->id)->count(), 'PurchaseReturn must roll back completely');
            $this->assertSame(0, NativeCommandReceipt::where('idempotency_key', 'ret-rollback-key')->count(), 'NativeCommandReceipt must not exist after rollback');
        } finally {
            NativeCommandReceiptManager::$failBeforeReceiptInsertForTesting = false;
        }
    }

    public function test_receipt_status_lookup_for_purchase_return(): void
    {
        $postResponse = $this->withHeaders($this->authHeaders('bill-status-check-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-STATUS-CHECK',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '15', 'unit_cost_ils' => '10.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $line = Purchase::where('public_id', $purchasePublicId)->firstOrFail()->lines()->firstOrFail();
        $transitWh = Warehouse::where('company_id', $this->company->id)->where('is_transit', true)->firstOrFail();

        $returnResp = $this->withHeaders($this->authHeaders('ret-status-check-key'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", [
                'purchase_id' => $purchasePublicId,
                'warehouse_id' => $transitWh->public_id,
                'reason' => 'Return for status check',
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '3'],
                ],
            ]);
        $returnResp->assertStatus(201);
        $returnId = $returnResp->json('id');

        // Lookup receipt status via mobile API
        $statusResp = $this->withHeaders($this->authHeaders('status-reader-key'))
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/receipts/ret-status-check-key/status");

        $statusResp->assertStatus(200)
            ->assertJsonPath('command_type', 'purchase_return')
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('entity_public_id', $returnId);
    }

    public function test_mismatched_purchase_line_and_product_id_rejected_and_preview_enforces_match(): void
    {
        $postResponse = $this->withHeaders($this->authHeaders('bill-mismatch-ids-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-MISMATCH-IDS',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '10', 'unit_cost_ils' => '10.00'],
                    ['product_id' => $this->product2->public_id, 'quantity' => '15', 'unit_cost_ils' => '20.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $purchase = Purchase::where('public_id', $purchasePublicId)->firstOrFail();
        $line1 = $purchase->lines()->where('product_id', $this->product->id)->firstOrFail();
        $line2 = $purchase->lines()->where('product_id', $this->product2->id)->firstOrFail();

        // 1. storeReturn with line1 ID but product2 ID => 422
        $resMismatch = $this->withHeaders($this->authHeaders('ret-mismatch-post'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", [
                'purchase_id' => $purchasePublicId,
                'lines' => [
                    ['purchase_line_id' => $line1->public_id, 'product_id' => $this->product2->public_id, 'quantity' => '2'],
                ],
            ]);
        $resMismatch->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 2. previewReturn with mismatched IDs does not accept wrong line when product matches
        $previewMismatch = $this->withHeaders($this->authHeaders('ret-mismatch-prev'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns/preview", [
                'purchase_id' => $purchasePublicId,
                'lines' => [
                    ['purchase_line_id' => $line1->public_id, 'product_id' => $this->product2->public_id, 'quantity' => '2'],
                ],
            ]);
        $previewMismatch->assertStatus(200);
        $this->assertEmpty($previewMismatch->json('lines'));

        // 3. previewReturn with matched IDs succeeds
        $previewMatched = $this->withHeaders($this->authHeaders('ret-matched-prev'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns/preview", [
                'purchase_id' => $purchasePublicId,
                'lines' => [
                    ['purchase_line_id' => $line1->public_id, 'product_id' => $this->product->public_id, 'quantity' => '2'],
                ],
            ]);
        $previewMatched->assertStatus(200);
        $this->assertCount(1, $previewMatched->json('lines'));
        $this->assertSame($line1->public_id, $previewMatched->json('lines.0.purchase_line_id'));
    }
}
