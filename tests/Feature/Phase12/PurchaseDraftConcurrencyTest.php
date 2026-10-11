<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Application\AI\PurchaseDraftCommands;
use App\Application\AI\PurchaseDraftPayload;
use App\Models\AiPurchaseDraftReceipt;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

/** Independent connections require committed fixtures, so intentionally no RefreshDatabase. */
class PurchaseDraftConcurrencyTest extends TestCase
{
    private ?DisposableMariaDbSchema $fixtureSchema = null;

    /** @var array<string,int> */
    private array $primaryCounts = [];

    private Company $company;

    private Company $controlCompany;

    private User $owner;

    private Vendor $vendor;

    private Product $product;

    private ProductUnit $carton;

    private string $controlFingerprint;

    protected function setUp(): void
    {
        parent::setUp();
        $primary = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($primary);
        foreach (['companies', 'users', 'purchases', 'ai_purchase_draft_receipts', 'posting_batches', 'stock_movements'] as $table) {
            if (Schema::hasTable($table)) {
                $this->primaryCounts[$table] = DB::table($table)->count();
            }
        }
        $this->fixtureSchema = DisposableMariaDbSchema::createFromSource($primary);
        $this->fixtureSchema->switchLaravelConnection();

        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create();
        $creator = app(CreateCompanyAction::class);

        // Control tenant: posted purchase establishes a meaningful fingerprint.
        $this->controlCompany = $creator->execute($this->owner, ['name_ar' => 'شركة ضبط السباق', 'name_en' => 'Purchase Race Control', 'base_currency_code' => 'ILS']);
        $this->activate($this->controlCompany);
        $controlVendor = app(VendorCatalogService::class)->save($this->controlCompany, $this->owner, ['name_ar' => 'مورد الضبط', 'name_en' => 'Control Vendor']);
        $controlUnit = Unit::where('company_id', $this->controlCompany->id)->where('code', 'piece')->firstOrFail();
        $controlProduct = app(ProductCatalogService::class)->createProduct($this->controlCompany, [
            'name_ar' => 'منتج الضبط', 'name_en' => 'Control Product', 'sku' => 'CTRL-PROD',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => false,
            'base_unit_id' => $controlUnit->id, 'default_purchase_cost_base' => '1.000000',
        ], $this->owner->id);
        $controlProductUnit = ProductUnit::where('company_id', $this->controlCompany->id)
            ->where('product_id', $controlProduct->id)->firstOrFail();
        $controlDraft = app(CreatePurchaseDraftAction::class)->execute($this->controlCompany, $this->owner, [
            'vendor_id' => $controlVendor->id,
            'warehouse_id' => Warehouse::where('company_id', $this->controlCompany->id)->firstOrFail()->id,
            'purchase_date' => '2026-10-01',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'document_locale' => 'ar',
            'lines' => [[
                'product_id' => $controlProduct->id,
                'product_unit_id' => $controlProductUnit->id,
                'quantity' => '1',
                'unit_cost' => '10',
                'lots' => [],
            ]],
        ]);
        app(PostPurchaseAction::class)->execute($controlDraft, $this->owner);
        $this->controlFingerprint = $this->fingerprint($this->controlCompany);

        // Main trading company.
        app(CompanyContext::class)->clear();
        $this->company = $creator->execute($this->owner, ['name_ar' => 'شركة السباق الرئيسية', 'name_en' => 'Purchase Race Main', 'base_currency_code' => 'ILS']);
        $this->activate($this->company);
        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, ['name_ar' => 'مورد تجريبي', 'name_en' => 'Race Vendor']);
        $pieceUnit = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();
        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صابون', 'name_en' => 'Soap', 'sku' => 'SOAP-RACE',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => true,
            'base_unit_id' => $pieceUnit->id, 'default_purchase_cost_base' => '2.000000',
        ], $this->owner->id);
        $cartonUnit = Unit::where('company_id', $this->company->id)->where('code', 'carton')->firstOrFail();
        $this->carton = app(ProductCatalogService::class)->addOrUpdateAlternateUnit($this->product, $cartonUnit->id, '12', false, true);
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        try {
            $this->fixtureSchema?->restoreLaravelConnection();
            $this->fixtureSchema?->drop();
            foreach ($this->primaryCounts as $table => $count) {
                if (Schema::hasTable($table)) {
                    $this->assertSame($count, DB::table($table)->count(), "Race fixtures must not leak into primary test schema [{$table}].");
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_concurrent_identical_create_yields_one_draft_and_one_receipt(): void
    {
        $formKey = (string) Str::uuid();
        $payload = $this->payload();
        $beforeEffects = $this->effects();

        $results = $this->race($formKey, $payload, $formKey, $payload);

        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame('ok', $results[1]['status']);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame('draft', $results[0]['status_name']);

        $this->assertSame(1, Purchase::where('company_id', $this->company->id)->count());
        $receipts = AiPurchaseDraftReceipt::where('company_id', $this->company->id)->get();
        $this->assertCount(1, $receipts);
        $receipt = $receipts->first();
        $this->assertNotNull($receipt);
        $this->assertSame($formKey, $receipt->form_key);
        $this->assertSame((int) $this->owner->id, (int) $receipt->user_id);
        $this->assertSame($results[0]['id'], (int) $receipt->purchase_id);
        $this->assertSame(app(PurchaseDraftPayload::class)->hash(app(PurchaseDraftPayload::class)->canonicalize($payload)), $receipt->payload_hash);

        $draft = Purchase::where('company_id', $this->company->id)->findOrFail($results[0]['id']);
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->posting_batch_id);
        $this->assertNull($draft->purchase_number);
        $this->assertWinningDraft($draft, $payload);

        $this->assertSame($beforeEffects, $this->effects());
        $this->assertHealthyAndControlUnchanged();
    }

    public function test_concurrent_conflicting_payload_same_form_key_yields_one_draft_one_conflict(): void
    {
        $formKey = (string) Str::uuid();
        $payloadA = $this->payload('24');
        $payloadB = $this->payload('30');
        $beforeEffects = $this->effects();

        $results = $this->race($formKey, $payloadA, $formKey, $payloadB);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses);

        $this->assertSame(1, Purchase::where('company_id', $this->company->id)->count());
        $receipts = AiPurchaseDraftReceipt::where('company_id', $this->company->id)->get();
        $this->assertCount(1, $receipts);

        $winnerIndex = $results[0]['status'] === 'ok' ? 0 : 1;
        $winnerId = $results[$winnerIndex]['id'];
        $this->assertSame((int) $winnerId, (int) $receipts->first()->purchase_id);
        $this->assertSame($formKey, $receipts->first()->form_key);
        $winningPayload = $winnerIndex === 0 ? $payloadA : $payloadB;
        $this->assertSame(409, $results[1 - $winnerIndex]['http_code']);
        $this->assertSame((int) $this->owner->id, (int) $receipts->first()->user_id);
        $this->assertSame(app(PurchaseDraftPayload::class)->hash(app(PurchaseDraftPayload::class)->canonicalize($winningPayload)), $receipts->first()->payload_hash);

        $draft = Purchase::where('company_id', $this->company->id)->findOrFail($winnerId);
        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->posting_batch_id);
        $this->assertNull($draft->purchase_number);
        $this->assertWinningDraft($draft, $winningPayload);

        $this->assertSame($beforeEffects, $this->effects());
        $this->assertHealthyAndControlUnchanged();
    }

    public function test_actor_role_downgrade_at_lock_boundary_refuses_both_attempts_without_writes(): void
    {
        $formKey = (string) Str::uuid();
        $payload = $this->payload();
        $beforeEffects = $this->effects();

        $viewerRole = Role::where('company_id', $this->company->id)->where('name', 'Viewer')->firstOrFail();
        $beforeRelease = function () use ($viewerRole): void {
            $this->owner->syncRoles($viewerRole);
        };

        $results = $this->race($formKey, $payload, $formKey, $payload, $this->owner->id, $this->owner->id, $beforeRelease);

        $this->assertSame(['status' => 'unauthorized'], $results[0]);
        $this->assertSame(['status' => 'unauthorized'], $results[1]);

        $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());
        $this->assertSame(0, AiPurchaseDraftReceipt::where('company_id', $this->company->id)->count());
        $this->assertSame($beforeEffects, $this->effects());
        $this->assertHealthyAndControlUnchanged();
    }

    public function test_revoked_membership_at_lock_boundary_refuses_both_attempts_without_writes(): void
    {
        $collaborator = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $collaborator->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->company->id);
        $collaborator->givePermissionTo('purchasing.purchase.create');
        $collaborator->givePermissionTo('purchasing.purchase.view');
        $collaborator->givePermissionTo('purchasing.cost.view');

        $formKey = (string) Str::uuid();
        $payload = $this->payload();
        $beforeEffects = $this->effects();

        $beforeRelease = function () use ($collaborator): void {
            CompanyUser::where('company_id', $this->company->id)
                ->where('user_id', $collaborator->id)
                ->update(['status' => 'inactive']);
        };

        $results = $this->race($formKey, $payload, $formKey, $payload, $collaborator->id, $collaborator->id, $beforeRelease);

        $this->assertSame(['status' => 'unauthorized'], $results[0]);
        $this->assertSame(['status' => 'unauthorized'], $results[1]);

        $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());
        $this->assertSame(0, AiPurchaseDraftReceipt::where('company_id', $this->company->id)->count());
        $this->assertSame($beforeEffects, $this->effects());
        $this->assertHealthyAndControlUnchanged();
    }

    public function test_foreign_company_entity_cannot_be_used_to_create_draft_and_control_tenant_is_unchanged(): void
    {
        // Build a foreign company with its own vendor/product/warehouse.
        app(CompanyContext::class)->clear();
        $foreignCompany = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة أجنبية', 'name_en' => 'Foreign Co', 'base_currency_code' => 'ILS']);
        $this->activate($foreignCompany);
        $foreignVendor = app(VendorCatalogService::class)->save($foreignCompany, $this->owner, ['name_ar' => 'مورد أجنبي', 'name_en' => 'Foreign Vendor']);
        $foreignPieceUnit = Unit::where('company_id', $foreignCompany->id)->where('code', 'piece')->firstOrFail();
        $foreignProduct = app(ProductCatalogService::class)->createProduct($foreignCompany, [
            'name_ar' => 'منتج أجنبي', 'name_en' => 'Foreign Product', 'sku' => 'FOREIGN-PROD',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => false, 'track_expiry' => false,
            'base_unit_id' => $foreignPieceUnit->id, 'default_purchase_cost_base' => '1.000000',
        ], $this->owner->id);
        $foreignProductUnit = ProductUnit::where('company_id', $foreignCompany->id)
            ->where('product_id', $foreignProduct->id)->firstOrFail();
        $foreignWarehouse = Warehouse::where('company_id', $foreignCompany->id)->firstOrFail();
        $foreignFingerprint = $this->fingerprint($foreignCompany);

        // Re-activate main company for the attempt.
        $this->activate($this->company);
        $beforeEffects = $this->effects();

        $base = $this->payload();
        $foreignProductPayload = $base;
        $foreignProductPayload['lines'][0]['product_id'] = $foreignProduct->id;
        $foreignProductPayload['lines'][0]['product_unit_id'] = $foreignProductUnit->id;
        $foreignUnitPayload = $base;
        $foreignUnitPayload['lines'][0]['product_unit_id'] = $foreignProductUnit->id;
        foreach ([
            'vendor' => [...$base, 'vendor_id' => $foreignVendor->id],
            'product' => $foreignProductPayload,
            'product_unit' => $foreignUnitPayload,
            'warehouse' => [...$base, 'warehouse_id' => $foreignWarehouse->id],
        ] as $entity => $payload) {
            try {
                app(PurchaseDraftCommands::class)->create($this->company, $this->owner, $payload, (string) Str::uuid());
                $this->fail('Foreign-company '.$entity.' must be rejected.');
            } catch (ModelNotFoundException|NotFoundHttpException) {
                $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());
                $this->assertSame(0, AiPurchaseDraftReceipt::where('company_id', $this->company->id)->count());
            }
        }

        $this->assertSame(0, Purchase::where('company_id', $this->company->id)->count());
        $this->assertSame(0, AiPurchaseDraftReceipt::where('company_id', $this->company->id)->count());
        $this->assertSame($beforeEffects, $this->effects());
        $this->assertSame($foreignFingerprint, $this->fingerprint($foreignCompany));
        $this->assertHealthyAndControlUnchanged();
    }

    private function assertWinningDraft(Purchase $draft, array $payload): void
    {
        $this->assertSame((int) $this->company->id, (int) $draft->company_id);
        $this->assertSame($payload['vendor_id'], (int) $draft->vendor_id);
        $this->assertSame($payload['warehouse_id'], (int) $draft->warehouse_id);
        $this->assertSame($payload['vendor_invoice_number'], $draft->vendor_invoice_number);
        $this->assertSame('ILS', $draft->currency_code);
        $lines = $draft->lines()->get();
        $this->assertCount(1, $lines);
        $line = $lines->firstOrFail();
        $this->assertSame($payload['lines'][0]['product_id'], (int) $line->product_id);
        $this->assertSame($payload['lines'][0]['product_unit_id'], (int) $line->product_unit_id);
        $this->assertSame((string) BigDecimal::of($payload['lines'][0]['quantity'])->toScale(6), $line->quantity);
        $this->assertSame((string) BigDecimal::of($payload['lines'][0]['unit_cost'])->toScale(6), $line->unit_cost);
        $this->assertSame((string) BigDecimal::of($payload['lines'][0]['quantity'])->multipliedBy('12')->toScale(6), $line->quantity_base);
        $this->assertSame((string) BigDecimal::of($payload['lines'][0]['quantity'])->multipliedBy($payload['lines'][0]['unit_cost'])->toScale(6), $draft->grand_total_currency);
        $this->assertSame(0, $line->lots()->count());
    }

    private function activate(Company $company, ?User $actor = null): void
    {
        $user = $actor ?? $this->owner;
        $this->actingAs($user);
        app(CompanyContext::class)->setCompany($company, $user);
        setPermissionsTeamId($company->id);
    }

    /** @return array<string,mixed> */
    private function payload(string $unitCost = '24', string $quantity = '2'): array
    {
        return [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => Warehouse::where('company_id', $this->company->id)->firstOrFail()->id,
            'vendor_invoice_number' => 'SYNTHETIC-RACE-001',
            'purchase_date' => '2026-10-11',
            'due_date' => null,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'document_locale' => 'ar',
            'notes' => null,
            'lines' => [[
                'product_id' => $this->product->id,
                'product_unit_id' => $this->carton->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'lots' => [],
            ]],
        ];
    }

    /** @return array<string,string> */
    private function effects(): array
    {
        $rows = [];
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'inventory_lots', 'inventory_balances', 'inventory_operations', 'inventory_lot_balances', 'inventory_cost_states', 'document_sequences'] as $table) {
            $rows[$table] = DB::table($table)->get()->toJson();
        }

        return $rows;
    }

    private function fingerprint(Company $company): string
    {
        $rows = [];
        foreach ([
            'purchases', 'purchase_lines', 'purchase_line_lots', 'posting_batches', 'posting_lines',
            'stock_movements', 'inventory_operations', 'inventory_lots', 'inventory_balances',
            'inventory_lot_balances', 'inventory_cost_states', 'ai_purchase_draft_receipts', 'audit_events',
        ] as $table) {
            if (Schema::hasTable($table)) {
                $rows[$table] = DB::table($table)->where('company_id', $company->id)->orderBy('id')->get()->all();
            }
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function assertHealthyAndControlUnchanged(): void
    {
        $this->activate($this->company);
        foreach (['accounting:reconcile', 'inventory:reconcile', 'money:reconcile'] as $command) {
            $code = Artisan::call($command, ['companyPublicId' => $this->company->public_id]);
            $this->assertSame(0, $code, $command.': '.Artisan::output());
        }
        $payablesReport = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($payablesReport->isHealthy, implode('; ', $payablesReport->violations));
        $this->assertSame([], $payablesReport->violations);
        $accountingReport = app(AccountingReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($accountingReport->isHealthy, implode('; ', $accountingReport->violations));
        $this->assertSame([], $accountingReport->violations);
        $this->assertSame($this->controlFingerprint, $this->fingerprint($this->controlCompany));
        $this->assertSame(0, DB::transactionLevel());
        app(CompanyContext::class)->clear();
        auth()->forgetUser();
        setPermissionsTeamId(null);
    }

    /**
     * @param  array<string,mixed>  $payloadA
     * @param  array<string,mixed>  $payloadB
     * @param  (?callable():void)  $beforeRelease
     * @return array{array<string,mixed>,array<string,mixed>}
     */
    private function race(
        string $formKeyA,
        array $payloadA,
        string $formKeyB,
        array $payloadB,
        int $actorIdA = 0,
        int $actorIdB = 0,
        ?callable $beforeRelease = null,
    ): array {
        if ($actorIdA === 0) {
            $actorIdA = $this->owner->id;
        }
        if ($actorIdB === 0) {
            $actorIdB = $this->owner->id;
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase12-purchase-draft-race-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create owned race directory.');
        }
        $release = $directory.DIRECTORY_SEPARATOR.'release';
        $processes = [];
        $files = [];
        $readyFiles = [];
        $attemptFiles = [];

        $appKey = (string) (config('app.key') ?: getenv('APP_KEY'));
        if ($appKey === '') {
            throw new \RuntimeException('A valid APP_KEY is required for the purchase draft race harness.');
        }

        $environment = [
            'APP_KEY' => $appKey,
            'DB_URL' => '',
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'COMMAND_API_ENABLED' => 'false',
        ];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) config('database.connections.mysql.'.$key);
        }
        foreach ([
            'PHASE8_ALLOW_DISPOSABLE_DB', 'PHASE8_TEST_DB_HOST', 'PHASE8_TEST_DB_PORT', 'PHASE8_TEST_DB_USERNAME',
            'PHASE8_TEST_DB_EMPTY_PASSWORD', 'PHASE8_DISPOSABLE_DB_PROOF', 'PHASE8_DISPOSABLE_DB_NONCE', 'APP_CONFIG_CACHE',
        ] as $key) {
            $environment[$key] = getenv($key);
        }
        $environment['PHASE8_TEST_DB_PASSWORD'] = getenv('PHASE8_TEST_DB_PASSWORD');
        $environment = array_replace($environment, $this->fixtureSchema->environment());

        $intents = [
            ['form_key' => $formKeyA, 'command_payload' => $payloadA, 'actor_id' => $actorIdA],
            ['form_key' => $formKeyB, 'command_payload' => $payloadB, 'actor_id' => $actorIdB],
        ];

        $locked = false;
        try {
            DB::beginTransaction();
            $locked = true;
            DB::table('companies')->where('id', $this->company->id)->lockForUpdate()->first();

            foreach ($intents as $index => $intent) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR.'payload-'.$index.'.json';
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $attempting = $directory.DIRECTORY_SEPARATOR.'attempting-'.$index;
                $files = array_merge($files, [$payloadPath, $ready, $attempting]);
                $readyFiles[] = $ready;
                $attemptFiles[] = $attempting;
                file_put_contents($payloadPath, json_encode([
                    'operation' => 'create',
                    'company_id' => $this->company->id,
                    'actor_id' => $intent['actor_id'],
                    'form_key' => $intent['form_key'],
                    'command_payload' => $intent['command_payload'],
                    'ready' => $ready,
                    'attempting' => $attempting,
                    'release' => $release,
                ], JSON_THROW_ON_ERROR));
                chmod($payloadPath, 0600);
                $process = new Process(
                    [PHP_BINARY, base_path('tests/Support/Phase12/purchase-draft-race-worker.php'), $payloadPath],
                    base_path(),
                    $environment,
                );
                $process->setTimeout(45)->start();
                $processes[] = $process;
            }

            $this->waitForFiles($readyFiles, $processes);
            file_put_contents($release, 'release');
            $this->waitForFiles($attemptFiles, $processes);
            usleep(200000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Both independent purchase draft attempts must overlap behind the company lock.');
                $this->assertSame('', $process->getOutput());
            }
            if ($beforeRelease !== null) {
                $beforeRelease();
            }
            DB::commit();
            $locked = false;

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return [$results[0], $results[1]];
        } finally {
            if ($locked) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (array_merge($files, [$release]) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * @param  list<string>  $files
     * @param  list<Process>  $processes
     */
    private function waitForFiles(array $files, array $processes): void
    {
        $deadline = microtime(true) + 20;
        do {
            if (count(array_filter($files, 'is_file')) === count($files)) {
                return;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('Purchase draft race child exited before the barrier: '.$process->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Purchase draft race barrier timed out.');
    }
}
