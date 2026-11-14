<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Company\ToggleCompanyMemberStatusAction;
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
use App\Models\MoneyAccount;
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
use App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile;
use App\Support\Mobile\Receipt\Exceptions\MobileEventAuthenticationException;
use App\Support\Mobile\Receipt\MobileEventReceiptManager;
use App\Support\Tenancy\CompanyContext;
use Closure;
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
 * Two-process MariaDB barrier tests qualifying bounded mobile lock interleavings
 * across AuthController::login, DeviceController::revoke, tenant membership/role
 * mutations, and MobileEventReceiptManager::recordOrReplay.
 *
 * All tests execute as independent OS processes against an isolated, freshly-created
 * owned disposable MariaDB database on 127.0.0.1:3342.
 */
final class MobileReceiptLockOrderQualificationTest extends TestCase
{
    public function test_admin_first_filters_revoked_company_after_candidate_discovery(): void
    {
        $this->qualifyCandidateWindow(true, false);
    }

    public function test_admin_first_all_revoked_returns_generic_401(): void
    {
        $this->qualifyCandidateWindow(true, true);
    }

    public function test_login_first_holds_company_until_admission_commits(): void
    {
        $this->qualifyCandidateWindow(false, false);
    }

    private function qualifyCandidateWindow(bool $adminFirst, bool $allRevoked): void
    {
        DisposableMariaDbSchema::assertPrimarySchema((string) DB::selectOne('SELECT DATABASE() AS db')->db);
        $schema = DisposableMariaDbSchema::create();
        $workers = [];
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $this->assertSame($schema->schemaName(), DB::selectOne('SELECT DATABASE() AS db')->db);
            [$owner, $password, $companyA, $companyB, $device] = $this->setupMultiCompanyFixture('Candidate');
            // Distinct real Owner retains authority while the login principal is deactivated.
            $staff = User::factory()->create(['password' => Hash::make($password)]);
            foreach ([$companyA, $companyB] as $company) {
                CompanyUser::create(['company_id' => $company->id, 'user_id' => $staff->id, 'status' => 'active', 'is_owner' => false]);
            }
            if ($allRevoked) {
                app(CompanyContext::class)->setCompany($companyA, $owner);
                app(ToggleCompanyMemberStatusAction::class)->execute($companyA, CompanyUser::where('company_id', $companyA->id)->where('user_id', $staff->id)->firstOrFail(), $owner);
            }
            app(CompanyContext::class)->setCompany($companyB, $owner);
            $device->update(['user_id' => $staff->id]);
            $nonce = bin2hex(random_bytes(32));
            $challengeId = (string) Str::uuid();
            MobileAuthChallenge::create([
                'challenge_id' => $challengeId, 'device_id' => $device->device_id,
                'nonce_hash' => hash('sha256', $nonce), 'app_version' => '1.0.0',
                'platform' => 'android', 'expires_at' => now()->addMinutes(5),
            ]);
            $login = $workers[] = $this->startWorker($schema, $directory, 'worker-a', [
                'mode' => 'login', 'email' => $staff->email, 'password' => $password,
                'device_id' => $device->device_id, 'challenge_id' => $challengeId, 'nonce' => $nonce,
                'pause_before_company_lock' => $adminFirst,
                'hold_lock_table' => $adminFirst ? null : 'companies',
                'wait_for_file' => 'release-login',
            ]);
            touch($directory.'/go');
            StrictBarrier::await(fn (): bool => is_file($directory.($adminFirst ? '/candidate-returned' : '/worker-a.locked')), fn (): bool => $login->isRunning());
            $toggle = $workers[] = $this->startWorker($schema, $directory, 'worker-b', [
                'mode' => 'toggle_member', 'company_id' => $companyB->id,
                'target_user_id' => $staff->id, 'actor_id' => $owner->id,
                'hold_lock_table' => $adminFirst ? 'companies' : null,
                'wait_for_file' => 'release-admin',
            ]);
            if ($adminFirst) {
                StrictBarrier::await(fn (): bool => is_file($directory.'/worker-b.locked'), fn (): bool => $toggle->isRunning());
                touch($directory.'/release-candidate');
            }
            $contender = $adminFirst ? $login : $toggle;
            $slot = $adminFirst ? 'worker-a' : 'worker-b';
            StrictBarrier::await(function () use ($directory, $schema, $slot): bool {
                $connectionPath = $directory.'/'.$slot.'.connection';
                if (! is_file($connectionPath)) {
                    return false;
                }
                $id = trim((string) file_get_contents($connectionPath));
                if (! ctype_digit($id)) {
                    return false;
                }
                foreach (DB::select('SHOW FULL PROCESSLIST') as $row) {
                    $sql = strtolower((string) $row->Info);
                    if ((string) $row->Id === $id && $row->db === $schema->schemaName()
                        && str_contains($sql, 'from `companies`') && str_contains($sql, 'for update')) {
                        return true;
                    }
                }

                return false;
            }, fn (): bool => $contender->isRunning() && $login->isRunning() && $toggle->isRunning());
            touch($directory.($adminFirst ? '/release-admin' : '/release-login'));
            $this->assertSame(0, $login->wait(), $login->getErrorOutput());
            $loginResult = json_decode($login->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($allRevoked ? 401 : 200, $loginResult['status']);
            if (! $allRevoked) {
                $ids = array_column($loginResult['body']['companies'], 'id');
                $this->assertContains($companyA->public_id, $ids);
                $this->assertSame(! $adminFirst, in_array($companyB->public_id, $ids, true));
            }
            $this->assertSame(0, $loginResult['retries']);
            $this->assertSame(0, $toggle->wait(), $toggle->getErrorOutput());
            $result = json_decode($toggle->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('inactive', $result['new_status']);
            $this->assertSame(0, $result['retries']);

        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
            $schema->drop();
        }
    }

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
            StrictBarrier::await(
                fn (): bool => $this->isFortifyUpdatePending($schema, $directory),
                fn (): bool => $worker->isRunning()
            );
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

    public function test_reset_wins_first_and_missing_holder_cannot_ack_contention(): void
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

            $worker = $this->startWorker($schema, $directory, 'worker-a', [
                'mode' => 'password_change', 'user_id' => $user->id,
                'new_password' => 'FreshStrict!Password123',
            ]);
            touch($directory.'/go');
            // Same test account can inspect its own connection's server-side state.
            // UPDATE is visible while pending, unlike Laravel's after-query event.
            $worker->wait();
            try {
                StrictBarrier::await(
                    fn (): bool => $this->isFortifyUpdatePending($schema, $directory),
                    fn (): bool => $worker->isRunning(),
                    10
                );
                $this->fail('Missing holder must not acknowledge contention.');
            } catch (\RuntimeException $failure) {
                $this->assertStringContainsString('peer exited', $failure->getMessage());
            }
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

    private function isFortifyUpdatePending(DisposableMariaDbSchema $schema, string $directory): bool
    {
        $attemptFile = $directory.'/worker-a.attempt';
        if (! is_file($attemptFile)) {
            $attemptFile = $directory.'/worker-a.connection';
        }
        if (! is_file($attemptFile)) {
            return false;
        }
        $connectionId = trim((string) file_get_contents($attemptFile));
        foreach (DB::select('SHOW FULL PROCESSLIST') as $row) {
            if ((string) $row->Id === $connectionId
                && $row->db === $schema->schemaName()
                && str_contains(strtolower((string) $row->Info), 'update `users`')
                && str_contains(strtolower((string) $row->State), 'updat')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tables
     */
    private function awaitContenderPendingLock(
        DisposableMariaDbSchema $schema,
        string $directory,
        string $slot,
        array $tables,
        Closure $alive
    ): void {
        StrictBarrier::await(function () use ($directory, $schema, $slot, $tables): bool {
            $connectionPath = $directory.'/'.$slot.'.connection';
            if (! is_file($connectionPath)) {
                return false;
            }
            $id = trim((string) file_get_contents($connectionPath));
            if (! ctype_digit($id)) {
                return false;
            }
            foreach (DB::select('SHOW FULL PROCESSLIST') as $row) {
                if ((string) $row->Id !== $id || $row->db !== $schema->schemaName()) {
                    continue;
                }
                $sql = strtolower((string) ($row->Info ?? ''));
                if (! str_contains($sql, 'for update')) {
                    continue;
                }
                foreach ($tables as $table) {
                    if (str_contains($sql, 'from `'.$table.'`') || str_contains($sql, 'from '.$table)) {
                        return true;
                    }
                }
            }

            return false;
        }, $alive);
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

    private function buildNormativePayload(
        Company $company,
        User $owner,
        MobileDevice $device,
        string $clientEventId,
        string $amount = '25.000000',
        string $unitPrice = '25.000000',
        string $quantity = '1.000000'
    ): string {
        $customer = Customer::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
        $warehouse = Warehouse::withoutGlobalScopes()->where('company_id', $company->id)->where('is_default', true)->firstOrFail();
        $product = Product::withoutGlobalScopes()->where('company_id', $company->id)->where('track_stock', true)->firstOrFail();
        $unit = Unit::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'piece')->firstOrFail();
        $cash = MoneyAccount::withoutGlobalScopes()->where('company_id', $company->id)->where('account_type', 'cash')->firstOrFail();

        $serverContext = [
            'actor_id' => (string) $owner->public_id,
            'company_id' => (string) $company->public_id,
            'currency_minor_units' => 2,
        ];

        $raw = [
            'client_event_id' => $clientEventId,
            'currency' => $company->base_currency_code,
            'customer_id' => (string) $customer->public_id,
            'device_id' => (string) $device->device_id,
            'lines' => [
                [
                    'conversion_to_base' => '1.000000',
                    'product_id' => (string) $product->public_id,
                    'product_unit_id' => (string) $unit->public_id,
                    'quantity' => $quantity,
                    'tax_inclusive' => false,
                    'tax_rate' => '0.000000',
                    'tax_rate_id' => null,
                    'unit_price' => $unitPrice,
                ],
            ],
            'payment' => [
                'amount' => $amount,
                'cash_account_id' => (string) $cash->public_id,
                'payment_method' => 'cash',
                'settlement_mode' => 'full',
            ],
            'warehouse_id' => (string) $warehouse->public_id,
        ];

        $rawJson = (string) json_encode($raw, JSON_THROW_ON_ERROR);
        $profile = OnlineSaleIntentProfile::construct($serverContext, $rawJson);

        return OnlineSaleIntentProfile::serialize($profile);
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
            'MOBILE_V2_API_ENABLED' => 'true',
            'MOBILE_V2_CASH_SLICE_ENABLED' => 'true',
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
     * Uses forced held-lock interleaving on MariaDB with canonical row-held explicit release ACK protocol.
     * Verifies clean serialization, zero deadlocks, and both operations succeed in both winner orders.
     */
    public function test_concurrent_workers_login_vs_receipt_intake_converge_without_deadlock(): void
    {
        $this->qualifyLoginVsReceipt(true);
        $this->qualifyLoginVsReceipt(false);
    }

    private function qualifyLoginVsReceipt(bool $loginFirst): void
    {
        DisposableMariaDbSchema::assertPrimarySchema((string) DB::selectOne('SELECT DATABASE() AS db')->db);
        $schema = DisposableMariaDbSchema::create();
        $workers = [];
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $this->assertSame($schema->schemaName(), DB::selectOne('SELECT DATABASE() AS db')->db);

            [$user, $password, $companyA, $companyB, $device, $token, $rawToken] = $this->setupMultiCompanyFixture('LoginRace'.($loginFirst ? 'A' : 'B'));

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

            $payload = $this->buildNormativePayload($companyA, $user, $device, '00000000-0000-4000-8000-000000000201');

            $inputLogin = [
                'mode' => 'login',
                'email' => $user->email,
                'password' => $password,
                'device_id' => $device->device_id,
                'device_name' => 'Race Multi-Company Device',
                'challenge_id' => $challengeId,
                'nonce' => $nonce,
                'hold_lock_table' => $loginFirst ? 'users' : null,
                'wait_for_file' => $loginFirst ? 'release-login' : null,
            ];

            $inputReceipt = [
                'mode' => 'receipt',
                'company_id' => $companyA->id,
                'client_event_id' => '00000000-0000-4000-8000-000000000201',
                'token_hash' => $token->token_hash,
                'event_version' => 2,
                'representation' => 'v2_online_sale_intent',
                'canonical_bytes' => $payload,
                'executor_mode' => 'canonical',
                'hold_lock_table' => $loginFirst ? null : 'users',
                'wait_for_file' => $loginFirst ? null : 'release-receipt',
            ];

            if ($loginFirst) {
                $login = $workers[] = $this->startWorker($schema, $directory, 'worker-a', $inputLogin);
                touch($directory.'/go');
                StrictBarrier::await(fn (): bool => is_file($directory.'/worker-a.locked'), fn (): bool => $login->isRunning());

                $receipt = $workers[] = $this->startWorker($schema, $directory, 'worker-b', $inputReceipt);
                $this->awaitContenderPendingLock(
                    $schema,
                    $directory,
                    'worker-b',
                    ['companies', 'users'],
                    fn (): bool => $login->isRunning() && $receipt->isRunning()
                );
                touch($directory.'/release-login');
            } else {
                $receipt = $workers[] = $this->startWorker($schema, $directory, 'worker-b', $inputReceipt);
                touch($directory.'/go');
                StrictBarrier::await(fn (): bool => is_file($directory.'/worker-b.locked'), fn (): bool => $receipt->isRunning());

                $login = $workers[] = $this->startWorker($schema, $directory, 'worker-a', $inputLogin);
                $this->awaitContenderPendingLock(
                    $schema,
                    $directory,
                    'worker-a',
                    ['companies', 'users'],
                    fn (): bool => $login->isRunning() && $receipt->isRunning()
                );
                touch($directory.'/release-receipt');
            }

            $this->assertSame(0, $login->wait(), 'Worker A stderr: '.$login->getErrorOutput());
            $this->assertSame(0, $receipt->wait(), 'Worker B stderr: '.$receipt->getErrorOutput());

            $resA = json_decode($login->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $resB = json_decode($receipt->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(200, $resA['status'], 'Worker A (Login) failed: '.json_encode($resA));
            $this->assertSame('logged_in', $resA['outcome']);
            $this->assertNotEmpty($resA['token']);
            $this->assertGreaterThanOrEqual(2, $resA['companies_count']);
            $this->assertSame(0, $resA['retries']);

            $this->assertSame(200, $resB['status'], 'Worker B (Receipt) failed: '.json_encode($resB));
            $this->assertSame('created', $resB['outcome']);
            $this->assertNotEmpty($resB['receipt_id']);
            $this->assertSame(0, $resB['retries']);

            $this->assertDatabaseCount('mobile_event_receipts', 1);
            $this->assertDatabaseCount('sales_invoices', 1);
            $this->assertDatabaseCount('customer_payments', 1);

            app(CompanyContext::class)->setCompany($companyA, $user);
            $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($companyA)->isHealthy);
            $this->assertTrue(app(SalesReconciliationService::class)->reconcile($companyA)->isHealthy);
            $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($companyA)->isHealthy);
            $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($companyA)->isHealthy);
        } finally {
            foreach ($workers as $w) {
                if ($w->isRunning()) {
                    $w->stop();
                }
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }

    /**
     * Barrier Test 2: Device revocation vs receipt intake.
     * Worker A revokes the device via HTTP Kernel; Worker B attempts receipt intake with a token on that device.
     * Uses forced held-lock interleaving on mobile_devices row with canonical row-held explicit release ACK protocol.
     * Asserts:
     * - Both winner orders: revoke first (401 auth_error fail-closed, zero mutations) and receipt first (200 created, device revoked).
     * - Never partial commits, never duplicate numbers, zero deadlocks.
     */
    public function test_concurrent_workers_device_revoke_vs_receipt_intake_reconciles_safely(): void
    {
        $this->qualifyRevokeVsReceipt(true);
        $this->qualifyRevokeVsReceipt(false);
    }

    private function qualifyRevokeVsReceipt(bool $revokeFirst): void
    {
        DisposableMariaDbSchema::assertPrimarySchema((string) DB::selectOne('SELECT DATABASE() AS db')->db);
        $schema = DisposableMariaDbSchema::create();
        $workers = [];
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $this->assertSame($schema->schemaName(), DB::selectOne('SELECT DATABASE() AS db')->db);

            [$user, $password, $companyA, $companyB, $device, $token, $rawToken] = $this->setupMultiCompanyFixture('RevokeRace'.($revokeFirst ? 'A' : 'B'));

            $payload = $this->buildNormativePayload($companyA, $user, $device, '00000000-0000-4000-8000-000000000202');

            $inputRevoke = [
                'mode' => 'revoke',
                'device_id' => $device->device_id,
                'raw_token' => $rawToken,
                'reason' => 'Lost device reported during intake',
                'hold_lock_table' => $revokeFirst ? 'mobile_devices' : null,
                'wait_for_file' => $revokeFirst ? 'release-revoke' : null,
            ];

            $inputReceipt = [
                'mode' => 'receipt',
                'company_id' => $companyA->id,
                'client_event_id' => '00000000-0000-4000-8000-000000000202',
                'token_hash' => $token->token_hash,
                'event_version' => 2,
                'representation' => 'v2_online_sale_intent',
                'canonical_bytes' => $payload,
                'executor_mode' => 'canonical',
                'hold_lock_table' => $revokeFirst ? null : 'mobile_devices',
                'wait_for_file' => $revokeFirst ? null : 'release-receipt',
            ];

            if ($revokeFirst) {
                $revoke = $workers[] = $this->startWorker($schema, $directory, 'worker-a', $inputRevoke);
                touch($directory.'/go');
                StrictBarrier::await(fn (): bool => is_file($directory.'/worker-a.locked'), fn (): bool => $revoke->isRunning());

                $receipt = $workers[] = $this->startWorker($schema, $directory, 'worker-b', $inputReceipt);
                $this->awaitContenderPendingLock(
                    $schema,
                    $directory,
                    'worker-b',
                    ['mobile_devices', 'users'],
                    fn (): bool => $revoke->isRunning() && $receipt->isRunning()
                );
                touch($directory.'/release-revoke');
            } else {
                $receipt = $workers[] = $this->startWorker($schema, $directory, 'worker-b', $inputReceipt);
                touch($directory.'/go');
                StrictBarrier::await(fn (): bool => is_file($directory.'/worker-b.locked'), fn (): bool => $receipt->isRunning());

                $revoke = $workers[] = $this->startWorker($schema, $directory, 'worker-a', $inputRevoke);
                $this->awaitContenderPendingLock(
                    $schema,
                    $directory,
                    'worker-a',
                    ['mobile_devices', 'users'],
                    fn (): bool => $revoke->isRunning() && $receipt->isRunning()
                );
                touch($directory.'/release-receipt');
            }

            $this->assertSame(0, $revoke->wait(), 'Worker A stderr: '.$revoke->getErrorOutput());
            $this->assertSame(0, $receipt->wait(), 'Worker B stderr: '.$receipt->getErrorOutput());

            $resA = json_decode($revoke->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $resB = json_decode($receipt->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            // Revoke must always succeed with 200
            $this->assertSame(200, $resA['status'], 'Worker A (Revoke) failed: '.json_encode($resA));
            $this->assertSame('revoked', $resA['outcome']);

            if ($revokeFirst) {
                // When revoke wins first, receipt intake fails closed with 401 auth_error
                $this->assertSame(401, $resB['status'], 'Worker B (Receipt) should fail closed: '.json_encode($resB));
                $this->assertSame('auth_error', $resB['outcome']);
                $this->assertDatabaseCount('mobile_event_receipts', 0);
                $this->assertDatabaseCount('sales_invoices', 0);
                $this->assertDatabaseCount('customer_payments', 0);
            } else {
                // When receipt wins first, receipt intake succeeds with 200
                $this->assertSame(200, $resB['status'], 'Worker B (Receipt) should succeed: '.json_encode($resB));
                $this->assertSame('created', $resB['outcome']);
                $this->assertNotEmpty($resB['receipt_id']);
                $this->assertDatabaseCount('mobile_event_receipts', 1);
                $this->assertDatabaseCount('sales_invoices', 1);
                $this->assertDatabaseCount('customer_payments', 1);

                app(CompanyContext::class)->setCompany($companyA, $user);
                $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($companyA)->isHealthy);
                $this->assertTrue(app(SalesReconciliationService::class)->reconcile($companyA)->isHealthy);
            }

            $device->refresh();
            $this->assertTrue($device->isRevoked());
        } finally {
            foreach ($workers as $w) {
                if ($w->isRunning()) {
                    $w->stop();
                }
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }

    /**
     * Barrier Test 3: Tenant membership toggle vs receipt intake.
     * Worker A deactivates the staff user's company membership under Company lock.
     * Worker B attempts receipt intake on that company with the staff user's token.
     * Verifies clean convergence without deadlock in both winner orders:
     * - If deactivation commits first, receipt rejects with 403 foreign_scope.
     * - If receipt commits first, receipt succeeds with 200, then deactivation succeeds.
     * - Zero deadlocks, 403 semantics strictly preserved.
     */
    public function test_concurrent_workers_membership_deactivation_vs_receipt_intake_reconciles_safely(): void
    {
        $this->qualifyMembershipVsReceipt(true);
        $this->qualifyMembershipVsReceipt(false);
    }

    private function qualifyMembershipVsReceipt(bool $toggleFirst): void
    {
        DisposableMariaDbSchema::assertPrimarySchema((string) DB::selectOne('SELECT DATABASE() AS db')->db);
        $schema = DisposableMariaDbSchema::create();
        $workers = [];
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $this->assertSame($schema->schemaName(), DB::selectOne('SELECT DATABASE() AS db')->db);

            [$owner, $password, $companyA, $companyB, $deviceOwner, $tokenOwner] = $this->setupMultiCompanyFixture('MemberRace'.($toggleFirst ? 'A' : 'B'));

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

            $payload = $this->buildNormativePayload($companyA, $staff, $staffDevice, '00000000-0000-4000-8000-000000000203');

            $inputToggle = [
                'mode' => 'toggle_member',
                'company_id' => $companyA->id,
                'target_user_id' => $staff->id,
                'actor_id' => $owner->id,
                'hold_lock_table' => $toggleFirst ? 'companies' : null,
                'wait_for_file' => $toggleFirst ? 'release-toggle' : null,
            ];

            $inputReceipt = [
                'mode' => 'receipt',
                'company_id' => $companyA->id,
                'client_event_id' => '00000000-0000-4000-8000-000000000203',
                'token_hash' => $staffToken->token_hash,
                'event_version' => 2,
                'representation' => 'v2_online_sale_intent',
                'canonical_bytes' => $payload,
                'executor_mode' => 'canonical',
                'hold_lock_table' => $toggleFirst ? null : 'companies',
                'wait_for_file' => $toggleFirst ? null : 'release-receipt',
            ];

            if ($toggleFirst) {
                $toggle = $workers[] = $this->startWorker($schema, $directory, 'worker-a', $inputToggle);
                touch($directory.'/go');
                StrictBarrier::await(fn (): bool => is_file($directory.'/worker-a.locked'), fn (): bool => $toggle->isRunning());

                $receipt = $workers[] = $this->startWorker($schema, $directory, 'worker-b', $inputReceipt);
                $this->awaitContenderPendingLock(
                    $schema,
                    $directory,
                    'worker-b',
                    ['companies'],
                    fn (): bool => $toggle->isRunning() && $receipt->isRunning()
                );
                touch($directory.'/release-toggle');
            } else {
                $receipt = $workers[] = $this->startWorker($schema, $directory, 'worker-b', $inputReceipt);
                touch($directory.'/go');
                StrictBarrier::await(fn (): bool => is_file($directory.'/worker-b.locked'), fn (): bool => $receipt->isRunning());

                $toggle = $workers[] = $this->startWorker($schema, $directory, 'worker-a', $inputToggle);
                $this->awaitContenderPendingLock(
                    $schema,
                    $directory,
                    'worker-a',
                    ['companies'],
                    fn (): bool => $toggle->isRunning() && $receipt->isRunning()
                );
                touch($directory.'/release-receipt');
            }

            $this->assertSame(0, $toggle->wait(), 'Worker A stderr: '.$toggle->getErrorOutput());
            $this->assertSame(0, $receipt->wait(), 'Worker B stderr: '.$receipt->getErrorOutput());

            $resA = json_decode($toggle->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $resB = json_decode($receipt->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(200, $resA['status'], 'Worker A (Toggle) failed: '.json_encode($resA));
            $this->assertSame('inactive', $resA['new_status']);

            if ($toggleFirst) {
                // When toggle deactivates first, receipt intake fails closed with 403 foreign_scope
                $this->assertSame(403, $resB['status'], 'Worker B (Receipt) should fail closed: '.json_encode($resB));
                $this->assertSame('foreign_scope', $resB['outcome']);
                $this->assertDatabaseCount('mobile_event_receipts', 0);
                $this->assertDatabaseCount('sales_invoices', 0);
                $this->assertDatabaseCount('customer_payments', 0);
            } else {
                // When receipt wins first, receipt succeeds with 200
                $this->assertSame(200, $resB['status'], 'Worker B (Receipt) should succeed: '.json_encode($resB));
                $this->assertSame('created', $resB['outcome']);
                $this->assertNotEmpty($resB['receipt_id']);
                $this->assertDatabaseCount('mobile_event_receipts', 1);
                $this->assertDatabaseCount('sales_invoices', 1);
                $this->assertDatabaseCount('customer_payments', 1);

                app(CompanyContext::class)->setCompany($companyA, $owner);
                $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($companyA)->isHealthy);
                $this->assertTrue(app(SalesReconciliationService::class)->reconcile($companyA)->isHealthy);
            }

            $staffMembership->refresh();
            $this->assertSame('inactive', $staffMembership->status);
        } finally {
            foreach ($workers as $w) {
                if ($w->isRunning()) {
                    $w->stop();
                }
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }

    /**
     * Barrier Test 3b: Role definition / permission revocation vs receipt intake.
     * Worker A revokes financial permission (sales.invoice.post) from Manager role.
     * Worker B attempts receipt intake with staff user assigned Manager role.
     * Asserts:
     * - Revocation first: Receipt intake fails closed with 403 unauthorized, zero financial mutations.
     * - Receipt first: Receipt intake succeeds with 200, then revocation succeeds with 200.
     */
    public function test_concurrent_workers_role_permission_revocation_vs_receipt_intake_reconciles_safely(): void
    {
        $this->qualifyRoleRevokeVsReceipt(true);
        $this->qualifyRoleRevokeVsReceipt(false);
    }

    private function qualifyRoleRevokeVsReceipt(bool $revokeFirst): void
    {
        DisposableMariaDbSchema::assertPrimarySchema((string) DB::selectOne('SELECT DATABASE() AS db')->db);
        $schema = DisposableMariaDbSchema::create();
        $workers = [];
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $this->assertSame($schema->schemaName(), DB::selectOne('SELECT DATABASE() AS db')->db);

            [$owner, $password, $companyA, $companyB, $deviceOwner, $tokenOwner] = $this->setupMultiCompanyFixture('RoleRace'.($revokeFirst ? 'A' : 'B'));

            // Create a staff user in Company A
            $staff = User::factory()->create([
                'email' => 'staff_role_'.Str::random(8).'@example.com',
                'password' => Hash::make('StaffSecured!Pass123'),
            ]);

            CompanyUser::create([
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
                'device_name' => 'Staff Role Race Device',
                'user_id' => $staff->id,
            ]);

            $staffRawToken = 'st_m_staff_role_'.bin2hex(random_bytes(32));
            $staffToken = MobileAccessToken::create([
                'token_hash' => hash('sha256', $staffRawToken),
                'device_id' => $staffDeviceId,
                'user_id' => $staff->id,
                'company_id' => $companyA->id,
                'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $staff->password), 0, 16),
                'expires_at' => now()->addDays(7),
            ]);

            $payload = $this->buildNormativePayload($companyA, $staff, $staffDevice, '00000000-0000-4000-8000-000000000205');

            $inputRevoke = [
                'mode' => 'update_role_permission',
                'company_id' => $companyA->id,
                'role_name' => 'Manager',
                'permission_name' => 'sales.invoice.post',
                'enable' => false,
                'actor_id' => $owner->id,
            ];

            $inputReceipt = [
                'mode' => 'receipt',
                'company_id' => $companyA->id,
                'client_event_id' => '00000000-0000-4000-8000-000000000205',
                'token_hash' => $staffToken->token_hash,
                'event_version' => 2,
                'representation' => 'v2_online_sale_intent',
                'canonical_bytes' => $payload,
                'executor_mode' => 'canonical',
            ];

            if ($revokeFirst) {
                $roleWorker = $workers[] = $this->startWorker($schema, $directory, 'worker-a', $inputRevoke);
                touch($directory.'/go');
                $this->assertSame(0, $roleWorker->wait(), 'Worker A stderr: '.$roleWorker->getErrorOutput());
                $resA = json_decode($roleWorker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(200, $resA['status'], 'Worker A (Role Revoke) failed: '.json_encode($resA));
                $this->assertSame('permission_updated', $resA['outcome']);

                $receipt = $workers[] = $this->startWorker($schema, $directory, 'worker-b', $inputReceipt);
                touch($directory.'/go-worker-b');
                $this->assertSame(0, $receipt->wait(), 'Worker B stderr: '.$receipt->getErrorOutput());
                $resB = json_decode($receipt->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                $this->assertSame(403, $resB['status'], 'Worker B (Receipt) should fail closed: '.json_encode($resB));
                $this->assertSame('unauthorized', $resB['outcome']);
                $this->assertDatabaseCount('mobile_event_receipts', 0);
                $this->assertDatabaseCount('sales_invoices', 0);
                $this->assertDatabaseCount('customer_payments', 0);
            } else {
                $receipt = $workers[] = $this->startWorker($schema, $directory, 'worker-b', $inputReceipt);
                touch($directory.'/go');
                $this->assertSame(0, $receipt->wait(), 'Worker B stderr: '.$receipt->getErrorOutput());
                $resB = json_decode($receipt->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                $this->assertSame(200, $resB['status'], 'Worker B (Receipt) should succeed: '.json_encode($resB));
                $this->assertSame('created', $resB['outcome']);
                $this->assertNotEmpty($resB['receipt_id']);
                $this->assertDatabaseCount('mobile_event_receipts', 1);
                $this->assertDatabaseCount('sales_invoices', 1);
                $this->assertDatabaseCount('customer_payments', 1);

                $roleWorker = $workers[] = $this->startWorker($schema, $directory, 'worker-a', $inputRevoke);
                touch($directory.'/go-worker-a');
                $this->assertSame(0, $roleWorker->wait(), 'Worker A stderr: '.$roleWorker->getErrorOutput());
                $resA = json_decode($roleWorker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(200, $resA['status'], 'Worker A (Role Revoke) failed: '.json_encode($resA));
                $this->assertSame('permission_updated', $resA['outcome']);

                app(CompanyContext::class)->setCompany($companyA, $owner);
                $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($companyA)->isHealthy);
                $this->assertTrue(app(SalesReconciliationService::class)->reconcile($companyA)->isHealthy);
            }
        } finally {
            foreach ($workers as $w) {
                if ($w->isRunning()) {
                    $w->stop();
                }
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }

    /**
     * Barrier Test 4: Password change (advancing password epoch) vs receipt intake.
     * Worker A changes user password via Fortify ResetUserPassword, invalidating existing mobile tokens.
     * Worker B attempts receipt intake with existing token.
     * Verifies clean convergence in both winner orders:
     * - Intake first: Receipt holds User lock, Fortify update blocks on UPDATE users, receipt completes, update commits, stale token fails.
     * - Reset first: Password change commits first, receipt rejects with 401 auth_error fail-closed.
     * - Clearly verifies repeat stale token rejected even if revoked_at not persisted in DB.
     * - Zero deadlocks.
     */
    public function test_concurrent_workers_password_epoch_change_vs_receipt_intake_reconciles_safely(): void
    {
        $this->qualifyPasswordVsReceipt(false);
        $this->qualifyPasswordVsReceipt(true);
    }

    private function qualifyPasswordVsReceipt(bool $resetFirst): void
    {
        DisposableMariaDbSchema::assertPrimarySchema((string) DB::selectOne('SELECT DATABASE() AS db')->db);
        $schema = DisposableMariaDbSchema::create();
        $workers = [];
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $this->assertSame($schema->schemaName(), DB::selectOne('SELECT DATABASE() AS db')->db);

            [$user, $password, $companyA, $companyB, $device, $token, $rawToken] = $this->setupMultiCompanyFixture('EpochRace'.($resetFirst ? 'A' : 'B'));

            $payload = $this->buildNormativePayload($companyA, $user, $device, '00000000-0000-4000-8000-000000000204');

            $inputPassword = [
                'mode' => 'password_change',
                'user_id' => $user->id,
                'new_password' => 'NewFreshSecured!Pass999',
            ];

            $inputReceipt = [
                'mode' => 'receipt',
                'company_id' => $companyA->id,
                'client_event_id' => '00000000-0000-4000-8000-000000000204',
                'token_hash' => $token->token_hash,
                'event_version' => 2,
                'representation' => 'v2_online_sale_intent',
                'canonical_bytes' => $payload,
                'executor_mode' => 'canonical',
                'hold_lock_table' => $resetFirst ? null : 'users',
                'wait_for_file' => $resetFirst ? null : 'release-receipt',
            ];

            if (! $resetFirst) {
                // Receipt intake holds User lock first, Fortify update blocks on UPDATE users
                $receipt = $workers[] = $this->startWorker($schema, $directory, 'worker-b', $inputReceipt);
                touch($directory.'/go');
                StrictBarrier::await(fn (): bool => is_file($directory.'/worker-b.locked'), fn (): bool => $receipt->isRunning());

                $passwordWorker = $workers[] = $this->startWorker($schema, $directory, 'worker-a', $inputPassword);
                StrictBarrier::await(
                    fn (): bool => $this->isFortifyUpdatePending($schema, $directory),
                    fn (): bool => $passwordWorker->isRunning() && $receipt->isRunning()
                );
                touch($directory.'/release-receipt');

                $this->assertSame(0, $receipt->wait(), 'Worker B stderr: '.$receipt->getErrorOutput());
                $this->assertSame(0, $passwordWorker->wait(), 'Worker A stderr: '.$passwordWorker->getErrorOutput());

                $resB = json_decode($receipt->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $resA = json_decode($passwordWorker->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                $this->assertSame(200, $resB['status'], 'Worker B (Receipt) should succeed: '.json_encode($resB));
                $this->assertSame('created', $resB['outcome']);
                $this->assertNotEmpty($resB['receipt_id']);
                $this->assertSame(200, $resA['status'], 'Worker A (Password) should succeed: '.json_encode($resA));
                $this->assertSame('password_updated', $resA['outcome']);

                $this->assertDatabaseCount('mobile_event_receipts', 1);
                $this->assertDatabaseCount('sales_invoices', 1);
                $this->assertDatabaseCount('customer_payments', 1);

                // Subsequent intake with stale token must reject with MobileEventAuthenticationException
                $manager = app(MobileEventReceiptManager::class);
                try {
                    $manager->recordOrReplay(
                        companyId: $companyA->id,
                        clientEventId: '00000000-0000-4000-8000-000000000299',
                        tokenHash: $token->token_hash,
                        eventVersion: 2,
                        representation: 'v2_online_sale_intent',
                        canonicalBytes: $payload
                    );
                    $this->fail('Expected MobileEventAuthenticationException for stale token not thrown.');
                } catch (MobileEventAuthenticationException $e) {
                    $this->assertStringContainsString('User credentials have changed', $e->getMessage());
                }
            } else {
                // Password change commits first, invalidating password epoch
                $passwordWorker = $workers[] = $this->startWorker($schema, $directory, 'worker-a', $inputPassword);
                touch($directory.'/go');
                $this->assertSame(0, $passwordWorker->wait(), 'Worker A stderr: '.$passwordWorker->getErrorOutput());
                $resA = json_decode($passwordWorker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(200, $resA['status'], 'Worker A (Password) failed: '.json_encode($resA));
                $this->assertSame('password_updated', $resA['outcome']);

                // Receipt intake with old token must fail closed with 401 auth_error
                $receipt = $workers[] = $this->startWorker($schema, $directory, 'worker-b', $inputReceipt);
                touch($directory.'/go');
                $this->assertSame(0, $receipt->wait(), 'Worker B stderr: '.$receipt->getErrorOutput());
                $resB = json_decode($receipt->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                $this->assertSame(401, $resB['status'], 'Worker B (Receipt) should reject stale epoch: '.json_encode($resB));
                $this->assertSame('auth_error', $resB['outcome']);
                $this->assertDatabaseCount('mobile_event_receipts', 0);
                $this->assertDatabaseCount('sales_invoices', 0);
                $this->assertDatabaseCount('customer_payments', 0);

                // Token revoked_at remains null in DB (no autocommit side effect)
                $tokenRow = DB::table('mobile_access_tokens')->where('token_hash', $token->token_hash)->first();
                $this->assertNotNull($tokenRow);
                $this->assertNull($tokenRow->revoked_at, 'revoked_at must NOT be persisted by exception autocommit');

                // Lookup also throws MobileEventAuthenticationException
                try {
                    app(MobileEventReceiptManager::class)->lookup($companyA->id, '00000000-0000-4000-8000-000000000999', $token->token_hash);
                    $this->fail('Expected MobileEventAuthenticationException for stale token lookup not thrown.');
                } catch (MobileEventAuthenticationException $e) {
                    $this->assertStringContainsString('User credentials have changed', $e->getMessage());
                }
            }
        } finally {
            foreach ($workers as $w) {
                if ($w->isRunning()) {
                    $w->stop();
                }
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
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
    public function test_auth_controller_login_filters_memberships_revoked_before_login(): void
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
