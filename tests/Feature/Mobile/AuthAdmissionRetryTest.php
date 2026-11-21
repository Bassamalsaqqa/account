<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileAuthChallenge;
use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

final class AuthAdmissionRetryTest extends TestCase
{
    private ?DisposableMariaDbSchema $fixture = null;

    private User $user;

    private string $password = 'race-password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = DisposableMariaDbSchema::createFromSource(DB::connection()->getDatabaseName());
        $this->fixture->switchLaravelConnection();
        config(['mobile.enabled' => true]);
        $this->user = User::factory()->create(['password' => $this->password]);
        $company = Company::factory()->create(['status' => 'active']);
        $this->user->companies()->attach($company->id, ['status' => 'active']);
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

    /** @return array<string, string> */
    private function createChallenge(string $deviceId): array
    {
        $id = (string) Str::uuid();
        $nonce = bin2hex(random_bytes(32));
        MobileAuthChallenge::create(['challenge_id' => $id, 'nonce_hash' => hash('sha256', $nonce), 'device_id' => $deviceId, 'platform' => 'android', 'app_version' => '1', 'expires_at' => now()->addMinutes(5)]);

        return ['challenge_id' => $id, 'nonce' => $nonce];
    }

    public function test_concurrency_retry_rolls_back_recovery_and_token_before_revalidation(): void
    {
        $this->assertAdmissionRetry(1020, 1, 200, 2);
    }

    public function test_concurrency_retry_exhaustion_rolls_back_all_admission_effects(): void
    {
        $this->assertAdmissionRetry(1020, 3, 500, 3);
    }

    public function test_unrelated_database_error_is_not_retried(): void
    {
        $this->assertAdmissionRetry(1064, 1, 500, 1);
    }

    public function test_retry_rechecks_challenge_expiry_before_issuing_a_token(): void
    {
        $this->assertAdmissionRetry(1020, 1, 401, 1, expireOnFailure: true);
    }

    private function assertAdmissionRetry(int $driverCode, int $failures, int $status, int $expectedAttempts, bool $expireOnFailure = false): void
    {
        $code = 'ABCDEFGH-12345678';
        $this->user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode([$code])),
        ])->save();
        $deviceId = (string) Str::uuid();
        $challenge = $this->createChallenge($deviceId);
        $attempts = 0;
        $enabled = true;
        // Fail AFTER token insertion, so retries must roll back both the token
        // and the recovery replacement, then read the original code afresh.
        DB::listen(function (QueryExecuted $query) use (&$enabled, &$attempts, $failures, $driverCode, $expireOnFailure): void {
            if (! $enabled || ! str_starts_with($query->sql, 'insert into `mobile_access_tokens`')) {
                return;
            }
            $attempts++;
            if ($attempts <= $failures) {
                if ($expireOnFailure) {
                    Carbon::setTestNow(now()->addMinutes(6));
                }
                $message = $driverCode === 1020 ? 'Record has changed since last read in table users' : 'Syntax error';
                $previous = new \PDOException($message);
                $previous->errorInfo = ['HY000', $driverCode, $message];
                throw new QueryException($query->connectionName, $query->sql, $query->bindings, $previous);
            }
        });
        try {
            $response = $this->postJson('/api/mobile/v1/auth/login', $challenge + [
                'device_id' => $deviceId, 'device_name' => 'Retry test',
                'email' => $this->user->email, 'password' => $this->password,
                'recovery_code' => $code,
            ]);
            $response->assertStatus($status);
            $this->assertSame($expectedAttempts, $attempts);
            $this->assertSame($status === 200 ? 1 : 0, MobileAccessToken::where('user_id', $this->user->id)->count());
            $this->assertSame($status === 200 ? 1 : 0, MobileDevice::where('device_id', $deviceId)->count());
            $this->assertSame($status !== 200, in_array($code, $this->user->fresh()->recoveryCodes(), true));
            $this->assertTrue(MobileAuthChallenge::where('challenge_id', $challenge['challenge_id'])->firstOrFail()->isConsumed());
        } finally {
            $enabled = false;
            Carbon::setTestNow();
        }
    }
}
