<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileAuthChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

/** Committed fixtures and independent PHP processes exercise real InnoDB locks. */
final class RecoveryConcurrencyTest extends TestCase
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

    public function test_same_recovery_code_issues_only_one_token_and_different_codes_do_not_lose_updates(): void
    {
        foreach ([[true, 'mobile'], [false, 'mobile'], [true, 'browser'], [false, 'browser']] as [$same, $secondMode]) {
            $codes = ['ABCDEFGH-12345678', 'IJKLMNOP-87654321'];
            $user = User::factory()->create(['password' => 'race-password']);
            $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => encrypt(json_encode($codes))])->save();
            $company = Company::factory()->create(['status' => 'active']);
            $user->companies()->attach($company->id, ['status' => 'active']);
            $workers = [];
            $markers = [];
            DB::beginTransaction();
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            try {
                foreach (range(0, 1) as $i) {
                    // Challenge rows must be committed before children can see them.
                    // Separate connection keeps the user lock held on the default connection.
                    $connection = 'mobile_race_setup';
                    config(['database.connections.'.$connection => config('database.connections.'.config('database.default'))]);
                    $device = (string) Str::uuid();
                    $id = (string) Str::uuid();
                    $nonce = bin2hex(random_bytes(32));
                    $challenge = new MobileAuthChallenge;
                    $challenge->setConnection($connection);
                    $challenge->fill(['challenge_id' => $id, 'device_id' => $device, 'nonce_hash' => hash('sha256', $nonce), 'expires_at' => now()->addMinutes(5), 'platform' => 'android', 'app_version' => '1'])->save();
                    DB::disconnect($connection);
                    $payload = ['challenge_id' => $id, 'nonce' => $nonce, 'device_id' => $device, 'device_name' => 'Race', 'email' => $user->email, 'password' => 'race-password', 'recovery_code' => $codes[$same ? 0 : $i]];
                    $marker = tempnam(sys_get_temp_dir(), 'mobile-race-');
                    unlink($marker);
                    $markers[] = $marker;
                    $worker = new Process([PHP_BINARY, base_path('tests/Support/Mobile/login-race-worker.php'), base64_encode(json_encode($payload)), $marker, $i === 1 ? $secondMode : 'mobile'], base_path(), $this->fixture->environment());
                    $worker->setTimeout(30)->start();
                    $workers[] = $worker;
                }
                $deadline = microtime(true) + 15;
                while ((! file_exists($markers[0]) || ! file_exists($markers[1])) && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertFileExists($markers[0]);
                $this->assertFileExists($markers[1]);
            } finally {
                DB::rollBack();
            }
            try {
                $statuses = [];
                foreach ($workers as $worker) {
                    $worker->wait();
                    $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                    $this->assertJson($worker->getOutput(), $worker->getOutput().$worker->getErrorOutput());
                    $statuses[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status'];
                }
                sort($statuses);
                $this->assertSame($same ? [200, 401] : [200, 200], $statuses);
                $tokenCount = MobileAccessToken::where('user_id', $user->id)->count();
                if ($secondMode === 'mobile') {
                    $this->assertSame($same ? 1 : 2, $tokenCount);
                } else {
                    $this->assertLessThanOrEqual(1, $tokenCount);
                    if (! $same) {
                        $this->assertSame(1, $tokenCount);
                    }
                }
                $remaining = $user->fresh()->recoveryCodes();
                $this->assertNotContains($codes[0], $remaining);
                if (! $same) {
                    $this->assertNotContains($codes[1], $remaining);
                }
            } finally {
                foreach ($workers as $worker) {
                    if ($worker->isRunning()) {
                        $worker->stop();
                    }
                }
                foreach ($markers as $marker) {
                    if (file_exists($marker)) {
                        unlink($marker);
                    }
                }
            }
        }
    }
}
