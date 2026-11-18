<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V1;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\RecordPurchaseGoodsReceiptAction;
use App\Domain\Purchasing\DTO\GoodsReceiptLineCommand;
use App\Domain\Purchasing\DTO\RecordGoodsReceiptCommand;
use App\Models\Company;
use App\Models\CompanyPurchaseSetting;
use App\Models\Currency;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryBalance;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PurchaseGoodsReceiptWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Vendor $vendor;

    private Warehouse $warehouse;

    private Unit $unit;

    private Product $product;

    private ProductUnit $productUnit;

    private string $deviceId;

    private string $token;

    private MobileAccessToken $accessToken;

    private MobileDevice $device;

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
            'name_ar' => 'شركة المشتريات والتوريد',
            'name_en' => 'Purchasing & Supply Co',
            'base_currency_code' => 'ILS',
        ]);

        $this->actingAs($this->user);
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        setPermissionsTeamId($this->company->id);
        $this->user->unsetRelation('roles')->unsetRelation('permissions');

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();

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
            'name_ar' => 'منتج تجريبي',
            'name_en' => 'Test Product',
            'sku' => 'SKU-TEST-1',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'default_sale_price_base' => '20.000000',
            'default_purchase_cost_base' => '10.000000',
            'base_unit_id' => $this->unit->id,
        ], $this->user->id);

        $this->productUnit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->user, [
            'name_ar' => 'مورد رئيسي',
            'name_en' => 'Main Vendor',
            'default_currency_code' => 'ILS',
        ]);

        CompanyPurchaseSetting::updateOrCreate(
            ['company_id' => $this->company->id],
            ['default_receiving_warehouse_id' => $this->warehouse->id]
        );

        $this->deviceId = (string) Str::uuid();
        $this->device = MobileDevice::create([
            'device_id' => $this->deviceId,
            'device_name' => 'QA Physical Device',
            'user_id' => $this->user->id,
        ]);

        $this->token = 'st_m_'.bin2hex(random_bytes(32));
        $this->accessToken = MobileAccessToken::create([
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

    private function getStock(Warehouse $wh, Product $prod): string
    {
        $this->bindContext();
        $bal = InventoryBalance::where('company_id', $this->company->id)
            ->where('warehouse_id', $wh->id)
            ->where('product_id', $prod->id)
            ->value('quantity_base');

        return (string) ($bal ?? '0.000000');
    }

    public function test_can_post_staged_purchase_bill_and_receive_goods_partially_and_fully(): void
    {
        // 1. Post Staged Purchase Bill via Mobile API
        $postResponse = $this->withHeaders($this->authHeaders('post-purchase-stage-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-STAGE-001',
                'lines' => [
                    [
                        'product_id' => $this->product->public_id,
                        'quantity' => '100',
                        'unit_cost_ils' => '10.00',
                    ],
                ],
            ]);

        $postResponse->assertStatus(201)
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('total_ils', '1000.00');

        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $purchase = Purchase::where('public_id', $purchasePublicId)->firstOrFail();
        $this->assertTrue($purchase->is_staged);

        // Verify In-Transit warehouse exists and holds the initial purchase stock
        $transitWh = Warehouse::where('company_id', $this->company->id)->where('is_transit', true)->firstOrFail();
        $this->assertSame($transitWh->id, $purchase->warehouse_id);

        $line = $purchase->lines()->firstOrFail();

        // Check show endpoint indicates pending receiving status
        $showResponse = $this->withHeaders($this->authHeaders('show-1'))
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}");

        $showResponse->assertStatus(200)
            ->assertJsonPath('receiving_status', 'pending')
            ->assertJsonPath('lines.0.ordered_quantity', '100.000000')
            ->assertJsonPath('lines.0.previously_received_quantity', '0.000000')
            ->assertJsonPath('lines.0.remaining_quantity', '100.000000')
            ->assertJsonPath('lines.0.is_received', false);

        // Verify stock movements prior to GRN: Physical warehouse has 0
        $this->assertSame('0.000000', $this->getStock($this->warehouse, $this->product));
        $this->assertSame('100.000000', $this->getStock($transitWh, $this->product));

        // 2. Partial GRN (40 units)
        $grn1Response = $this->withHeaders($this->authHeaders('grn-test-key-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'receipt_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'purchase_line_id' => $line->public_id,
                        'quantity' => '40',
                    ],
                ],
            ]);

        if ($grn1Response->status() !== 201) {
            $this->fail($grn1Response->getContent());
        }
        $grn1Response->assertStatus(201)
            ->assertHeader('Idempotency-Key', 'grn-test-key-1')
            ->assertHeaderMissing('X-Idempotent-Replay')
            ->assertJsonPath('grn_number', 'GRN-'.now()->format('Y').'-0001')
            ->assertJsonPath('overall_receiving_status', 'partial')
            ->assertJsonPath('lines.0.received_now', '40.000000')
            ->assertJsonPath('lines.0.remaining_quantity', '60.000000')
            ->assertJsonPath('lines.0.is_line_complete', false);

        // Physical warehouse now has 40, Transit has 60
        $this->assertSame('40.000000', $this->getStock($this->warehouse, $this->product));
        $this->assertSame('60.000000', $this->getStock($transitWh, $this->product));

        // 3. Second Partial GRN completing the order (60 units)
        $grn2Response = $this->withHeaders($this->authHeaders('grn-test-key-2'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'receipt_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'purchase_line_id' => $line->public_id,
                        'quantity' => '60',
                    ],
                ],
            ]);

        $grn2Response->assertStatus(201)
            ->assertJsonPath('grn_number', 'GRN-'.now()->format('Y').'-0002')
            ->assertJsonPath('overall_receiving_status', 'received')
            ->assertJsonPath('lines.0.received_now', '60.000000')
            ->assertJsonPath('lines.0.remaining_quantity', '0.000000')
            ->assertJsonPath('lines.0.is_line_complete', true);

        // Physical warehouse now has full 100, Transit is 0
        $this->assertSame('100.000000', $this->getStock($this->warehouse, $this->product));
        $this->assertSame('0.000000', $this->getStock($transitWh, $this->product));

        // Show endpoint now reflects 'received' status
        $showAfter = $this->withHeaders($this->authHeaders('show-2'))
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}");

        $showAfter->assertStatus(200)
            ->assertJsonPath('receiving_status', 'received')
            ->assertJsonPath('lines.0.previously_received_quantity', '100.000000')
            ->assertJsonPath('lines.0.remaining_quantity', '0.000000')
            ->assertJsonPath('lines.0.is_received', true);
    }

    public function test_idempotency_exact_replay_returns_cached_snapshot_with_200_and_header(): void
    {
        $postResponse = $this->withHeaders($this->authHeaders('post-purchase-replay'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-REPLAY',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '50', 'unit_cost_ils' => '10.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $line = Purchase::where('public_id', $purchasePublicId)->firstOrFail()->lines()->firstOrFail();

        $payload = [
            'destination_warehouse_id' => $this->warehouse->public_id,
            'receipt_date' => now()->format('Y-m-d'),
            'lines' => [
                ['purchase_line_id' => $line->public_id, 'quantity' => '25'],
            ],
        ];

        // First call: 201 Created
        $first = $this->withHeaders($this->authHeaders('idemp-replay-key'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", $payload);
        $first->assertStatus(201);
        $firstGrnId = $first->json('id');

        $this->bindContext();
        $initialGrnCount = GoodsReceiptNote::count();
        $initialMovementCount = StockMovement::count();

        // Exact replay: 200 OK with X-Idempotent-Replay: true
        $second = $this->withHeaders($this->authHeaders('idemp-replay-key'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", $payload);

        $second->assertStatus(200)
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJsonPath('id', $firstGrnId);

        // Zero duplicate entities created
        $this->bindContext();
        $this->assertSame($initialGrnCount, GoodsReceiptNote::count());
        $this->assertSame($initialMovementCount, StockMovement::count());
    }

    public function test_idempotency_conflict_returns_409_when_payload_differs(): void
    {
        $postResponse = $this->withHeaders($this->authHeaders('post-purchase-conflict'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-CONFLICT',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '50', 'unit_cost_ils' => '10.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $line = Purchase::where('public_id', $purchasePublicId)->firstOrFail()->lines()->firstOrFail();

        // First call with quantity 20
        $this->withHeaders($this->authHeaders('idemp-conflict-key'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '20'],
                ],
            ])
            ->assertStatus(201);

        // Conflict call with same key but quantity 30
        $conflict = $this->withHeaders($this->authHeaders('idemp-conflict-key'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '30'],
                ],
            ]);

        $conflict->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
    }

    public function test_over_receipt_protection_returns_422_when_requested_qty_exceeds_remaining(): void
    {
        $postResponse = $this->withHeaders($this->authHeaders('post-purchase-overreceive'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-OVERRECEIVE',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '10', 'unit_cost_ils' => '10.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $line = Purchase::where('public_id', $purchasePublicId)->firstOrFail()->lines()->firstOrFail();

        // Attempt to receive 15 units when ordered is only 10
        $response = $this->withHeaders($this->authHeaders('overreceive-key'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '15'],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'OVER_RECEIPT_LIMIT_EXCEEDED');
    }

    public function test_revalidation_fails_when_token_or_device_or_password_revoked(): void
    {
        $postResponse = $this->withHeaders($this->authHeaders('post-purchase-reval'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-REVAL',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '10', 'unit_cost_ils' => '10.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $line = Purchase::where('public_id', $purchasePublicId)->firstOrFail()->lines()->firstOrFail();

        // 1. Revoked Token
        $this->accessToken->update(['revoked_at' => now()]);
        $respRevokedToken = $this->withHeaders($this->authHeaders('key-revoked-token'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [['purchase_line_id' => $line->public_id, 'quantity' => '5']],
            ]);
        $respRevokedToken->assertStatus(401);

        // Restore token, then revoke device
        $this->accessToken->update(['revoked_at' => null]);
        $this->device->update(['revoked_at' => now()]);
        $respRevokedDevice = $this->withHeaders($this->authHeaders('key-revoked-dev'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [['purchase_line_id' => $line->public_id, 'quantity' => '5']],
            ]);
        $respRevokedDevice->assertStatus(401);

        // Restore device, then change password
        $this->device->update(['revoked_at' => null]);
        $this->user->update(['password' => Hash::make('new-brand-new-password')]);
        $respPasswordEpoch = $this->withHeaders($this->authHeaders('key-revised-pw'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [['purchase_line_id' => $line->public_id, 'quantity' => '5']],
            ]);
        $respPasswordEpoch->assertStatus(401);
    }

    public function test_transaction_rollback_preserves_zero_orphan_movements_on_failure(): void
    {
        $postResponse = $this->withHeaders($this->authHeaders('post-purchase-rollback'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-ROLLBACK',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '20', 'unit_cost_ils' => '10.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $purchase = Purchase::where('public_id', $purchasePublicId)->firstOrFail();
        $line = $purchase->lines()->firstOrFail();

        $beforeGrnCount = GoodsReceiptNote::count();
        $beforeMovementCount = StockMovement::count();

        $lineCmd = new GoodsReceiptLineCommand(
            purchaseLineId: (int) $line->id,
            purchaseLinePublicId: (string) $line->public_id,
            quantity: BigDecimal::of('10'),
            lotNumber: null,
            expiryDate: null
        );

        $command = new RecordGoodsReceiptCommand(
            companyId: (int) $this->company->id,
            purchaseId: (int) $purchase->id,
            destinationWarehouseId: (int) $this->warehouse->id,
            receiptDate: now()->format('Y-m-d'),
            lines: [$lineCmd],
            idempotencyKey: 'simulated-failure-key',
            createdBy: (int) $this->user->id,
            notes: null
        );

        // Simulate failure by introducing an intentional exception during the action
        // or by executing inside a transaction that catches an exception
        try {
            DB::transaction(function () use ($purchase, $command) {
                app(RecordPurchaseGoodsReceiptAction::class)->execute(
                    $this->company,
                    $purchase,
                    $this->user,
                    $command,
                    $this->accessToken,
                    $this->device
                );
                throw new Exception('Simulated post-transfer crash before commit');
            });
        } catch (Exception $e) {
            $this->assertSame('Simulated post-transfer crash before commit', $e->getMessage());
        }

        // Entire transaction rolled back cleanly
        $this->assertSame($beforeGrnCount, GoodsReceiptNote::count());
        $this->assertSame($beforeMovementCount, StockMovement::count());
    }

    public function test_expiry_tracked_lot_and_zero_cost_purchase_grn(): void
    {
        $this->bindContext();

        $expUnit = Unit::firstOrCreate([
            'company_id' => $this->company->id,
            'code' => 'BOX',
        ], [
            'name_ar' => 'صندوق',
            'allows_fraction' => false,
            'decimal_places' => 0,
            'active' => true,
        ]);

        $expProduct = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'EXPIRY-ZERO-COST',
            'name_ar' => 'منتج بصلاحية وتكلفة صفرية',
            'base_unit_id' => $expUnit->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => true,
            'default_sale_price_base' => '50.000000',
            'default_purchase_cost_base' => '0.000000',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $expProduct->id,
            'unit_id' => $expUnit->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'default_sale_price_base' => '50.000000',
            'active' => true,
        ]);

        app(CompanyContext::class)->clear();

        // Post zero-cost staged purchase with expiry lot
        $postResponse = $this->withHeaders($this->authHeaders('post-purchase-exp-zero'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-EXP-ZERO',
                'lines' => [
                    [
                        'product_id' => $expProduct->public_id,
                        'quantity' => '25',
                        'unit_cost_ils' => '0.00',
                        'lots' => [
                            [
                                'lot_number' => 'LOT-EXP-2026',
                                'expiry_date' => '2027-12-31',
                                'quantity' => '25',
                            ],
                        ],
                    ],
                ],
            ]);

        $postResponse->assertStatus(201);
        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $purchase = Purchase::where('public_id', $purchasePublicId)->firstOrFail();
        $line = $purchase->lines()->firstOrFail();

        // Perform GRN
        $grnResponse = $this->withHeaders($this->authHeaders('grn-exp-zero-key'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [
                    [
                        'purchase_line_id' => $line->public_id,
                        'quantity' => '25',
                        'lot_number' => 'LOT-EXP-2026',
                    ],
                ],
            ]);

        $grnResponse->assertStatus(201)
            ->assertJsonPath('overall_receiving_status', 'received')
            ->assertJsonPath('lines.0.remaining_quantity', '0.000000');

        // Check inventory movement unit cost is 0.000000 and lot is preserved
        $this->bindContext();
        $grn = GoodsReceiptNote::where('public_id', $grnResponse->json('id'))->firstOrFail();
        $grnLine = $grn->lines()->firstOrFail();
        $inMovement = $grnLine->transferInMovement;
        $this->assertSame('0.000000', (string) $inMovement->unit_cost_base);
        $this->assertSame((int) $this->warehouse->id, (int) $inMovement->warehouse_id);
    }

    public function test_multi_grn_and_multi_destination_warehouse(): void
    {
        $this->bindContext();

        // Create second physical warehouse
        $warehouse2 = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'WH-BRANCH-2',
            'name_ar' => 'فرع 2',
            'is_transit' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        app(CompanyContext::class)->clear();

        $postResponse = $this->withHeaders($this->authHeaders('post-purchase-multidest'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-MULTIDEST',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '100', 'unit_cost_ils' => '10.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $line = Purchase::where('public_id', $purchasePublicId)->firstOrFail()->lines()->firstOrFail();

        // Receive 35 units into Warehouse 1
        $this->withHeaders($this->authHeaders('grn-dest1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [['purchase_line_id' => $line->public_id, 'quantity' => '35']],
            ])
            ->assertStatus(201)
            ->assertJsonPath('overall_receiving_status', 'partial')
            ->assertJsonPath('lines.0.remaining_quantity', '65.000000');

        // Receive 65 units into Warehouse 2
        $this->withHeaders($this->authHeaders('grn-dest2'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $warehouse2->public_id,
                'lines' => [['purchase_line_id' => $line->public_id, 'quantity' => '65']],
            ])
            ->assertStatus(201)
            ->assertJsonPath('overall_receiving_status', 'received')
            ->assertJsonPath('lines.0.remaining_quantity', '0.000000');

        $this->bindContext();
        $this->assertSame('35.000000', $this->getStock($this->warehouse, $this->product));
        $this->assertSame('65.000000', $this->getStock($warehouse2, $this->product));
    }

    public function test_pre_receipt_return_vs_post_receipt_return_no_double_subtraction(): void
    {
        // 1. Create staged purchase of 100 units
        $postResponse = $this->withHeaders($this->authHeaders('post-purchase-return-math'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases", [
                'supplier_id' => $this->vendor->public_id,
                'bill_number' => 'BILL-RETURN-MATH',
                'lines' => [
                    ['product_id' => $this->product->public_id, 'quantity' => '100', 'unit_cost_ils' => '10.00'],
                ],
            ]);
        $purchasePublicId = $postResponse->json('id');
        $this->bindContext();
        $purchase = Purchase::where('public_id', $purchasePublicId)->firstOrFail();
        $line = $purchase->lines()->firstOrFail();
        $transitWh = Warehouse::where('company_id', $this->company->id)->where('is_transit', true)->firstOrFail();

        // 2. Pre-receipt return: 30 units returned directly from transit to vendor before any GRN
        $preReturnResponse = $this->withHeaders($this->authHeaders('pre-receipt-ret-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", [
                'purchase_id' => $purchasePublicId,
                'warehouse_id' => $transitWh->public_id,
                'reason' => 'Vendor recalled defective items before store delivery',
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '30'],
                ],
            ]);
        $preReturnResponse->assertStatus(201);

        // Check show endpoint: remaining should now be 70 (100 ordered - 30 pre-returned)
        $showResp = $this->withHeaders($this->authHeaders('show-after-prereturn'))
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}");
        $showResp->assertStatus(200)
            ->assertJsonPath('lines.0.remaining_quantity', '70.000000');

        // 3. First GRN receives 50 units into physical store
        $grn1 = $this->withHeaders($this->authHeaders('grn-rec-50'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '50'],
                ],
            ]);
        $grn1->assertStatus(201)
            ->assertJsonPath('lines.0.remaining_quantity', '20.000000')
            ->assertJsonPath('overall_receiving_status', 'partial');

        $grn1PublicId = $grn1->json('id');

        // 4. Post-receipt physical return: 10 units returned from physical warehouse
        // This coordinates transfer back from physical warehouse to transit warehouse, then posts purchase return
        $postReturnResponse = $this->withHeaders($this->authHeaders('post-receipt-ret-1'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns", [
                'purchase_id' => $purchasePublicId,
                'warehouse_id' => $this->warehouse->public_id,
                'goods_receipt_note_id' => $grn1PublicId,
                'reason' => 'Customer store rejection after inspection',
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '10'],
                ],
            ]);
        $postReturnResponse->assertStatus(201);

        // 5. CRUCIAL INVARIANT: Post-receipt return must NOT double-subtract remaining receivable!
        // Ordered: 100
        // Received via GRN: 50
        // Pre-receipt returned: 30
        // Remaining to receive: 100 - 50 - 30 = 20.
        // It must NOT be (20 - 10 = 10) because the 10 units were already received and transferred out of transit!
        $showRespAfterPostRet = $this->withHeaders($this->authHeaders('show-after-postreturn'))
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}");
        $showRespAfterPostRet->assertStatus(200)
            ->assertJsonPath('lines.0.remaining_quantity', '20.000000');

        // 6. Complete final GRN of exactly 20 units without over-receipt error
        $grn2 = $this->withHeaders($this->authHeaders('grn-rec-final-20'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchasePublicId}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '20'],
                ],
            ]);
        $grn2->assertStatus(201)
            ->assertJsonPath('lines.0.remaining_quantity', '0.000000')
            ->assertJsonPath('overall_receiving_status', 'received');
    }
}
