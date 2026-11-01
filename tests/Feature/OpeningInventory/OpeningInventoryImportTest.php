<?php

declare(strict_types=1);

namespace Tests\Feature\OpeningInventory;

use App\Actions\Company\CreateCompanyAction;
use App\Models\Company;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\FefoAllocationService;
use App\Services\Inventory\OpeningInventoryImport;
use App\Services\Inventory\OpeningInventoryWorkbook;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Posting\AccountingPostingService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\OpeningInventoryWorkbookFixture;
use Tests\TestCase;

final class OpeningInventoryImportTest extends TestCase
{
    private DisposableMariaDbSchema $schema;

    private Company $company;

    private User $owner;

    private string $workbook;

    /** @var array<string, mixed> */
    private array $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = DisposableMariaDbSchema::create();
        $this->schema->migrate();
        $this->schema->switchLaravelConnection();
        $this->owner = User::factory()->create(['name' => 'Synthetic Operator']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة اصطناعية', 'base_currency_code' => 'ILS']);
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->workbook = OpeningInventoryWorkbookFixture::create();
        $source = app(OpeningInventoryWorkbook::class)->read($this->workbook, hash_file('sha256', $this->workbook));
        $unit = Unit::where('code', 'piece')->firstOrFail();
        $warehouse = Warehouse::firstOrFail();
        $this->plan = ['version' => 1, 'source_sha256' => $source['source_sha256'], 'rows_sha256' => $source['rows_sha256'],
            'company_public_id' => $this->company->public_id, 'actor_id' => $this->owner->id, 'owner_id' => $this->owner->id, 'owner_name' => $this->owner->name,
            'source_provider' => 'Synthetic Stock Counter', 'baseline_sha256' => app(OpeningInventoryImport::class)->baseline($this->company), 'currency' => 'ILS',
            'stock_count_date' => '2026-10-20', 'movement_date' => '2026-10-20', 'owned_stock_confirmed' => true, 'complete_count_confirmed' => true,
            'not_previously_posted_confirmed' => true, 'no_payable_confirmed' => true, 'prices_per_base_unit_confirmed' => true, 'code_kind' => 'barcode', 'uncoded_sku_policy' => 'leave_null',
            'identity_resolution' => 'Same serum code is one product with two separate expiry lots. Vial is a distinct product.', 'rows' => []];
        foreach ($source['rows'] as $row) {
            $this->plan['rows'][] = ['source_row' => $row['source_row'], 'exclude' => false, 'exclusion_reason' => null, 'zero_cost_confirmed' => false, 'unknown_expiry_confirmed' => false,
                'product_key' => $row['source_row'] === 4 ? 'vial' : 'serum', 'existing_product_public_id' => null,
                'unit_public_id' => $unit->public_id, 'warehouse_public_id' => $warehouse->public_id, 'expiry_date' => $row['expiry_date']];
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->workbook) && is_file($this->workbook)) {
            unlink($this->workbook);
        }
        if (isset($this->schema)) {
            $this->schema->drop();
        }
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function runImport(bool $apply = true): array
    {
        $service = app(OpeningInventoryImport::class);

        return $service->run($this->workbook, hash_file('sha256', $this->workbook), $this->plan, $service->hash($this->plan), $this->company, $this->owner, $apply);
    }

