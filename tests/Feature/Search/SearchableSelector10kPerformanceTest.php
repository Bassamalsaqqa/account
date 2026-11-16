<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Actions\Company\CreateCompanyAction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Unit;
use App\Models\User;
use App\Services\Search\SearchableSelectorService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SearchableSelector10kPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;
    protected SearchableSelectorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);
        $this->company = $creator->execute($this->user, [
            'name_ar' => 'شركة فحص الأداء للمجموعات الضخمة',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $this->actingAs($this->user);
        $this->service = app(SearchableSelectorService::class);

        $this->ensure10kDatasetSeeded();
    }

    protected function getOrCreateUnit(
        string $code = 'piece',
        string $nameAr = 'قطعة',
        ?string $nameEn = 'Piece',
        ?string $symbolAr = 'قطعة',
        ?string $symbolEn = 'pc'
    ): Unit {
        return Unit::firstOrCreate(
            [
                'company_id' => $this->company->id,
                'code' => $code,
            ],
            [
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'symbol_ar' => $symbolAr,
                'symbol_en' => $symbolEn,
                'allows_fraction' => false,
                'decimal_places' => 0,
                'active' => true,
            ]
        );
    }

    protected function ensure10kDatasetSeeded(): void
    {
        $existingCount = Product::where('company_id', $this->company->id)->count();
        if ($existingCount >= 10000) {
            return;
        }

        $baseUnit = $this->getOrCreateUnit('piece');
        $now = now();

        // Seed 10,000 products in bounded chunks of 1,000 records using canonical fields
        for ($chunk = 0; $chunk < 10; $chunk++) {
            $productRows = [];
            for ($i = 1; $i <= 1000; $i++) {
                $idx = ($chunk * 1000) + $i;
                $productRows[] = [
                    'public_id' => (string) Str::ulid(),
                    'company_id' => $this->company->id,
                    'sku' => sprintf('SKU-10K-%05d', $idx),
                    'name_ar' => sprintf('منتج ضخم للاختبار %05d', $idx),
                    'name_en' => sprintf('Scale Test Product %05d', $idx),
                    'product_type' => Product::TYPE_STOCK,
                    'base_unit_id' => $baseUnit->id,
                    'active' => true,
                    'track_stock' => true,
                    'created_by' => $this->user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            Product::insert($productRows);
        }

        // Seed 2,000 customers in bounded chunks of 500 records using canonical fields
        for ($chunk = 0; $chunk < 4; $chunk++) {
            $customerRows = [];
            for ($i = 1; $i <= 500; $i++) {
                $idx = ($chunk * 500) + $i;
                $customerRows[] = [
                    'public_id' => (string) Str::ulid(),
                    'company_id' => $this->company->id,
                    'code' => sprintf('CUST-10K-%05d', $idx),
                    'name_ar' => sprintf('عميل ضخم للاختبار %05d', $idx),
                    'name_en' => sprintf('Scale Test Customer %05d', $idx),
                    'status' => 'active',
                    'active' => true,
                    'created_by' => $this->user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            Customer::insert($customerRows);
        }
    }

    public function test_query_count_is_strictly_bounded_and_constant(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        // Perform search across the 10,000 products dataset
        $response = $this->service->search($this->company, $this->user, 'product', 'ضخم', null, 25, ['context' => 'sales']);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // The search must execute bounded queries without N+1 relations:
        // 1. Fastpath barcode lookup on product_barcodes
        // 2. Main product search query with CASE/COALESCE ranking
        $this->assertLessThanOrEqual(2, count($queries), 'Search must not execute N+1 queries for relations.');
        $this->assertNotEmpty($response['items']);
        $this->assertLessThanOrEqual(25, count($response['items']));
    }

    public function test_result_payload_size_is_strictly_bounded(): void
    {
        // 10,000 products match the query 'ضخم'
        $response = $this->service->search($this->company, $this->user, 'product', 'ضخم', null, 25, ['context' => 'sales']);

        // Bounded result contract: limit is strictly 25 items regardless of 10,000 total matches
        $this->assertCount(25, $response['items'], 'Result count must be capped at 25 items regardless of total matches.');
        $this->assertTrue($response['has_more'], 'Search on 10k dataset must indicate more results available.');
        $this->assertNotNull($response['next_cursor'], 'Next cursor must be provided when more pages exist.');
    }

    public function test_customer_party_lookup_payload_is_bounded_and_deterministic(): void
    {
        // 2,000 customers match the query 'عميل ضخم'
        $response = $this->service->search($this->company, $this->user, 'customer', 'عميل ضخم', null, 25, ['context' => 'sales']);

        $this->assertCount(25, $response['items'], 'Customer result count must be capped at 25 items.');
        $this->assertTrue($response['has_more'], 'Customer search on 2k dataset must indicate more results available.');
        $this->assertNotNull($response['next_cursor']);

        // Verify deterministic sorting: order must be stable
        $labels = array_map(fn ($r) => $r['label'], $response['items']);
        $sortedLabels = $labels;
        sort($sortedLabels);
        $this->assertSame($sortedLabels, $labels, 'Customer search results must follow deterministic tie-breaker sorting.');
    }

    public function test_explain_query_plan_and_index_usage_documentation(): void
    {
        /**
         * Documented MariaDB Query Execution Plan and Index Tradeoffs on 10k Products:
         *
         * 1. Exact Barcode & SKU Lookup:
         *    - Exact barcode fastpath queries `product_barcodes.barcode` with tenant isolation.
         *    - Exact SKU matches resolve with index-backed lookup on `products.sku`.
         *
         * 2. Tenant Isolation Partitioning:
         *    - Tenant filter enforces `company_id` = ? AND `active` = 1, ensuring candidate evaluation
         *      is restricted to the active tenant partition.
         *
         * 3. Arbitrary Partial-Name Contains (LIKE '%term%') Tradeoff:
         *    - Leading wildcard contains search cannot traverse standard B-tree indexes via range scan.
         *    - Arbitrary partial-name accuracy is strictly preserved across languages.
         *    - CASE/COALESCE rank and deterministic tie-breaker sorting over the tenant candidate set
         *      requires MariaDB filesort; measured EXPLAIN plan captures actual key, access type,
         *      and rows examined without speculative short-circuit assertions.
         */
        $targetProduct = Product::where('company_id', $this->company->id)->first();
        if ($targetProduct !== null) {
            ProductBarcode::firstOrCreate(
                [
                    'company_id' => $this->company->id,
                    'barcode' => 'BARCODE-EXACT-10K',
                ],
                [
                    'product_id' => $targetProduct->id,
                    'unit_id' => null,
                    'type' => 'code128',
                    'is_primary' => true,
                ]
            );
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        // Measure cold execution latency on actual production service search across 10k records
        $startCold = microtime(true);
        $coldResponse = $this->service->search(
            $this->company,
            $this->user,
            'product',
            'اختبار',
            null,
            25,
            ['context' => 'sales']
        );
        $coldLatencyMs = (microtime(true) - $startCold) * 1000;

        // Measure warm execution latency on actual production service search across 10k records
        $startWarm = microtime(true);
        $warmResponse = $this->service->search(
            $this->company,
            $this->user,
            'product',
            'اختبار',
            null,
            25,
            ['context' => 'sales']
        );
        $warmLatencyMs = (microtime(true) - $startWarm) * 1000;

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $driver = DB::connection()->getDriverName();
        $plans = [];

        // Collect ALL service product query plans, distinguishing fastpath vs contains
        foreach ($queries as $entry) {
            $sql = $entry['query'];
            $sqlLower = strtolower($sql);
            $label = null;

            if (str_contains($sqlLower, 'product_barcodes')) {
                $label = 'fastpath_barcode';
            } elseif (str_contains($sqlLower, '`products`') || str_contains($sqlLower, ' products ')) {
                $label = 'contains_products';
            }

            if ($label !== null && !isset($plans[$label])) {
                $explainRows = [];
                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    $explainRows = DB::select("EXPLAIN {$sql}", $entry['bindings']);
                } elseif ($driver === 'sqlite') {
                    $explainRows = DB::select("EXPLAIN QUERY PLAN {$sql}", $entry['bindings']);
                }

                $plans[$label] = [
                    'query' => $sql,
                    'bindings' => $entry['bindings'],
                    'explain' => json_decode(json_encode($explainRows), true),
                ];
            }
        }

        $productCount = Product::where('company_id', $this->company->id)->count();
        $customerCount = Customer::where('company_id', $this->company->id)->count();

        // Actually record sanitized plans + latencies in test output
        $metrics = [
            'fixture_dataset' => [
                'products_count' => $productCount,
                'customers_count' => $customerCount,
            ],
            'latency_measurements_ms' => [
                'cold_search_ms' => round($coldLatencyMs, 3),
                'warm_search_ms' => round($warmLatencyMs, 3),
            ],
            'query_execution_plans' => $plans,
        ];

        fwrite(STDERR, "\n" . json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        // Assert plan presence and dataset bounds
        $this->assertArrayHasKey('contains_products', $plans, 'Query log must contain the main products contains query.');
        $this->assertNotEmpty($plans['contains_products']['explain'], 'Database EXPLAIN must return a valid execution plan for products query.');
        $this->assertGreaterThanOrEqual(10000, $productCount, 'Products dataset must contain at least 10,000 records for 10k qualification.');
        $this->assertGreaterThanOrEqual(2000, $customerCount, 'Customers dataset must contain thousands of records for 10k qualification.');

        $this->assertLessThanOrEqual(25, count($coldResponse['items']));
        $this->assertLessThanOrEqual(25, count($warmResponse['items']));
    }
}
