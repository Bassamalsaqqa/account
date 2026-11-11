<?php

declare(strict_types=1);

namespace Tests\Benchmark;

use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MoneyAccount;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Mobile\CatalogSnapshot;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

final class Catalog10kBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Warehouse $warehouse;

    private Customer $customer;

    private MoneyAccount $cashAccount;

    private Unit $unitPiece;

    private TaxRate $taxRate;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Mandatory testing guards: ensure testing environment, owned disposable schema, and refuse persistent 3353
        if (! app()->environment('testing')) {
            throw new RuntimeException('Catalog10kBenchmarkTest refused: APP_ENV is not testing.');
        }

        $dbName = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($dbName);

        $port = (int) (DB::connection()->getConfig('port') ?? 0);
        if ($port === 3353) {
            throw new RuntimeException('Forbidden: Catalog10kBenchmarkTest cannot run on persistent 3353 database.');
        }

        config(['mobile.enabled' => true]);

        // 2. Company & roles setup
        $this->user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $this->company = Company::factory()->create([
            'name_ar' => 'شركة مؤهلة للاختبار المعياري',
            'name_en' => '10k Benchmark Qualified Company',
            'base_currency_code' => 'ILS',
            'status' => 'active',
        ]);

        $this->user->companies()->attach($this->company->id, ['status' => 'active', 'is_owner' => false]);
        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($this->company);
        setPermissionsTeamId($this->company->id);
        $this->user->assignRole('Cashier');

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        // 3. Operational entities for Bootstrap & Products
        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'WH-BENCH-MAIN',
            'name_ar' => 'المستودع الرئيسي',
            'name_en' => 'Main Benchmark Warehouse',
            'active' => true,
            'is_default' => true,
            'created_by' => $this->user->id,
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'CUST-BENCH-WALKIN',
            'name_ar' => 'عميل نقدي',
            'name_en' => 'Walk-in Customer',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $ledgerAccount = LedgerAccount::create([
            'company_id' => $this->company->id,
            'code' => '1010',
            'name_ar' => 'النقدية',
            'name_en' => 'Cash',
            'account_type' => LedgerAccount::TYPE_ASSET,
            'normal_balance' => LedgerAccount::BALANCE_DEBIT,
            'is_control' => false,
            'is_system' => false,
            'active' => true,
        ]);

        $this->cashAccount = MoneyAccount::create([
            'company_id' => $this->company->id,
            'account_type' => 'cash',
            'name_ar' => 'صندوق نقاط البيع',
            'name_en' => 'Cash Register 01 (ILS)',
            'currency_code' => 'ILS',
            'ledger_account_id' => $ledgerAccount->id,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->unitPiece = Unit::create([
            'company_id' => $this->company->id,
            'code' => 'PCS',
            'name_ar' => 'حبة',
            'name_en' => 'Piece',
            'symbol_en' => 'pc',
            'allows_fraction' => false,
            'decimal_places' => 0,
            'active' => true,
        ]);

        $this->taxRate = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT16',
            'name_ar' => 'ضريبة القيمة المضافة',
            'name_en' => 'Standard VAT (16%)',
            'rate' => '16.000000',
            'calculation' => 'exclusive',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        CompanyMobileSetting::create([
            'company_id' => $this->company->id,
            'walk_in_customer_id' => $this->customer->id,
            'approved_cash_account_id' => $this->cashAccount->id,
            'default_warehouse_id' => $this->warehouse->id,
            'default_tax_rate_id' => $this->taxRate->id,
            'offline_policy_enabled' => false,
        ]);

        // 4. Issue authenticated mobile token
        $rawToken = 'st_m_'.bin2hex(random_bytes(32));
        $deviceId = (string) Str::uuid();
        MobileDevice::create(['device_id' => $deviceId, 'device_name' => 'Tablet-10k-Bench', 'user_id' => $this->user->id]);
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $rawToken),
            'device_id' => $deviceId,
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->user->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);
        $this->token = $rawToken;
    }

    public function test_10k_product_catalog_qualification_benchmark(): void
    {
        $dbName = DB::connection()->getDatabaseName();
        fwrite(STDOUT, "Owned schema for 10k qualification: {$dbName}\n");

        $seedSec = $this->seed10kProducts();

        $companyPublicId = $this->company->public_id;

        // Endpoints to benchmark
        $endpoints = [
            'bootstrap' => [
                'name' => 'Bootstrap',
                'uri' => "/api/mobile/v1/companies/{$companyPublicId}/bootstrap",
            ],
            'products_limit_1' => [
                'name' => 'Product Limit 1',
                'uri' => "/api/mobile/v1/companies/{$companyPublicId}/products?limit=1",
            ],
            'products_limit_30' => [
                'name' => 'Product Limit 30',
                'uri' => "/api/mobile/v1/companies/{$companyPublicId}/products?limit=30",
            ],
            'nonmatching_search' => [
                'name' => 'Non-matching Search',
                'uri' => "/api/mobile/v1/companies/{$companyPublicId}/products?search=NONEXISTENT_XYZ_QUERY",
            ],
        ];

        $coldResults = [];
        $warmResults = [];

        // Cold run
        foreach ($endpoints as $key => $ep) {
            $metric = $this->measureRequest($ep['uri']);
            $coldResults[$key] = $metric;

            fwrite(STDOUT, "\n=== [{$ep['name']}] ===\n");
            fwrite(STDOUT, "URI: {$ep['uri']}\n");
            fwrite(STDOUT, "Status: {$metric['status']}\n");
            fwrite(STDOUT, "Latency: {$metric['latency_ms']} ms\n");
            fwrite(STDOUT, "Peak Memory Delta: {$metric['peak_memory_kb']} KB\n");
            fwrite(STDOUT, "Total DB Queries: {$metric['queries']}\n");
        }

        // Warm run
        foreach ($endpoints as $key => $ep) {
            $metric = $this->measureRequest($ep['uri']);
            $warmResults[$key] = $metric;
        }

        // EXPLAIN plans for actual chunk and page queries
        $explainProducts = DB::select(
            'EXPLAIN SELECT p.id, p.public_id, p.sku, p.name_ar, p.name_en, p.product_type, p.active, p.base_unit_id, p.default_sale_price_base, p.track_stock, p.updated_at FROM products as p WHERE p.company_id = ? AND p.active = 1 AND p.deleted_at IS NULL AND p.id > ? ORDER BY p.id ASC LIMIT ?',
            [$this->company->id, 0, CatalogSnapshot::CHUNK_SIZE]
        );

        $explainProductUnits = DB::select(
            'EXPLAIN SELECT pu.id, pu.product_id, p.public_id as product_public_id, pu.unit_id, u.public_id as unit_public_id, pu.conversion_to_base, pu.is_base, pu.is_default_sale, pu.default_sale_price_base, pu.active, pu.updated_at FROM product_units as pu JOIN products as p ON p.id = pu.product_id JOIN units as u ON u.id = pu.unit_id WHERE pu.company_id = ? AND p.company_id = ? AND u.company_id = ? AND pu.active = 1 AND p.active = 1 AND p.deleted_at IS NULL AND pu.id > ? ORDER BY pu.id ASC LIMIT ?',
            [$this->company->id, $this->company->id, $this->company->id, 0, CatalogSnapshot::CHUNK_SIZE]
        );

        $explainProductPage = DB::select(
            'EXPLAIN SELECT id, public_id, company_id, sku, name_ar, name_en, track_stock, product_type, default_sale_price_base, base_unit_id, updated_at FROM products WHERE company_id = ? AND active = 1 AND deleted_at IS NULL ORDER BY public_id ASC LIMIT 31',
            [$this->company->id]
        );

        fwrite(STDOUT, "\n=== EXPLAIN Plans for Key Queries ===\n");
        fwrite(STDOUT, "EXPLAIN Keyset Chunk Products (chunk=256):\n".json_encode($explainProducts, JSON_PRETTY_PRINT)."\n\n");
        fwrite(STDOUT, "EXPLAIN Keyset Chunk Product Units (chunk=256):\n".json_encode($explainProductUnits, JSON_PRETTY_PRINT)."\n\n");
        fwrite(STDOUT, "EXPLAIN Product Page (limit=30):\n".json_encode($explainProductPage, JSON_PRETTY_PRINT)."\n\n");

        // Structured JSON qualification summary
        $metricsPayload = [
            'schema' => $dbName,
            'total_products' => 10000,
            'seed_duration_sec' => round($seedSec, 2),
            'cold' => array_map(fn (array $r): array => [
                'status' => $r['status'],
                'latency_ms' => $r['latency_ms'],
                'queries' => $r['queries'],
                'peak_memory_kb' => $r['peak_memory_kb'],
                'memory_delta_kb' => $r['memory_delta_kb'],
            ], $coldResults),
            'warm' => array_map(fn (array $r): array => [
                'status' => $r['status'],
                'latency_ms' => $r['latency_ms'],
                'queries' => $r['queries'],
                'peak_memory_kb' => $r['peak_memory_kb'],
                'memory_delta_kb' => $r['memory_delta_kb'],
            ], $warmResults),
            'explain_plans' => [
                'keyset_chunk_products' => $explainProducts,
                'keyset_chunk_product_units' => $explainProductUnits,
                'product_page_limit_30' => $explainProductPage,
            ],
        ];

        fwrite(STDOUT, "=== QUALIFICATION_METRICS_JSON ===\n".json_encode($metricsPayload, JSON_UNESCAPED_SLASHES)."\n=== END_QUALIFICATION_METRICS_JSON ===\n");

        // Assertions: truthful correctness, contract compliance, no flaky latency thresholds
        $this->assertSame(200, $coldResults['bootstrap']['status']);
        $this->assertSame(200, $coldResults['products_limit_1']['status']);
        $this->assertSame(200, $coldResults['products_limit_30']['status']);
        $this->assertSame(200, $coldResults['nonmatching_search']['status']);

        // Bootstrap data integrity
        $bootstrapData = $coldResults['bootstrap']['response']->json();
        $this->assertSame($companyPublicId, $bootstrapData['company_id']);
        $this->assertNotEmpty($bootstrapData['catalog_snapshot_version']);

        // Products limit 1 integrity
        $p1Data = $coldResults['products_limit_1']['response']->json();
        $this->assertCount(1, $p1Data['products']);
        $this->assertTrue($p1Data['has_more']);
        $this->assertNotNull($p1Data['next_cursor']);
        $this->assertSame($bootstrapData['catalog_snapshot_version'], $p1Data['catalog_snapshot_version']);

        // Products limit 30 integrity
        $p30Data = $coldResults['products_limit_30']['response']->json();
        $this->assertCount(30, $p30Data['products']);
        $this->assertTrue($p30Data['has_more']);
        $this->assertNotNull($p30Data['next_cursor']);
        $this->assertSame($bootstrapData['catalog_snapshot_version'], $p30Data['catalog_snapshot_version']);

        // Nonmatching search integrity
        $searchData = $coldResults['nonmatching_search']['response']->json();
        $this->assertCount(0, $searchData['products']);
        $this->assertFalse($searchData['has_more']);
        $this->assertNull($searchData['next_cursor']);
        $this->assertSame($bootstrapData['catalog_snapshot_version'], $searchData['catalog_snapshot_version']);

        // Verify total DB query counts are within the bounded O(n) expectation (174 - 186 queries)
        $this->assertGreaterThanOrEqual(170, $coldResults['nonmatching_search']['queries']);
        $this->assertLessThanOrEqual(195, $coldResults['bootstrap']['queries']);
    }

    private function seed10kProducts(): float
    {
        fwrite(STDOUT, "Seeding 10,000 products with units, barcodes, balances...\n");
        $start = hrtime(true);

        $now = now()->toDateTimeString();
        $batchSize = 2000;
        $total = 10000;

        $productsData = [];
        $unitsData = [];
        $barcodesData = [];
        $balancesData = [];

        for ($i = 1; $i <= $total; $i++) {
            $publicId = '01H'.str_pad((string) $i, 23, '0', STR_PAD_LEFT);
            $sku = 'SKU-BENCH-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT);

            $productsData[] = [
                'id' => $i,
                'public_id' => $publicId,
                'company_id' => $this->company->id,
                'sku' => $sku,
                'name_ar' => 'منتج تجريبي رقم '.$i,
                'name_en' => 'Benchmark Product '.$i,
                'product_type' => 'stock',
                'base_unit_id' => $this->unitPiece->id,
                'track_stock' => 1,
                'default_sale_price_base' => '25.000000',
                'active' => 1,
                'created_by' => $this->user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $unitsData[] = [
                'company_id' => $this->company->id,
                'product_id' => $i,
                'unit_id' => $this->unitPiece->id,
                'conversion_to_base' => '1.000000',
                'is_base' => 1,
                'is_default_sale' => 1,
                'default_sale_price_base' => '25.000000',
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $barcodesData[] = [
                'company_id' => $this->company->id,
                'product_id' => $i,
                'unit_id' => $this->unitPiece->id,
                'barcode' => '777'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
                'type' => 'code128',
                'is_primary' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $balancesData[] = [
                'company_id' => $this->company->id,
                'product_id' => $i,
                'warehouse_id' => $this->warehouse->id,
                'quantity_base' => '100.000000',
                'updated_at' => $now,
            ];

            if ($i % $batchSize === 0) {
                DB::table('products')->insert($productsData);
                DB::table('product_units')->insert($unitsData);
                DB::table('product_barcodes')->insert($barcodesData);
                DB::table('inventory_balances')->insert($balancesData);

                $productsData = [];
                $unitsData = [];
                $barcodesData = [];
                $balancesData = [];
            }
        }

        DB::statement('ANALYZE TABLE products');
        DB::statement('ANALYZE TABLE product_units');
        DB::statement('ANALYZE TABLE product_barcodes');
        DB::statement('ANALYZE TABLE inventory_balances');

        $durationSec = (hrtime(true) - $start) / 1e9;
        fwrite(STDOUT, sprintf("10,000 products seeded in %.2fs\n", $durationSec));

        return $durationSec;
    }

    /**
     * @return array{status: int, latency_ms: float, queries: int, peak_memory_kb: float, memory_delta_kb: float, response: TestResponse}
     */
    private function measureRequest(string $uri): array
    {
        gc_collect_cycles();
        $memBefore = memory_get_usage();
        $peakBefore = memory_get_peak_usage();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $start = hrtime(true);
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson($uri);
        $latencyMs = (hrtime(true) - $start) / 1e6;

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $memAfter = memory_get_usage();
        $peakAfter = memory_get_peak_usage();

        $memDeltaKb = max(0.0, ($memAfter - $memBefore) / 1024);
        $peakDeltaKb = max(0.0, ($peakAfter - $peakBefore) / 1024);

        return [
            'status' => $response->getStatusCode(),
            'latency_ms' => round($latencyMs, 2),
            'queries' => $queries,
            'peak_memory_kb' => round($peakDeltaKb, 2),
            'memory_delta_kb' => round($memDeltaKb, 2),
            'response' => $response,
        ];
    }
}