    public function test_dry_run_is_database_enforced_read_only_and_preserves_catalog_and_audit(): void
    {
        $before = app(OpeningInventoryImport::class)->baseline($this->company);
        $auditCount = DB::table('audit_events')->count();
        $report = $this->runImport(false);
        $this->assertSame('DRY_RUN', $report['status']);
        $this->assertSame('6.000000', $report['result']['quantity']);
        $this->assertSame('57.000000', $report['result']['value_base']);
        $this->assertSame($before, app(OpeningInventoryImport::class)->baseline($this->company));
        $this->assertSame($auditCount, DB::table('audit_events')->count());
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_committed_import_has_two_products_three_expiry_lots_and_balanced_canonical_equity(): void
    {
        $result = $this->runImport();
        $this->assertSame('APPLIED', $result['status']);
        $this->assertSame('57.000000', $result['result']['value_base']);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('product_units', 2);
        $this->assertDatabaseCount('product_barcodes', 1);
        $this->assertDatabaseCount('inventory_lots', 3);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseCount('posting_batches', 3);
        $this->assertDatabaseCount('posting_lines', 6);
        $this->assertSame(0, Product::whereNotNull('name_ar')->count());
        $this->assertSame(0, Product::whereNotNull('sku')->count());
        $this->assertSame(0, DB::table('inventory_lots')->whereNotNull('lot_number')->count());
        $this->assertSame('00123456789012', DB::table('product_barcodes')->value('barcode'));
        $this->assertDatabaseHas('product_barcodes', ['barcode' => '00123456789012', 'is_primary' => true]);
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('sales_invoices', 0);
        $this->assertDatabaseCount('vendor_payments', 0);
        $this->assertSame('6.000000', DB::table('inventory_cost_states')->selectRaw('CAST(SUM(quantity_base) AS CHAR) AS qty')->value('qty'));
        $this->assertSame('57.000000', DB::table('inventory_cost_states')->selectRaw('CAST(SUM(inventory_value_base) AS CHAR) AS value')->value('value'));
        $inventory = DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'inventory')->value('id');
        $equity = DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', 'opening_balance_equity')->value('id');
        $this->assertSame('57.000000', DB::table('posting_lines')->where('ledger_account_id', $inventory)->selectRaw('CAST(SUM(debit_base) AS CHAR) AS val')->value('val'));
        $this->assertSame('57.000000', DB::table('posting_lines')->where('ledger_account_id', $equity)->selectRaw('CAST(SUM(credit_base) AS CHAR) AS val')->value('val'));
        $retry = $this->runImport();
        $this->assertSame('ALREADY_APPLIED', $retry['status']);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseCount('products', 2);
    }

