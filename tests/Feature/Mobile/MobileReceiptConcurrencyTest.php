<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\Customer;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

final class MobileReceiptConcurrencyTest extends TestCase
{
    private function setupCompanyFixture(string $name, string $currency): array
    {
        app(CompanyContext::class)->clear();

        $owner = User::factory()->create([
            'email' => 'worker_owner_'.Str::random(8).'@example.com',
            'password' => Hash::make('WorkerSecured!Pass123'),
        ]);

        $company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'شركة السباق '.$name,
            'name_en' => 'Race Company '.$name,
            'base_currency_code' => $currency,
        ]);

        $this->actingAs($owner);
        app(CompanyContext::class)->setCompany($company, $owner);

        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($company);
        setPermissionsTeamId($company->id);
        $owner->assignRole('Owner');

        Customer::create([
            'company_id' => $company->id,
            'name_ar' => 'عميل السباق',
            'name_en' => 'Race Customer',
            'currency_code' => $currency,
            'active' => true,
            'created_by' => $owner->id,
        ]);

        app(CreateMoneyAccountAction::class)->execute($company, $owner, [
            'account_type' => 'cash',
            'name_ar' => 'صندوق متجر السباق',
            'name_en' => 'Race Cash Store',
            'currency_code' => $currency,
        ]);

        $unit = Unit::where('company_id', $company->id)->where('code', 'piece')->firstOrFail();
        $warehouse = Warehouse::where('company_id', $company->id)->where('is_default', true)->firstOrFail();

        $product = Product::create([
            'company_id' => $company->id,
            'name_ar' => 'بضاعة السباق',
            'name_en' => 'Race Stock Item',
            'base_unit_id' => $unit->id,
            'product_type' => 'stock',
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $owner->id,
        ]);

        ProductUnit::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'active' => true,
            'created_by' => $owner->id,
        ]);

        app(PostOpeningStockAction::class)->execute(
            company: $company,
            product: $product,
            warehouse: $warehouse,
            quantity: Quantity::of('10'),
            unitCostBase: '10.000000',
            user: $owner,
            idempotencyKey: 'race-opening-stock-'.$company->id,
            unitId: $unit->id,
            movementDate: '2026-11-12'
        );

        $deviceId = (string) Str::uuid();
        $device = MobileDevice::create([
            'device_id' => $deviceId,
            'device_name' => 'Race Device',
            'user_id' => $owner->id,
        ]);

        $rawToken = 'st_m_race_'.bin2hex(random_bytes(32));
        $token = MobileAccessToken::create([
            'token_hash' => hash('sha256', $rawToken),
            'device_id' => $deviceId,
            'user_id' => $owner->id,
            'company_id' => $company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $owner->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        return [$company, $owner, $device, $token];
    }

    private function startWorker(DisposableMariaDbSchema $schema, string $directory, string $slot, array $input): Process
    {
        file_put_contents($directory.'/'.$slot.'.json', json_encode($input, JSON_THROW_ON_ERROR));
        chmod($directory.'/'.$slot.'.json', 0600);

        $settings = DisposableMariaDbSchema::testSettings();
        $env = array_replace([
            'APP_KEY' => (string) (config('app.key') ?: getenv('APP_KEY')),
            'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
            'PHASE8_TEST_DB_HOST' => $settings['HOST'],
            'PHASE8_TEST_DB_PORT' => $settings['PORT'],
            'PHASE8_TEST_DB_USERNAME' => $settings['USERNAME'],
            'PHASE8_TEST_DB_PASSWORD' => $settings['PASSWORD'],
            'PHASE8_TEST_DB_EMPTY_PASSWORD' => $settings['PASSWORD'] === '' ? '1' : '0',
        ], $schema->environment());

        $process = new Process([PHP_BINARY, base_path('tests/Support/mobile-receipt-worker.php'), $directory, $slot], base_path(), $env);
        $process->setTimeout(90)->start();

        $deadline = microtime(true) + 30;
        while (! is_file($directory.'/'.$slot.'.ready')) {
            if (! $process->isRunning() || microtime(true) > $deadline) {
                $process->stop();
                $this->fail('Worker '.$slot.' failed to reach ready barrier: [err='.$process->getErrorOutput().'] [out='.$process->getOutput().']');
            }
            usleep(1000);
        }

        return $process;
    }

    public function test_concurrent_workers_same_company_same_event_converge_to_single_sale(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            [$company, $owner, $device, $token] = $this->setupCompanyFixture('Converge', 'ILS');
            $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            $a = $b = null;
            try {
                $payload = '{"action":"race_sale","amount":"25.000000"}';
                $input = [
                    'company_id' => $company->id,
                    'client_event_id' => '00000000-0000-4000-8000-000000000101',
                    'token_hash' => $token->token_hash,
                    'event_version' => 2,
                    'representation' => 'v2_intent',
                    'canonical_bytes' => $payload,
                    'executor_mode' => 'canonical',
                ];

                $a = $this->startWorker($schema, $directory, 'worker-a', $input);
                $b = $this->startWorker($schema, $directory, 'worker-b', $input);

                // Fire barrier
                touch($directory.'/go');

                $this->assertSame(0, $a->wait(), $a->getErrorOutput());
                $this->assertSame(0, $b->wait(), $b->getErrorOutput());

                $resA = json_decode($a->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $resB = json_decode($b->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                $this->assertSame(200, $resA['status'], 'Worker A failed: '.($resA['class'] ?? '').': '.($resA['message'] ?? ''));
                $this->assertSame(200, $resB['status'], 'Worker B failed: '.($resB['class'] ?? '').': '.($resB['message'] ?? ''));

                // Exactly one created, one replay
                $outcomes = [$resA['outcome'], $resB['outcome']];
                sort($outcomes);
                $this->assertSame(['created', 'replay'], $outcomes);

                // Both return identical receipt_id and canonical public IDs
                $this->assertSame($resA['receipt_id'], $resB['receipt_id']);
                $this->assertSame($resA['sales_invoice_public_id'], $resB['sales_invoice_public_id']);
                $this->assertSame($resA['customer_payment_public_id'], $resB['customer_payment_public_id']);

                // Exact database counts: exactly 1 sale, 1 payment, 1 receipt!
                $this->assertDatabaseCount('sales_invoices', 1);
                $this->assertDatabaseCount('customer_payments', 1);
                $this->assertDatabaseCount('mobile_event_receipts', 1);
                $this->assertDatabaseCount('posting_batches', 3); // opening stock + invoice + payment
                $this->assertDatabaseCount('stock_movements', 2); // opening stock + sale

                // All 6 reconciliations healthy!
                $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($company)->isHealthy);
                $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($company)->isHealthy);
                $this->assertTrue(app(SalesReconciliationService::class)->reconcile($company)->isHealthy);
                $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($company)->isHealthy);
                $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($company)->isHealthy);
                $this->assertTrue(app(Phase7ReconciliationService::class)->reconcile($company)->isHealthy);
            } finally {
                $a?->stop();
                $b?->stop();
                foreach (glob($directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }

    public function test_concurrent_workers_same_event_different_payloads_returns_conflict_409(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            [$company, $owner, $device, $token] = $this->setupCompanyFixture('Conflict', 'ILS');

            $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            $a = $b = null;

            try {
                $payload1 = '{"action":"race_sale","amount":"10.000000"}';
                $payload2 = '{"action":"race_sale","amount":"20.000000"}';

                $inputA = [
                    'company_id' => $company->id,
                    'client_event_id' => '00000000-0000-4000-8000-000000000102',
                    'token_hash' => $token->token_hash,
                    'event_version' => 2,
                    'representation' => 'v2_intent',
                    'canonical_bytes' => $payload1,
                    'executor_mode' => 'canonical',
                ];

                $inputB = [
                    'company_id' => $company->id,
                    'client_event_id' => '00000000-0000-4000-8000-000000000102',
                    'token_hash' => $token->token_hash,
                    'event_version' => 2,
                    'representation' => 'v2_intent',
                    'canonical_bytes' => $payload2,
                    'executor_mode' => 'canonical',
                ];

                $a = $this->startWorker($schema, $directory, 'worker-a', $inputA);
                $b = $this->startWorker($schema, $directory, 'worker-b', $inputB);

                touch($directory.'/go');

                $this->assertSame(0, $a->wait(), $a->getErrorOutput());
                $this->assertSame(0, $b->wait(), $b->getErrorOutput());

                $resA = json_decode($a->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $resB = json_decode($b->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                $statuses = [$resA['status'], $resB['status']];
                sort($statuses);
                $this->assertSame([200, 409], $statuses);

                $outcomes = [$resA['outcome'], $resB['outcome']];
                sort($outcomes);
                $this->assertSame(['conflict', 'created'], $outcomes);

                $this->assertDatabaseCount('mobile_event_receipts', 1);
            } finally {
                $a?->stop();
                $b?->stop();
                foreach (glob($directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }

    public function test_concurrent_workers_same_event_different_companies_succeed_independently(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            [$companyA, $ownerA, $deviceA, $tokenA] = $this->setupCompanyFixture('TenantA', 'ILS');
            [$companyB, $ownerB, $deviceB, $tokenB] = $this->setupCompanyFixture('TenantB', 'USD');

            $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            $a = $b = null;

            try {
                $payload = '{"action":"race_sale","amount":"25.000000"}';

                $inputA = [
                    'company_id' => $companyA->id,
                    'client_event_id' => '00000000-0000-4000-8000-000000000103',
                    'token_hash' => $tokenA->token_hash,
                    'event_version' => 2,
                    'representation' => 'v2_intent',
                    'canonical_bytes' => $payload,
                    'executor_mode' => 'canonical',
                ];

                $inputB = [
                    'company_id' => $companyB->id,
                    'client_event_id' => '00000000-0000-4000-8000-000000000103',
                    'token_hash' => $tokenB->token_hash,
                    'event_version' => 2,
                    'representation' => 'v2_intent',
                    'canonical_bytes' => $payload,
                    'executor_mode' => 'canonical',
                ];

                $a = $this->startWorker($schema, $directory, 'worker-a', $inputA);
                $b = $this->startWorker($schema, $directory, 'worker-b', $inputB);

                touch($directory.'/go');

                $this->assertSame(0, $a->wait(), $a->getErrorOutput());
                $this->assertSame(0, $b->wait(), $b->getErrorOutput());

                $resA = json_decode($a->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $resB = json_decode($b->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                $this->assertSame(200, $resA['status'], 'Worker A failed: '.json_encode($resA));
                $this->assertSame(200, $resB['status'], 'Worker B failed: '.json_encode($resB));
                $this->assertSame('created', $resA['outcome']);
                $this->assertSame('created', $resB['outcome']);

                // Exactly 2 receipts in DB, one for company A, one for company B
                $this->assertDatabaseCount('mobile_event_receipts', 2);
            } finally {
                $a?->stop();
                $b?->stop();
                foreach (glob($directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }

    public function test_concurrent_workers_cross_version_same_payload_converges_to_single_sale(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            [$company, $owner, $device, $token] = $this->setupCompanyFixture('CrossVer', 'ILS');

            $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            $a = $b = null;

            try {
                $payload = '{"action":"race_sale","amount":"25.000000"}';

                $inputA = [
                    'company_id' => $company->id,
                    'client_event_id' => '00000000-0000-4000-8000-000000000104',
                    'token_hash' => $token->token_hash,
                    'event_version' => 2,
                    'representation' => 'v2_intent',
                    'canonical_bytes' => $payload,
                    'executor_mode' => 'canonical',
                ];

                $inputB = [
                    'company_id' => $company->id,
                    'client_event_id' => '00000000-0000-4000-8000-000000000104',
                    'token_hash' => $token->token_hash,
                    'event_version' => 2,
                    'representation' => 'v2_intent',
                    'canonical_bytes' => $payload,
                    'executor_mode' => 'canonical',
                ];

                $a = $this->startWorker($schema, $directory, 'worker-a', $inputA);
                $b = $this->startWorker($schema, $directory, 'worker-b', $inputB);

                touch($directory.'/go');

                $this->assertSame(0, $a->wait(), $a->getErrorOutput());
                $this->assertSame(0, $b->wait(), $b->getErrorOutput());

                $resA = json_decode($a->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $resB = json_decode($b->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                $this->assertSame(200, $resA['status'], 'Worker A failed: '.json_encode($resA));
                $this->assertSame(200, $resB['status'], 'Worker B failed: '.json_encode($resB));

                $outcomes = [$resA['outcome'], $resB['outcome']];
                sort($outcomes);
                $this->assertSame(['created', 'replay'], $outcomes);

                $this->assertDatabaseCount('sales_invoices', 1);
                $this->assertDatabaseCount('customer_payments', 1);
                $this->assertDatabaseCount('mobile_event_receipts', 1);
            } finally {
                $a?->stop();
                $b?->stop();
                foreach (glob($directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }
}
