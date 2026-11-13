<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Http\Controllers\Api\Mobile\V1\AuthController;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\MobileAccessToken;
use App\Models\MobileAuthChallenge;
use App\Models\MobileDevice;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Mobile\Receipt\Exceptions\MobileEventAuthenticationException;
use App\Support\Mobile\Receipt\MobileEventReceiptManager;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\StrictBarrier;
use Tests\TestCase;

/**
 * Two-process MariaDB barrier tests verifying consistent global lock order
 * across AuthController::login, DeviceController::revoke, tenant membership/role
 * mutations, and MobileEventReceiptManager::recordOrReplay.
 *
 * All tests execute as independent OS processes against an isolated, freshly-created
 * owned disposable MariaDB database on 127.0.0.1:3342.
 */
final class MobileReceiptLockOrderQualificationTest extends TestCase
{
    public function test_real_fortify_update_waits_for_user_holder_then_invalidates_old_epoch(): void
    {
        DisposableMariaDbSchema::assertPrimarySchema((string) DB::selectOne('SELECT DATABASE() AS db')->db);
        $schema = DisposableMariaDbSchema::create();
        $worker = null;
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $this->assertSame($schema->schemaName(), DB::selectOne('SELECT DATABASE() AS db')->db);
            [$user, , $company, , , $token] = $this->setupMultiCompanyFixture('StrictPassword');
            DB::beginTransaction();
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $worker = $this->startWorker($schema, $directory, 'worker-a', [
                'mode' => 'password_change', 'user_id' => $user->id,
                'new_password' => 'FreshStrict!Password123',
            ]);
            touch($directory.'/go');
            // Same test account can inspect its own connection's server-side state.
            // UPDATE is visible while pending, unlike Laravel's after-query event.
            StrictBarrier::await(function () use ($schema, $directory): bool {
                foreach (DB::select('SHOW FULL PROCESSLIST') as $row) {
                    if (is_file($directory.'/worker-a.attempt')
                        && (string) $row->Id === file_get_contents($directory.'/worker-a.attempt')
                        && $row->db === $schema->schemaName()
                        && str_contains(strtolower((string) $row->Info), 'update `users`')
                        && str_contains(strtolower((string) $row->State), 'updat')) {
                        return true;
                    }
                }

                return false;
            }, fn (): bool => $worker->isRunning());
            $this->assertTrue($worker->isRunning(), 'Reset cannot complete behind held User row.');
            DB::commit(); // Never wait for contender commit while holding its row.
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('password_updated', $result['outcome']);
            $this->assertNotSame($token->password_epoch, $result['new_epoch']);
            $this->expectException(MobileEventAuthenticationException::class);
            app(MobileEventReceiptManager::class)->lookup($company->id, '00000000-0000-4000-8000-000000000999', $token->token_hash);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker !== null && $worker->isRunning()) {
                $worker->stop();
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
            $schema->drop();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['mobile.enabled' => true]);
    }

    /**
     * @return array{0: User, 1: string, 2: Company, 3: Company, 4: MobileDevice, 5: MobileAccessToken, 6: string}
     */
    private function setupMultiCompanyFixture(string $prefix, string $currencyA = 'ILS', string $currencyB = 'USD'): array
    {
        config(['mobile.enabled' => true]);
        app(CompanyContext::class)->clear();

        $password = 'LockSecured!Pass123';
        $user = User::factory()->create([
            'email' => 'worker_user_'.Str::random(8).'@example.com',
            'password' => Hash::make($password),
        ]);

        $companyA = app(CreateCompanyAction::class)->execute($user, [
            'name_ar' => 'شركة السباق أ '.$prefix,
            'name_en' => 'Race Company A '.$prefix,
            'base_currency_code' => $currencyA,
        ]);

        $companyB = app(CreateCompanyAction::class)->execute($user, [
            'name_ar' => 'شركة السباق ب '.$prefix,
            'name_en' => 'Race Company B '.$prefix,
            'base_currency_code' => $currencyB,
        ]);

        $roleService = app(CompanyRoleService::class);
        $roleService->ensurePermissionsExist();

        foreach ([$companyA, $companyB] as $company) {
            $this->actingAs($user);
            app(CompanyContext::class)->setCompany($company, $user);

            $roleService->seedCompanyRoles($company);
            setPermissionsTeamId($company->id);
            $user->assignRole('Owner');

            Customer::create([
                'company_id' => $company->id,
                'name_ar' => 'عميل السباق '.$company->id,
                'name_en' => 'Race Customer '.$company->id,
                'currency_code' => $company->base_currency_code,
                'active' => true,
                'created_by' => $user->id,
            ]);

            app(CreateMoneyAccountAction::class)->execute($company, $user, [
                'account_type' => 'cash',
                'name_ar' => 'صندوق متجر السباق '.$company->id,
                'name_en' => 'Race Cash Store '.$company->id,
                'currency_code' => $company->base_currency_code,
            ]);

            $unit = Unit::where('company_id', $company->id)->where('code', 'piece')->firstOrFail();
            $warehouse = Warehouse::where('company_id', $company->id)->where('is_default', true)->firstOrFail();

            $product = Product::create([
                'company_id' => $company->id,
                'name_ar' => 'بضاعة السباق '.$company->id,
                'name_en' => 'Race Stock Item '.$company->id,
                'base_unit_id' => $unit->id,
                'product_type' => 'stock',
                'track_stock' => true,
                'track_expiry' => false,
                'active' => true,
                'created_by' => $user->id,
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
                'created_by' => $user->id,
            ]);

            app(PostOpeningStockAction::class)->execute(
                company: $company,
                product: $product,
                warehouse: $warehouse,
                quantity: Quantity::of('10'),
                unitCostBase: '10.000000',
                user: $user,
                idempotencyKey: 'race-opening-stock-'.$company->id,
                unitId: $unit->id,
                movementDate: '2026-11-12'
            );
        }

        $deviceId = (string) Str::uuid();
        $device = MobileDevice::create([
            'device_id' => $deviceId,
            'device_name' => 'Race Multi-Company Device',
            'user_id' => $user->id,
        ]);

        $rawToken = 'st_m_race_'.bin2hex(random_bytes(32));
        $token = MobileAccessToken::create([
            'token_hash' => hash('sha256', $rawToken),
            'device_id' => $deviceId,
            'user_id' => $user->id,
            'company_id' => null, // Multi-company staff token
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $user->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        return [$user, $password, $companyA, $companyB, $device, $token, $rawToken];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function startWorker(DisposableMariaDbSchema $schema, string $directory, string $slot, array $input): Process
    {
        file_put_contents($directory.'/'.$slot.'.json', json_encode($input, JSON_THROW_ON_ERROR));
        chmod($directory.'/'.$slot.'.json', 0600);

        $settings = DisposableMariaDbSchema::testSettings();
        $env = array_replace([
            'APP_KEY' => (string) (config('app.key') ?: getenv('APP_KEY')),
            'MOBILE_API_ENABLED' => 'true',
            'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
            'PHASE8_TEST_DB_HOST' => $settings['HOST'],
            'PHASE8_TEST_DB_PORT' => $settings['PORT'],
            'PHASE8_TEST_DB_USERNAME' => $settings['USERNAME'],
            'PHASE8_TEST_DB_PASSWORD' => $settings['PASSWORD'],
            'PHASE8_TEST_DB_EMPTY_PASSWORD' => $settings['PASSWORD'] === '' ? '1' : '0',
        ], $schema->environment());

        $worktreeRoot = dirname(__DIR__, 3);
        $process = new Process([PHP_BINARY, $worktreeRoot.'/tests/Support/mobile-identity-worker.php', $directory, $slot], $worktreeRoot, $env);
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

    /**
     * Barrier Test 1: Real production login vs receipt intake with owned device.
     * Both workers acquire locks on User, Company, and Device concurrently.
     * Uses forced held-lock interleaving on MariaDB to test actual critical row contention.
     * Verifies clean serialization, zero deadlocks, and both operations succeed.
     */
    public function test_concurrent_workers_login_vs_receipt_intake_converge_without_deadlock(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $this->assertTrue($schema->isOwned());
            $this->assertMatchesRegularExpression('/^accounting_p8_tmp_[a-f0-9]{12}$/D', $schema->schemaName());
            $schema->migrate();
            $schema->switchLaravelConnection();
            $actualDb = DB::selectOne('SELECT DATABASE() AS db')->db;
            $this->assertSame($schema->schemaName(), $actualDb);

            [$user, $password, $companyA, $companyB, $device, $token, $rawToken] = $this->setupMultiCompanyFixture('LoginRace');

            // Pre-create valid challenge for worker A (login)
            $challengeId = (string) Str::uuid();
            $nonce = bin2hex(random_bytes(32));
            MobileAuthChallenge::create([
                'challenge_id' => $challengeId,
                'device_id' => $device->device_id,
                'nonce_hash' => hash('sha256', $nonce),
                'app_version' => '1.0.0',
                'platform' => 'android',
                'expires_at' => now()->addMinutes(5),
            ]);

            $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            $a = $b = null;

            try {
                // Worker A holds lock on users table for 150ms to force MariaDB lock wait
                $inputA = [
                    'mode' => 'login',
                    'email' => $user->email,
                    'password' => $password,
                    'device_id' => $device->device_id,
                    'device_name' => 'Race Multi-Company Device',
                    'challenge_id' => $challengeId,
                    'nonce' => $nonce,
                    'hold_lock_table' => 'users',
                    'hold_ms' => 150,
                ];

                $payload = '{"action":"race_sale","amount":"25.000000"}';
                // Worker B starts with a slight offset to establish real InnoDB lock wait on User row
                $inputB = [
                    'mode' => 'receipt',
                    'company_id' => $companyA->id,
                    'client_event_id' => '00000000-0000-4000-8000-000000000201',
                    'token_hash' => $token->token_hash,
                    'event_version' => 2,
                    'representation' => 'v2_intent',
                    'canonical_bytes' => $payload,
                    'executor_mode' => 'canonical',
                    'start_delay_ms' => 30,
                ];

                $a = $this->startWorker($schema, $directory, 'worker-a', $inputA);
                $b = $this->startWorker($schema, $directory, 'worker-b', $inputB);

                // Release barrier simultaneously
                touch($directory.'/go');

                $this->assertSame(0, $a->wait(), 'Worker A stderr: '.$a->getErrorOutput());
                $this->assertSame(0, $b->wait(), 'Worker B stderr: '.$b->getErrorOutput());

                $resA = json_decode($a->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $resB = json_decode($b->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                // Worker A (Login) must succeed with 200 and return a fresh token
                $this->assertSame(200, $resA['status'], 'Worker A (Login) failed: '.json_encode($resA));
                $this->assertSame('logged_in', $resA['outcome']);
                $this->assertNotEmpty($resA['token']);
                $this->assertGreaterThanOrEqual(2, $resA['companies_count']);

                // Worker B (Receipt intake) must succeed with 200 and create receipt
                $this->assertSame(200, $resB['status'], 'Worker B (Receipt) failed: '.json_encode($resB));
                $this->assertSame('created', $resB['outcome']);
                $this->assertNotEmpty($resB['receipt_id']);

                // Exactly 1 receipt in DB, exactly 1 invoice, exactly 1 customer payment
                $this->assertDatabaseCount('mobile_event_receipts', 1);
                $this->assertDatabaseCount('sales_invoices', 1);
                $this->assertDatabaseCount('customer_payments', 1);

                // Reconciliations pass cleanly on Company A
                app(CompanyContext::class)->setCompany($companyA, $user);
                $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($companyA)->isHealthy);
                $this->assertTrue(app(SalesReconciliationService::class)->reconcile($companyA)->isHealthy);
                $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($companyA)->isHealthy);
                $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($companyA)->isHealthy);
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

    /**
     * Barrier Test 2: Device revocation vs receipt intake.
     * Worker A revokes the device via HTTP Kernel; Worker B attempts receipt intake with a token on that device.
     * Uses forced held-lock interleaving on mobile_devices row.
     * Asserts:
     * - Revoke succeeds with 200.
     * - Receipt intake either succeeds with 200 (if receipt locks held first) OR cleanly fails with 401
     *   auth_error (if revoke committed first).
     * - Never partial commits, never duplicate numbers, zero deadlocks.
     */
    public function test_concurrent_workers_device_revoke_vs_receipt_intake_reconciles_safely(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $this->assertTrue($schema->isOwned());
            $this->assertMatchesRegularExpression('/^accounting_p8_tmp_[a-f0-9]{12}$/D', $schema->schemaName());
            $schema->migrate();
            $schema->switchLaravelConnection();
            $actualDb = DB::selectOne('SELECT DATABASE() AS db')->db;
            $this->assertSame($schema->schemaName(), $actualDb);

            [$user, $password, $companyA, $companyB, $device, $token, $rawToken] = $this->setupMultiCompanyFixture('RevokeRace');

            $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            $a = $b = null;

            try {
                // Worker A holds lock on mobile_devices row to establish deterministic interleaving
                $inputA = [
                    'mode' => 'revoke',
                    'device_id' => $device->device_id,
                    'raw_token' => $rawToken,
                    'reason' => 'Lost device reported during intake',
                    'hold_lock_table' => 'mobile_devices',
                    'hold_ms' => 150,
                ];

                $payload = '{"action":"race_sale","amount":"25.000000"}';
                $inputB = [
                    'mode' => 'receipt',
                    'company_id' => $companyA->id,
                    'client_event_id' => '00000000-0000-4000-8000-000000000202',
                    'token_hash' => $token->token_hash,
                    'event_version' => 2,
                    'representation' => 'v2_intent',
                    'canonical_bytes' => $payload,
                    'executor_mode' => 'canonical',
                    'start_delay_ms' => 30,
                ];

                $a = $this->startWorker($schema, $directory, 'worker-a', $inputA);
                $b = $this->startWorker($schema, $directory, 'worker-b', $inputB);

                touch($directory.'/go');

                $this->assertSame(0, $a->wait(), 'Worker A stderr: '.$a->getErrorOutput());
                $this->assertSame(0, $b->wait(), 'Worker B stderr: '.$b->getErrorOutput());

                $resA = json_decode($a->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $resB = json_decode($b->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                // Revoke must always succeed with 200
                $this->assertSame(200, $resA['status'], 'Worker A (Revoke) failed: '.json_encode($resA));
                $this->assertSame('revoked', $resA['outcome']);

                // Receipt worker must either succeed (200) or reject with 401 (auth_error)
                $this->assertContains($resB['status'], [200, 401], 'Worker B returned unexpected status: '.json_encode($resB));

                if ($resB['status'] === 200) {
                    $this->assertSame('created', $resB['outcome']);
                    $this->assertDatabaseCount('mobile_event_receipts', 1);
                    $this->assertDatabaseCount('sales_invoices', 1);
                } else {
                    $this->assertSame('auth_error', $resB['outcome']);
                    // Zero financial mutations leaked when revoked first
                    $this->assertDatabaseCount('mobile_event_receipts', 0);
                    $this->assertDatabaseCount('sales_invoices', 0);
                    $this->assertDatabaseCount('customer_payments', 0);
                }

                // In all outcomes, device is now revoked in database
                $device->refresh();
                $this->assertTrue($device->isRevoked());
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

    /**
     * Barrier Test 3: Tenant membership toggle vs receipt intake.
     * Worker A deactivates the staff user's company membership under Company lock.
     * Worker B attempts receipt intake on that company with the staff user's token.
     * Verifies clean convergence without deadlock:
     * - If deactivation commits first, receipt rejects with 403 foreign_scope.
     * - If receipt commits first, receipt succeeds with 200, then deactivation succeeds.
     * - Zero deadlocks, 403 semantics strictly preserved.
     */
    public function test_concurrent_workers_membership_deactivation_vs_receipt_intake_reconciles_safely(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $this->assertTrue($schema->isOwned());
            $this->assertMatchesRegularExpression('/^accounting_p8_tmp_[a-f0-9]{12}$/D', $schema->schemaName());
            $schema->migrate();
            $schema->switchLaravelConnection();
            $actualDb = DB::selectOne('SELECT DATABASE() AS db')->db;
            $this->assertSame($schema->schemaName(), $actualDb);

            [$owner, $password, $companyA, $companyB, $deviceOwner, $tokenOwner] = $this->setupMultiCompanyFixture('MemberRace');

            // Create a staff user in Company A
            $staff = User::factory()->create([
                'email' => 'staff_'.Str::random(8).'@example.com',
                'password' => Hash::make('StaffSecured!Pass123'),
            ]);

            $staffMembership = CompanyUser::create([
                'company_id' => $companyA->id,
                'user_id' => $staff->id,
                'status' => 'active',
                'is_owner' => false,
                'joined_at' => now(),
            ]);

            setPermissionsTeamId($companyA->id);
            $staff->assignRole('Manager');

            $staffDeviceId = (string) Str::uuid();
            $staffDevice = MobileDevice::create([
                'device_id' => $staffDeviceId,
                'device_name' => 'Staff Race Device',
                'user_id' => $staff->id,
            ]);

            $staffRawToken = 'st_m_staff_'.bin2hex(random_bytes(32));
            $staffToken = MobileAccessToken::create([
                'token_hash' => hash('sha256', $staffRawToken),
                'device_id' => $staffDeviceId,
                'user_id' => $staff->id,
                'company_id' => $companyA->id,
                'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $staff->password), 0, 16),
                'expires_at' => now()->addDays(7),
            ]);

            $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            $a = $b = null;

            try {
                $inputA = [
                    'mode' => 'toggle_member',
                    'company_id' => $companyA->id,
                    'target_user_id' => $staff->id,
                    'actor_id' => $owner->id,
                    'hold_lock_table' => 'companies',
                    'hold_ms' => 150,
                ];

                $payload = '{"action":"race_sale","amount":"25.000000"}';
                $inputB = [
                    'mode' => 'receipt',
                    'company_id' => $companyA->id,
                    'client_event_id' => '00000000-0000-4000-8000-000000000203',
                    'token_hash' => $staffToken->token_hash,
                    'event_version' => 2,
                    'representation' => 'v2_intent',
                    'canonical_bytes' => $payload,
                    'executor_mode' => 'canonical',
                    'start_delay_ms' => 30,
                ];

                $a = $this->startWorker($schema, $directory, 'worker-a', $inputA);
                $b = $this->startWorker($schema, $directory, 'worker-b', $inputB);

                touch($directory.'/go');

                $this->assertSame(0, $a->wait(), 'Worker A stderr: '.$a->getErrorOutput());
                $this->assertSame(0, $b->wait(), 'Worker B stderr: '.$b->getErrorOutput());

                $resA = json_decode($a->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $resB = json_decode($b->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                $this->assertSame(200, $resA['status'], 'Worker A (Toggle) failed: '.json_encode($resA));
                $this->assertSame('inactive', $resA['new_status']);

                $this->assertContains($resB['status'], [200, 403], 'Worker B returned unexpected status: '.json_encode($resB));

                if ($resB['status'] === 200) {
                    $this->assertSame('created', $resB['outcome']);
                    $this->assertDatabaseCount('mobile_event_receipts', 1);
                } else {
                    $this->assertSame('foreign_scope', $resB['outcome']);
                    $this->assertDatabaseCount('mobile_event_receipts', 0);
                }

                // Final state: membership is inactive
                $staffMembership->refresh();
                $this->assertSame('inactive', $staffMembership->status);
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

    /**
     * Barrier Test 4: Password change (advancing password epoch) vs receipt intake.
     * Worker A changes user password via Fortify ResetUserPassword, invalidating existing mobile tokens.
     * Worker B attempts receipt intake with existing token.
     * Verifies clean convergence:
     * - If password change commits first, receipt rejects with 401 auth_error fail-closed.
     * - Clearly verifies repeat stale token rejected even if revoked_at not persisted in DB.
     * - Zero deadlocks.
     */
    public function test_concurrent_workers_password_epoch_change_vs_receipt_intake_reconciles_safely(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $this->assertTrue($schema->isOwned());
            $this->assertMatchesRegularExpression('/^accounting_p8_tmp_[a-f0-9]{12}$/D', $schema->schemaName());
            $schema->migrate();
            $schema->switchLaravelConnection();
            $actualDb = DB::selectOne('SELECT DATABASE() AS db')->db;
            $this->assertSame($schema->schemaName(), $actualDb);

            [$user, $password, $companyA, $companyB, $device, $token, $rawToken] = $this->setupMultiCompanyFixture('EpochRace');

            $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            $a = $b = null;

            try {
                $inputA = [
                    'mode' => 'password_change',
                    'user_id' => $user->id,
                    'new_password' => 'NewFreshSecured!Pass999',
                    'hold_lock_table' => 'users',
                    'hold_ms' => 150,
                ];

                $payload = '{"action":"race_sale","amount":"25.000000"}';
                $inputB = [
                    'mode' => 'receipt',
                    'company_id' => $companyA->id,
                    'client_event_id' => '00000000-0000-4000-8000-000000000204',
                    'token_hash' => $token->token_hash,
                    'event_version' => 2,
                    'representation' => 'v2_intent',
                    'canonical_bytes' => $payload,
                    'executor_mode' => 'canonical',
                    'start_delay_ms' => 30,
                ];

                $a = $this->startWorker($schema, $directory, 'worker-a', $inputA);
                $b = $this->startWorker($schema, $directory, 'worker-b', $inputB);

                touch($directory.'/go');

                $this->assertSame(0, $a->wait(), 'Worker A stderr: '.$a->getErrorOutput());
                $this->assertSame(0, $b->wait(), 'Worker B stderr: '.$b->getErrorOutput());

                $resA = json_decode($a->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $resB = json_decode($b->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                $this->assertSame(200, $resA['status'], 'Worker A (Password) failed: '.json_encode($resA));
                $this->assertSame('password_updated', $resA['outcome']);

                $this->assertContains($resB['status'], [200, 401], 'Worker B returned unexpected status: '.json_encode($resB));

                if ($resB['status'] === 200) {
                    $this->assertSame('created', $resB['outcome']);
                    $this->assertDatabaseCount('mobile_event_receipts', 1);
                } else {
                    $this->assertSame('auth_error', $resB['outcome']);
                    $this->assertDatabaseCount('mobile_event_receipts', 0);
                    // Clearly test repeat stale token rejected even if revoked_at not persisted in DB
                    $tokenRow = DB::table('mobile_access_tokens')->where('token_hash', $token->token_hash)->first();
                    $this->assertNotNull($tokenRow);
                    $this->assertNull($tokenRow->revoked_at, 'revoked_at must NOT be persisted by exception autocommit');

                    $manager = app(MobileEventReceiptManager::class);
                    $this->expectException(MobileEventAuthenticationException::class);
                    $this->expectExceptionMessage('User credentials have changed since token establishment.');

                    $manager->recordOrReplay(
                        companyId: $companyA->id,
                        clientEventId: '00000000-0000-4000-8000-000000000299',
                        tokenHash: $token->token_hash,
                        eventVersion: 2,
                        representation: 'v2_intent',
                        canonicalBytes: $payload
                    );
                }
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

    /**
     * Deterministic race test for Point 1:
     * AuthController::login must use locked current membership rows to authoritatively filter
     * activeCompanies, defaultCompany, and token company, rejecting when no active memberships remain.
     * Prevents race condition where candidate query saw membership active but concurrent transaction
     * revoked it before/during membership locking.
     */
    public function test_auth_controller_login_authoritatively_filters_revoked_memberships_during_race(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $this->assertTrue($schema->isOwned());
            $this->assertMatchesRegularExpression('/^accounting_p8_tmp_[a-f0-9]{12}$/D', $schema->schemaName());
            $schema->migrate();
            $schema->switchLaravelConnection();
            $actualDb = DB::selectOne('SELECT DATABASE() AS db')->db;
            $this->assertSame($schema->schemaName(), $actualDb);

            [$user, $password, $companyA, $companyB, $device, $token, $rawToken] = $this->setupMultiCompanyFixture('AuthFilter');

            // Case A: Deactivate Company B membership under row lock
            CompanyUser::where('company_id', $companyB->id)
                ->where('user_id', $user->id)
                ->update(['status' => 'inactive']);

            // Challenge for login
            $challengeId = (string) Str::uuid();
            $nonce = bin2hex(random_bytes(32));
            MobileAuthChallenge::create([
                'challenge_id' => $challengeId,
                'device_id' => $device->device_id,
                'nonce_hash' => hash('sha256', $nonce),
                'app_version' => '1.0.0',
                'platform' => 'android',
                'expires_at' => now()->addMinutes(5),
            ]);

            $request = Request::create(
                '/api/mobile/v1/auth/login',
                'POST',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                    'REMOTE_ADDR' => '127.0.0.1',
                ],
                json_encode([
                    'email' => $user->email,
                    'password' => $password,
                    'device_id' => $device->device_id,
                    'device_name' => 'Filter Test Device',
                    'challenge_id' => $challengeId,
                    'nonce' => $nonce,
                ], JSON_THROW_ON_ERROR)
            );

            /** @var Kernel $kernel */
            $kernel = app(Kernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            $this->assertSame(200, $response->getStatusCode());
            $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

            // Authoritative filter: Company B MUST NOT be present; exactly 1 active company (Company A)
            $this->assertCount(1, $data['companies']);
            $this->assertSame($companyA->public_id, $data['companies'][0]['id']);
            $this->assertTrue($data['companies'][0]['is_default']);

            // Case B: Deactivate all memberships for user
            CompanyUser::where('company_id', $companyA->id)
                ->where('user_id', $user->id)
                ->update(['status' => 'inactive']);

            $challengeId2 = (string) Str::uuid();
            $nonce2 = bin2hex(random_bytes(32));
            MobileAuthChallenge::create([
                'challenge_id' => $challengeId2,
                'device_id' => $device->device_id,
                'nonce_hash' => hash('sha256', $nonce2),
                'app_version' => '1.0.0',
                'platform' => 'android',
                'expires_at' => now()->addMinutes(5),
            ]);

            $request2 = Request::create(
                '/api/mobile/v1/auth/login',
                'POST',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                    'REMOTE_ADDR' => '127.0.0.1',
                ],
                json_encode([
                    'email' => $user->email,
                    'password' => $password,
                    'device_id' => $device->device_id,
                    'device_name' => 'Filter Test Device',
                    'challenge_id' => $challengeId2,
                    'nonce' => $nonce2,
                ], JSON_THROW_ON_ERROR)
            );

            $response2 = $kernel->handle($request2);
            $kernel->terminate($request2, $response2);

            // With zero active memberships confirmed under lock, login MUST fail closed with 401 INVALID_CREDENTIALS
            $this->assertSame(401, $response2->getStatusCode());
            $errorData = json_decode((string) $response2->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('INVALID_CREDENTIALS', $errorData['error']['code']);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }
}
