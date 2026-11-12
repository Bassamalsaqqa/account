<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Support\Mobile\CatalogSnapshot;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

/**
 * Proves that reading the catalog page and computing the catalog fingerprint
 * within one MariaDB consistent read (REPEATABLE READ) yields a coherent response
 * (strictly old or strictly new, never mixed) when concurrent independent processes write to the database.
 */
final class CatalogConsistencyTest extends TestCase
{
    private ?DisposableMariaDbSchema $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = DisposableMariaDbSchema::createFromSource(DB::connection()->getDatabaseName());
        $this->fixture->switchLaravelConnection();
    }

    protected function tearDown(): void
    {
        try {
            $this->fixture?->restoreLaravelConnection();
            $this->fixture?->drop();
        } finally {
            parent::tearDown();
        }
    }

    public function test_concurrent_writer_yields_coherent_response_not_mixed_within_consistent_read(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['status' => 'active', 'base_currency_code' => 'ILS']);
        $user->companies()->attach($company->id, ['status' => 'active']);

        $unit = CompanyScope::executeWithoutScope(fn () => Unit::create([
            'company_id' => $company->id,
            'code' => 'PCS',
            'name_ar' => 'حبة',
            'name_en' => 'Piece',
            'allows_fraction' => false,
            'decimal_places' => 0,
            'active' => true,
        ]));

        $product = CompanyScope::executeWithoutScope(function () use ($company, $unit, $user) {
            $p = Product::create([
                'company_id' => $company->id,
                'sku' => 'SKU-RACE-001',
                'name_ar' => 'منتج اختبار التزامن',
                'product_type' => Product::TYPE_STOCK,
                'base_unit_id' => $unit->id,
                'track_stock' => false,
                'default_sale_price_base' => '20.000000',
                'active' => true,
                'created_by' => $user->id,
            ]);

            ProductUnit::create([
                'company_id' => $company->id,
                'product_id' => $p->id,
                'unit_id' => $unit->id,
                'conversion_to_base' => '1.000000',
                'is_base' => true,
                'is_default_sale' => true,
                'default_sale_price_base' => '20.000000',
                'active' => true,
            ]);

            return $p;
        });

        // Baseline initial version
        $oldVersion = CatalogSnapshot::version($company);

        // Begin verified consistent read (Process A context)
        CatalogSnapshot::withConsistentRead(function () use ($company, $product, $oldVersion): void {
            // 1. Read product in consistent read
            $row = DB::table('products')->where('id', $product->id)->first();
            $this->assertSame('20.000000', (string) $row->default_sale_price_base);

            // 2. Spawn an independent PHP process (Process B) that updates the product and commits in MariaDB
            $marker = tempnam(sys_get_temp_dir(), 'cat-race-');
            unlink($marker);
            $dbName = DB::connection()->getDatabaseName();
            $worker = new Process([
                PHP_BINARY,
                base_path('tests/Support/Mobile/catalog-writer-worker.php'),
                $dbName,
                (string) $product->id,
                '99.000000',
                $marker,
            ], base_path(), $this->fixture->environment());
            $worker->setTimeout(30)->mustRun();
            $this->assertFileExists($marker);
            unlink($marker);

            // 3. Compute version within the SAME consistent read transaction
            // Under REPEATABLE READ, Process A MUST see its original snapshot (price 20.000000),
            // NOT the uncommitted or newly committed price 99.000000 from Process B!
            $inTransactionVersion = CatalogSnapshot::computeVersion($company);

            // Proves no mixed state: version strictly matches old snapshot!
            $this->assertSame($oldVersion, $inTransactionVersion,
                'Consistent read violated: CatalogSnapshot computed version saw data committed after snapshot initialization!'
            );
        });

        // 4. Outside the transaction, a fresh read now sees the new state and new version
        $freshRow = DB::table('products')->where('id', $product->id)->first();
        $this->assertSame('99.000000', (string) $freshRow->default_sale_price_base);
        $newVersion = CatalogSnapshot::version($company);
        $this->assertNotSame($oldVersion, $newVersion);
    }
}
