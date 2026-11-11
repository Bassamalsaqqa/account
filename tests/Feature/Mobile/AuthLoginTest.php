<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileAuthChallenge;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Tenancy\CompanyRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Tests\TestCase;

final class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private string $password = 'Secret123!CorrectPassword';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mobile.enabled' => true]);
        RateLimiter::clear('mobile_v1:ip_overall:127.0.0.1');

        $this->user = User::factory()->create([
            'email' => 'cashier@example.com',
            'password' => Hash::make($this->password),
        ]);

        $this->company = Company::factory()->create([
            'name_ar' => 'شركة الأمل للتجزئة',
            'name_en' => 'Al-Amal Retail Company',
            'base_currency_code' => 'ILS',
            'status' => 'active',
        ]);

        $this->user->companies()->attach($this->company->id, [
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        $this->user->update(['last_active_company_id' => $this->company->id]);

        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($this->company);
        setPermissionsTeamId($this->company->id);
        $this->user->assignRole('Cashier');
    }

    private function createChallenge(string $deviceId): array
    {
        $challengeId = (string) Str::uuid();
        $nonce = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes(5);

        MobileAuthChallenge::create([
            'challenge_id' => $challengeId,
            'device_id' => $deviceId,
            'nonce_hash' => hash('sha256', $nonce),
            'app_version' => '1.0.0',
            'platform' => 'android',
            'expires_at' => $expiresAt,
        ]);

        return ['challenge_id' => $challengeId, 'nonce' => $nonce];
    }

    public function test_login_successful_without_mfa(): void
    {
        $deviceId = (string) Str::uuid();
        $challenge = $this->createChallenge($deviceId);

        $now = Carbon::parse('2026-11-10T12:00:00Z');
        Carbon::setTestNow($now);

        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'challenge_id' => $challenge['challenge_id'],
            'nonce' => $challenge['nonce'],
            'device_id' => $deviceId,
            'device_name' => 'Samsung Galaxy Tab A8',
            'email' => 'cashier@example.com',
            'password' => $this->password,
            'mfa_code' => null,
            'recovery_code' => null,
        ]);

        $response->assertStatus(200)
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        $data = $response->json();
        $this->assertArrayHasKey('token', $data);
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertSame('2026-11-17T12:00:00Z', $data['expires_at']);

        // Actor
        $this->assertSame($this->user->public_id, $data['actor']['id']);
        $this->assertSame($this->user->name, $data['actor']['name']);
        $this->assertSame('cashier@example.com', $data['actor']['email']);
        $this->assertSame('cashier', $data['actor']['role']);
        $this->assertStringStartsWith('epoch-', $data['actor']['password_epoch']);

        // Companies
        $this->assertCount(1, $data['companies']);
        $this->assertSame($this->company->public_id, $data['companies'][0]['id']);
        $this->assertSame('ILS', $data['companies'][0]['base_currency']);
        $this->assertTrue($data['companies'][0]['is_default']);

        // Verify token saved hashed in DB (never plaintext)
        $tokenRecord = MobileAccessToken::where('device_id', $deviceId)->first();
        $this->assertNotNull($tokenRecord);
        $this->assertSame(hash('sha256', $data['token']), $tokenRecord->token_hash);
        $this->assertNotSame($data['token'], $tokenRecord->token_hash);
        $this->assertSame($this->company->id, $tokenRecord->company_id);

        // Verify device record created
        $deviceRecord = MobileDevice::where('device_id', $deviceId)->first();
        $this->assertNotNull($deviceRecord);
        $this->assertSame('Samsung Galaxy Tab A8', $deviceRecord->device_name);
        $this->assertFalse($deviceRecord->isRevoked());
    }

    public function test_login_challenge_is_atomically_single_use(): void
    {
        $deviceId = (string) Str::uuid();
        $challenge = $this->createChallenge($deviceId);

        // First login succeeds
        $first = $this->postJson('/api/mobile/v1/auth/login', [
            'challenge_id' => $challenge['challenge_id'],
            'nonce' => $challenge['nonce'],
            'device_id' => $deviceId,
            'device_name' => 'Test Device',
            'email' => 'cashier@example.com',
            'password' => $this->password,
            'mfa_code' => null,
            'recovery_code' => null,
        ]);
        $first->assertStatus(200);

        // Second login with identical challenge is rejected (single-use)
        $second = $this->postJson('/api/mobile/v1/auth/login', [
            'challenge_id' => $challenge['challenge_id'],
            'nonce' => $challenge['nonce'],
            'device_id' => $deviceId,
            'device_name' => 'Test Device',
            'email' => 'cashier@example.com',
            'password' => $this->password,
            'mfa_code' => null,
            'recovery_code' => null,
        ]);
        $second->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_login_rejected_when_challenge_expired_or_nonce_mismatched(): void
    {
        $deviceId = (string) Str::uuid();
        $challenge = $this->createChallenge($deviceId);

        // Mismatched nonce
        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'challenge_id' => $challenge['challenge_id'],
            'nonce' => str_repeat('a', 64),
            'device_id' => $deviceId,
            'device_name' => 'Test Device',
            'email' => 'cashier@example.com',
            'password' => $this->password,
        ]);
        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        // Expired challenge
        $newChallenge = $this->createChallenge($deviceId);
        Carbon::setTestNow(now()->addMinutes(10));
        $response2 = $this->postJson('/api/mobile/v1/auth/login', [
            'challenge_id' => $newChallenge['challenge_id'],
            'nonce' => $newChallenge['nonce'],
            'device_id' => $deviceId,
            'device_name' => 'Test Device',
            'email' => 'cashier@example.com',
            'password' => $this->password,
        ]);
        $response2->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_login_rejected_when_device_is_revoked(): void
    {
        $deviceId = (string) Str::uuid();
        MobileDevice::create([
            'device_id' => $deviceId,
            'device_name' => 'Compromised Tablet',
            'revoked_at' => now()->subHour(),
            'revoked_reason' => 'Stolen device',
        ]);

        $challenge = $this->createChallenge($deviceId);

        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'challenge_id' => $challenge['challenge_id'],
            'nonce' => $challenge['nonce'],
            'device_id' => $deviceId,
            'device_name' => 'Compromised Tablet',
            'email' => 'cashier@example.com',
            'password' => $this->password,
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'DEVICE_REVOKED');
    }

    public function test_login_safe_generic_error_for_invalid_password_or_email(): void
    {
        $deviceId = (string) Str::uuid();

        // Wrong password
        $ch1 = $this->createChallenge($deviceId);
        $resp1 = $this->postJson('/api/mobile/v1/auth/login', [
            'challenge_id' => $ch1['challenge_id'],
            'nonce' => $ch1['nonce'],
            'device_id' => $deviceId,
            'device_name' => 'Tablet',
            'email' => 'cashier@example.com',
            'password' => 'WrongPassword!',
        ]);
        $resp1->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        // Non-existent email
        $ch2 = $this->createChallenge($deviceId);
        $resp2 = $this->postJson('/api/mobile/v1/auth/login', [
            'challenge_id' => $ch2['challenge_id'],
            'nonce' => $ch2['nonce'],
            'device_id' => $deviceId,
            'device_name' => 'Tablet',
            'email' => 'unknown@example.com',
            'password' => 'SomePassword123!',
        ]);
        $resp2->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_login_mfa_flow_with_totp_and_single_use_recovery_codes(): void
    {
        /** @var TwoFactorAuthenticationProvider $tfa */
        $tfa = app(TwoFactorAuthenticationProvider::class);
        $secret = $tfa->generateSecretKey();

        $recoveryCodes = ['ABCD1234-EFGH5678', 'IJKL9012-MNOP3456'];
        $this->user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
        ])->save();

        $this->assertTrue($this->user->hasEnabledTwoFactorAuthentication());

        $deviceId = (string) Str::uuid();

        // 1. Initial login without MFA returns MFA_REQUIRED
        $ch1 = $this->createChallenge($deviceId);
        $resp1 = $this->postJson('/api/mobile/v1/auth/login', [
            'challenge_id' => $ch1['challenge_id'],
            'nonce' => $ch1['nonce'],
            'device_id' => $deviceId,
            'device_name' => 'Tablet',
            'email' => 'cashier@example.com',
            'password' => $this->password,
            'mfa_code' => null,
            'recovery_code' => null,
        ]);

        $resp1->assertStatus(200)
            ->assertJsonPath('status', 'MFA_REQUIRED');
        $mfaChallengeId = $resp1->json('mfa_challenge_id');
        $this->assertNotNull($mfaChallengeId);

        // 2. Mutual exclusion: submitting both mfa_code and recovery_code rejected with 422
        $chMutual = $this->createChallenge($deviceId);
        $respMutual = $this->postJson('/api/mobile/v1/auth/login', [
            'challenge_id' => $chMutual['challenge_id'],
            'nonce' => $chMutual['nonce'],
            'device_id' => $deviceId,
            'device_name' => 'Tablet',
            'email' => 'cashier@example.com',
            'password' => $this->password,
            'mfa_code' => '123456',
            'recovery_code' => 'ABCD1234-EFGH5678',
        ]);
        $respMutual->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 3. Login with single-use recovery code succeeds
        $chRec = $this->createChallenge($deviceId);
        $respRec = $this->postJson('/api/mobile/v1/auth/login', [
            'challenge_id' => $chRec['challenge_id'],
            'nonce' => $chRec['nonce'],
            'device_id' => $deviceId,
            'device_name' => 'Tablet',
            'email' => 'cashier@example.com',
            'password' => $this->password,
            'mfa_code' => null,
            'recovery_code' => 'ABCD1234-EFGH5678',
        ]);
        $respRec->assertStatus(200)
            ->assertJsonPath('token_type', 'Bearer');

        // 4. Re-using the same recovery code fails (atomic single-use)
        $chRec2 = $this->createChallenge($deviceId);
        $respRec2 = $this->postJson('/api/mobile/v1/auth/login', [
            'challenge_id' => $chRec2['challenge_id'],
            'nonce' => $chRec2['nonce'],
            'device_id' => $deviceId,
            'device_name' => 'Tablet',
            'email' => 'cashier@example.com',
            'password' => $this->password,
            'mfa_code' => null,
            'recovery_code' => 'ABCD1234-EFGH5678',
        ]);
        $respRec2->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }
}