    public function test_changed_plan_replay_conflicts_without_more_economic_effects(): void
    {
        $this->runImport();
        $this->plan['movement_date'] = '2026-10-21';
        try {
            $this->runImport();
            $this->fail('Changed intent replayed.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('different plan', $e->getMessage());
        }
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('stock_movements', 3);
    }

    public function test_gl_failure_rolls_back_products_barcodes_stock_lots_and_audit(): void
    {
        $before = app(OpeningInventoryImport::class)->baseline($this->company);
        $this->mock(AccountingPostingService::class, function ($mock): void {
            $mock->shouldReceive('post')->once()->andThrow(new RuntimeException('Synthetic GL failure'));
        });
        try {
            $this->runImport();
            $this->fail('Partial stock committed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic GL failure', $e->getMessage());
        }
        $this->assertSame($before, app(OpeningInventoryImport::class)->baseline($this->company));
        foreach (['products', 'product_barcodes', 'product_units', 'inventory_lots', 'stock_movements', 'posting_batches', 'posting_lines'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_unresolved_expiry_or_mapping_cannot_begin_creating_products(): void
    {
        $this->plan['rows'][2]['expiry_date'] = null;
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->runImport();
        } finally {
            $this->assertDatabaseCount('products', 0);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_catalog_drift_is_refused_without_overwriting_it(): void
    {
        app(ProductCatalogService::class)->createProduct($this->company,
            ['name_en' => 'Existing unrelated product', 'base_unit_id' => Unit::where('code', 'piece')->value('id'), 'product_type' => 'stock', 'track_stock' => true, 'track_expiry' => false, 'active' => true], (int) $this->owner->id);
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->runImport();
        } finally {
            $this->assertDatabaseCount('products', 1);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_revoked_membership_is_checked_before_exact_retry(): void
    {
        $this->runImport();
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
        $this->expectException(AuthorizationException::class);
        try {
            $this->runImport();
        } finally {
            $this->assertDatabaseCount('stock_movements', 3);
        }
    }

    #[DataProvider('invalidChoices')]
    public function test_invalid_plan_is_rejected_without_any_product_or_economic_write(string $field, mixed $value): void
    {
        if (str_starts_with($field, 'row.')) {
            $this->plan['rows'][0][substr($field, 4)] = $value;
        } else {
            $this->plan[$field] = $value;
        }
        try {
            $this->runImport();
            $this->fail('Invalid plan was admitted.');
        } catch (\Throwable $e) {
            $this->assertTrue($e instanceof InvalidArgumentException || $e instanceof ValidationException || $e instanceof ModelNotFoundException, get_class($e).': '.$e->getMessage());
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('posting_batches', 0);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidChoices(): array
    {
        return [
            'currency' => ['currency', 'USD'], 'owner' => ['owner_name', 'Not the owner'],
            'future date' => ['movement_date', '2099-01-01'], 'unconfirmed ownership' => ['owned_stock_confirmed', false],
            'unconfirmed prices' => ['prices_per_base_unit_confirmed', false], 'unknown units' => ['row.unit_public_id', null],
            'wrong warehouse' => ['row.warehouse_public_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            'changed dated expiry' => ['row.expiry_date', '2029-01-01'], 'misaligned source row' => ['row.source_row', 99],
        ];
    }

    public function test_explicit_exclusion_preserves_row_trace_and_adjusts_totals(): void
    {
        $this->plan['rows'][2]['exclude'] = true;
        $this->plan['rows'][2]['exclusion_reason'] = 'Owner excludes synthetic vial pending expiry verification';
        $this->plan['rows'][2]['expiry_date'] = null;
        $result = $this->runImport();
        $this->assertSame('EXCLUDED', $result['result']['rows'][2]['action']);
        $this->assertSame('5.000000', $result['result']['quantity']);
        $this->assertSame('50.000000', $result['result']['value_base']);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_existing_product_reuse_never_overwrites_prices_and_lots_use_fefo(): void
    {
        $product = app(ProductCatalogService::class)->createProduct($this->company,
            ['name_en' => 'Synthetic serum', 'base_unit_id' => Unit::where('code', 'piece')->value('id'), 'product_type' => 'stock', 'track_stock' => true, 'track_expiry' => true, 'active' => true,
                'default_sale_price_base' => '99', 'default_purchase_cost_base' => '88'], (int) $this->owner->id);
        $this->plan['rows'][0]['existing_product_public_id'] = $product->public_id;
        $this->plan['rows'][1]['existing_product_public_id'] = $product->public_id;
        $this->plan['baseline_sha256'] = app(OpeningInventoryImport::class)->baseline($this->company);
        $this->runImport();
        $this->assertSame('99.000000', $product->fresh()->default_sale_price_base);
        $this->assertSame('88.000000', $product->fresh()->default_purchase_cost_base);
        $this->assertDatabaseCount('products', 2);
        $lots = app(FefoAllocationService::class)->allocate($product->fresh(), Warehouse::firstOrFail(), '4', true, '2026-11-01');
        $this->assertCount(2, $lots);
        $this->assertSame('2027-08-18', $lots[0]['lot']->expiry_date->toDateString());
        $this->assertSame('3.000000', (string) $lots[0]['quantity']);
        $this->assertSame('1.000000', (string) $lots[1]['quantity']);
    }

    public function test_sku_classification_does_not_manufacture_scannable_barcodes(): void
    {
        $this->plan['code_kind'] = 'sku';
        $this->runImport();
        $this->assertDatabaseCount('product_barcodes', 0);
        $this->assertSame('00123456789012', Product::where('name_en', 'Synthetic serum')->value('sku'));
        $this->assertNull(Product::where('name_en', 'Synthetic vial')->value('sku'));
    }

    public function test_subscription_read_only_refuses_the_entire_import(): void
    {
        config(['subscriptions.enforcement_enabled' => true]);
        DB::table('platform_subscriptions')->insert(['company_id' => $this->company->id, 'plan' => 'Synthetic', 'fee' => '10.000000', 'currency' => 'ILS', 'starts_on' => '2019-01-01',
            'renews_on' => '2020-01-01', 'grace_days' => 0, 'reminder_days' => '[7,3,1]', 'revision' => 1, 'enforcement_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(AuthorizationException::class);
        try {
            $this->runImport();
        } finally {
            $this->assertDatabaseCount('products', 0);
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_unknown_expiry_is_an_explicit_row_decision_with_tracking_retained(): void
    {
        $this->plan['rows'][2]['expiry_date'] = null;
        // Source date cannot be erased merely by confirming unknown expiry.
        $this->plan['rows'][2]['unknown_expiry_confirmed'] = true;
        $this->expectException(InvalidArgumentException::class);
        $this->runImport();
    }

    public function test_confirmed_unknown_source_expiry_posts_a_null_date_tracked_lot(): void
    {
        unlink($this->workbook);
        $this->workbook = OpeningInventoryWorkbookFixture::create(true);
        $source = app(OpeningInventoryWorkbook::class)->read($this->workbook, hash_file('sha256', $this->workbook));
        $this->plan['source_sha256'] = $source['source_sha256'];
        $this->plan['rows_sha256'] = $source['rows_sha256'];
        $this->plan['rows'][2]['expiry_date'] = null;
        $this->plan['rows'][2]['unknown_expiry_confirmed'] = true;
        $this->runImport();
        $vial = Product::where('name_en', 'Synthetic vial')->firstOrFail();
        $this->assertTrue($vial->track_expiry);
        $this->assertDatabaseHas('inventory_lots', ['product_id' => $vial->id, 'expiry_date' => null, 'lot_number' => null]);
        $this->assertDatabaseCount('stock_movements', 3);
    }

    public function test_foreign_company_context_and_warehouse_cannot_be_used_and_foreign_data_is_preserved(): void
    {
        $context = app(CompanyContext::class);
        $context->clear();
        $foreignOwner = User::factory()->create();
        $foreign = app(CreateCompanyAction::class)->execute($foreignOwner, ['name_ar' => 'شركة أخرى اصطناعية', 'base_currency_code' => 'ILS']);
        $foreignBaseline = app(OpeningInventoryImport::class)->baseline($foreign);
        $context->setCompany($foreign, $foreignOwner);
        try {
            $this->runImport();
            $this->fail('Foreign ambient context admitted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('stock_movements', 0);
        }
        $context->setCompany($this->company, $this->owner);
        $this->plan['rows'][0]['warehouse_public_id'] = DB::table('warehouses')->where('company_id', $foreign->id)->value('public_id');
        try {
            $this->runImport();
            $this->fail('Foreign warehouse admitted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('products', 0);
        }
        $this->assertSame($foreignBaseline, app(OpeningInventoryImport::class)->baseline($foreign));
    }

    public function test_revoked_cost_permission_and_suspended_company_refuse_before_creation(): void
    {
        $role = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
        $role->revokePermissionTo('inventory.cost.view');
        try {
            $this->runImport();
            $this->fail('Missing cost permission admitted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('products', 0);
        }
        $role->givePermissionTo('inventory.cost.view');
        DB::table('companies')->where('id', $this->company->id)->update(['status' => 'suspended']);
        $this->expectException(AuthorizationException::class);
        try {
            $this->runImport();
        } finally {
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    public function test_two_real_processes_converge_to_one_import_including_products_and_barcodes(): void
    {
        $directory = sys_get_temp_dir().'/opening_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $processes = [];
        try {
            file_put_contents($directory.'/input.json', json_encode(['company_id' => $this->company->id, 'actor_id' => $this->owner->id,
                'workbook' => $this->workbook, 'source_sha' => hash_file('sha256', $this->workbook), 'plan' => $this->plan], JSON_THROW_ON_ERROR));
            foreach (['worker-a', 'worker-b'] as $slot) {
                $process = new Process([PHP_BINARY, 'tests/Support/opening-inventory-worker.php', $directory, $slot], base_path(), $this->schema->environment());
                $process->setTimeout(90)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 45;
            while (! is_file($directory.'/worker-a.ready') || ! is_file($directory.'/worker-b.ready')) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Synthetic importer worker exited before barrier: '.$process->getErrorOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Synthetic importer workers did not reach barrier.');
                }
                usleep(1000);
            }
            touch($directory.'/go');
            $statuses = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $statuses[] = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR)['status'];
            }
            $this->assertEqualsCanonicalizing(['APPLIED', 'ALREADY_APPLIED'], $statuses);
            $this->assertDatabaseCount('products', 2);
            $this->assertDatabaseCount('product_barcodes', 1);
            $this->assertDatabaseCount('inventory_lots', 3);
            $this->assertDatabaseCount('stock_movements', 3);
            $this->assertDatabaseCount('posting_batches', 3);
            $this->assertSame(1, DB::table('audit_events')->where('event_key', 'inventory.opening_import.completed')->count());
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
